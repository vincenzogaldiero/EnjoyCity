<?php
// =========================================================
// FILE: dashboard.php  (UTENTE LOGGATO)
// Scopo didattico:
// - Mostrare i prossimi eventi prenotati (futuri) + countdown
// - Mostrare lo storico eventi (passati) nella stessa dashboard
// - Gestire azioni POST con pattern PRG (Post/Redirect/Get)
// - Sicurezza: accesso solo user; query parametrizzate; ownership su prenotazioni
// - Coerenza DB: eventi visibili solo se approvati + attivi + non archiviati
// =========================================================

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// =========================================================
// GUARD: solo utenti loggati "user"
// =========================================================
if (!isset($_SESSION['logged']) || $_SESSION['logged'] !== true) {
    header("Location: " . base_url('login.php'));
    exit;
}
if (($_SESSION['ruolo'] ?? '') === 'admin') {
    // NB: se il tuo path admin è /admin/admin_dashboard.php usa quello
    header("Location: " . base_url('admin/admin_dashboard.php'));
    exit;
}

$user_id    = (int)($_SESSION['user_id'] ?? 0);
$nomeUtente = (string)($_SESSION['nome_utente'] ?? 'Utente');

// =========================================================
// Flash messages (PRG)
// =========================================================
$flash_ok  = $_SESSION['flash_ok']  ?? '';
$flash_err = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

// =========================================================
// DB connect
// =========================================================
$conn = db_connect();

// =========================================================
// POST ACTIONS (PRG)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    // -----------------------------------------------------
    // 1) Annulla prenotazione (solo se evento FUTURO + attivo)
    // -----------------------------------------------------
    if ($action === 'annulla_prenotazione') {

        $id = (string)($_POST['id_prenotazione'] ?? '');
        if (!ctype_digit($id)) {
            $_SESSION['flash_error'] = "Prenotazione non valida.";
            db_close($conn);
            header("Location: " . base_url("dashboard.php"));
            exit;
        }

        $pren_id = (int)$id;

        // Transazione: (1) check + (2) delete + (3) update contatore
        pg_query($conn, "BEGIN");

        // Recupero evento_id verificando:
        // - ownership (utente_id)
        // - evento futuro (data_evento >= NOW())
        // - evento attivo e non archiviato
        $sqlGet = "
            SELECT p.evento_id
            FROM prenotazioni p
            JOIN eventi e ON e.id = p.evento_id
            WHERE p.id = $1
              AND p.utente_id = $2
              AND e.stato = 'approvato'
              AND e.archiviato = FALSE
              AND e.stato_evento = 'attivo'
              AND e.data_evento >= NOW()
            LIMIT 1;
        ";
        $resGet = pg_query_params($conn, $sqlGet, [$pren_id, $user_id]);
        $rowGet = $resGet ? pg_fetch_assoc($resGet) : null;

        if (!$rowGet) {
            pg_query($conn, "ROLLBACK");
            $_SESSION['flash_error'] = "Non puoi annullare: prenotazione non trovata o evento già concluso/non attivo.";
            db_close($conn);
            header("Location: " . base_url("dashboard.php"));
            exit;
        }

        $evento_id = (int)$rowGet['evento_id'];

        // Delete prenotazione
        $sqlDel = "DELETE FROM prenotazioni WHERE id = $1 AND utente_id = $2;";
        $resDel = pg_query_params($conn, $sqlDel, [$pren_id, $user_id]);

        if (!$resDel || pg_affected_rows($resDel) <= 0) {
            pg_query($conn, "ROLLBACK");
            $_SESSION['flash_error'] = "Non è stato possibile annullare la prenotazione.";
            db_close($conn);
            header("Location: " . base_url("dashboard.php"));
            exit;
        }

        // Ricalcolo posti_prenotati (consistente anche se più prenotazioni cambiano)
        $sqlUpd = "
            UPDATE eventi
            SET posti_prenotati = (
                SELECT COALESCE(SUM(quantita), 0)
                FROM prenotazioni
                WHERE evento_id = $1
            )
            WHERE id = $1;
        ";
        $resUpd = pg_query_params($conn, $sqlUpd, [$evento_id]);

        if (!$resUpd) {
            pg_query($conn, "ROLLBACK");
            $_SESSION['flash_error'] = "Prenotazione annullata, ma errore aggiornamento posti.";
            db_close($conn);
            header("Location: " . base_url("dashboard.php"));
            exit;
        }

        pg_query($conn, "COMMIT");

        $_SESSION['flash_ok'] = "Prenotazione annullata con successo.";
        db_close($conn);
        header("Location: " . base_url("dashboard.php"));
        exit;
    }

    // -----------------------------------------------------
    // 2) Invia recensione (moderazione admin)
    // -----------------------------------------------------
    if ($action === 'invia_recensione') {
        $voto  = (string)($_POST['voto'] ?? '');
        $testo = trim((string)($_POST['testo'] ?? ''));

        $err = '';

        if (!ctype_digit($voto)) {
            $err = "Seleziona un voto valido.";
        } else {
            $v = (int)$voto;
            if ($v < 1 || $v > 5) $err = "Il voto deve essere tra 1 e 5.";
        }

        if ($err === '' && mb_strlen($testo) < 10) $err = "Scrivi almeno 10 caratteri.";
        if ($err === '' && mb_strlen($testo) > 250) $err = "Massimo 250 caratteri.";

        if ($err !== '') {
            $_SESSION['flash_error'] = $err;
            db_close($conn);
            header("Location: " . base_url("dashboard.php#recensioni"));
            exit;
        }

        $sqlIns = "
            INSERT INTO recensioni (utente_id, testo, voto, stato, data_recensione)
            VALUES ($1, $2, $3, 'in_attesa', NOW());
        ";
        $resIns = pg_query_params($conn, $sqlIns, [$user_id, $testo, (int)$voto]);

        if (!$resIns) {
            $_SESSION['flash_error'] = "Errore durante l'invio. Riprova.";
            db_close($conn);
            header("Location: " . base_url("dashboard.php#recensioni"));
            exit;
        }

        $_SESSION['flash_ok'] = "Recensione inviata! Sarà visibile dopo l’approvazione dell’admin.";
        db_close($conn);
        header("Location: " . base_url("dashboard.php#recensioni"));
        exit;
    }
}

// =========================================================
// QUERY DATI PAGINA
// =========================================================

// A) Prossimi eventi prenotati (FUTURI)
$prenotati = [];
$sql_pren_futuri = "
    SELECT
        p.id            AS prenotazione_id,
        p.quantita      AS quantita,
        e.id            AS evento_id,
        e.titolo        AS titolo,
        e.luogo         AS luogo,
        e.data_evento   AS data_evento,
        c.nome          AS categoria
    FROM prenotazioni p
    JOIN eventi e ON e.id = p.evento_id
    LEFT JOIN categorie c ON c.id = e.categoria_id
    WHERE p.utente_id = $1
      AND e.stato = 'approvato'
      AND e.archiviato = FALSE
      AND e.stato_evento = 'attivo'
      AND e.data_evento >= NOW()
    ORDER BY e.data_evento ASC
    LIMIT 12;
";
$res = pg_query_params($conn, $sql_pren_futuri, [$user_id]);
if ($res) while ($row = pg_fetch_assoc($res)) $prenotati[] = $row;

// B) Storico eventi prenotati (PASSATI)
$storico = [];
$sql_pren_passati = "
    SELECT
        p.id            AS prenotazione_id,
        p.quantita      AS quantita,
        e.id            AS evento_id,
        e.titolo        AS titolo,
        e.luogo         AS luogo,
        e.data_evento   AS data_evento,
        c.nome          AS categoria
    FROM prenotazioni p
    JOIN eventi e ON e.id = p.evento_id
    LEFT JOIN categorie c ON c.id = e.categoria_id
    WHERE p.utente_id = $1
      AND e.stato = 'approvato'
      AND e.data_evento < NOW()
    ORDER BY e.data_evento DESC
    LIMIT 12;
";
$res = pg_query_params($conn, $sql_pren_passati, [$user_id]);
if ($res) while ($row = pg_fetch_assoc($res)) $storico[] = $row;

// C) Categorie (per select)
$categorie = [];
$res = pg_query($conn, "SELECT id, nome FROM categorie ORDER BY nome;");
if ($res) while ($row = pg_fetch_assoc($res)) $categorie[] = $row;

// D) Preferenze
$prefs = [];
$sql_pref = "
    SELECT categoria_id
    FROM preferenze_utente
    WHERE utente_id = $1
    ORDER BY ordine ASC;
";
$res = pg_query_params($conn, $sql_pref, [$user_id]);
if ($res) while ($row = pg_fetch_assoc($res)) $prefs[] = (int)$row['categoria_id'];

// E) Scelti per te: preferenze, futuri, attivi, non archiviati, non già prenotati
$consigliati = [];
if (count($prefs) > 0) {

    $placeholders = [];
    $params = [];
    $i = 1;

    foreach ($prefs as $catId) {
        $placeholders[] = '$' . $i;
        $params[] = $catId;
        $i++;
    }
    $in = implode(',', $placeholders);

    // parametro user_id per NOT EXISTS
    $params[] = $user_id;
    $uidPh = '$' . $i;

    $sql_rec = "
        SELECT
            e.id           AS evento_id,
            e.titolo       AS titolo,
            e.luogo        AS luogo,
            e.data_evento  AS data_evento,
            e.prezzo       AS prezzo,
            e.categoria_id AS categoria_id,
            c.nome         AS categoria
        FROM eventi e
        LEFT JOIN categorie c ON c.id = e.categoria_id
        WHERE e.stato = 'approvato'
          AND e.archiviato = FALSE
          AND e.stato_evento = 'attivo'
          AND e.data_evento >= NOW()
          AND e.categoria_id IN ($in)
          AND NOT EXISTS (
              SELECT 1 FROM prenotazioni p
              WHERE p.evento_id = e.id AND p.utente_id = $uidPh
          )
        ORDER BY e.data_evento ASC
        LIMIT 8;
    ";

    $res = pg_query_params($conn, $sql_rec, $params);
    if ($res) while ($row = pg_fetch_assoc($res)) $consigliati[] = $row;
}

// F) Recensioni approvate (preview)
$recensioni = [];
$sql_rev = "
    SELECT
        u.nome AS nome,
        r.voto,
        r.testo,
        r.data_recensione
    FROM recensioni r
    LEFT JOIN utenti u ON u.id = r.utente_id
    WHERE r.stato = 'approvato'
    ORDER BY r.data_recensione DESC
    LIMIT 6;
";
$res = pg_query($conn, $sql_rev);
if ($res) while ($row = pg_fetch_assoc($res)) $recensioni[] = $row;

db_close($conn);

$page_title = "Dashboard - EnjoyCity";
require_once __DIR__ . '/includes/header.php';
?>

<main class="container dashboard" id="content">

    <?php if ($flash_ok !== ''): ?>
        <div class="alert alert-success" role="status"><?= e($flash_ok) ?></div>
    <?php endif; ?>

    <?php if ($flash_err !== ''): ?>
        <div class="alert alert-error" role="alert"><?= e($flash_err) ?></div>
    <?php endif; ?>

    <!-- =====================================================
         A) PROSSIMI EVENTI PRENOTATI
         - solo futuri
         - countdown
         - annullamento consentito (evento futuro, attivo)
         ===================================================== -->
    <section class="panel" aria-labelledby="h-prenotati">
        <header class="panel-head">
            <div>
                <h1 id="h-prenotati">Ciao <?= e($nomeUtente) ?> 👋</h1>
                <p class="muted">I tuoi prossimi eventi prenotati (con countdown).</p>
            </div>
            <a class="btn" href="<?= base_url('eventi.php') ?>">Esplora eventi</a>
        </header>

        <?php if (count($prenotati) === 0): ?>
            <p class="muted">Non hai prenotazioni attive. Vai su “Eventi” e prenotati!</p>
        <?php else: ?>
            <div class="grid cards" role="list">
                <?php foreach ($prenotati as $ev): ?>
                    <?php
                    $prenId = (int)$ev['prenotazione_id'];
                    $evId   = (int)$ev['evento_id'];
                    $dtISO  = date('c', strtotime((string)$ev['data_evento']));
                    ?>
                    <article class="card" role="listitem">
                        <div class="card-top">
                            <span class="pill"><?= e($ev['categoria'] ?? 'Categoria') ?></span>
                            <span class="pill"><?= e(fmt_datetime($ev['data_evento'])) ?></span>
                        </div>

                        <h2 class="card-title"><?= e($ev['titolo']) ?></h2>
                        <p class="card-meta muted"><?= e($ev['luogo']) ?> • Biglietti: <?= (int)$ev['quantita'] ?></p>

                        <p class="countdown" data-countdown="<?= e($dtISO) ?>">Caricamento countdown…</p>

                        <div class="card-actions">
                            <a class="btn small" href="<?= base_url('evento.php?id=' . $evId) ?>">Dettagli</a>

                            <!-- PRG: annulla prenotazione -->
                            <form action="<?= base_url('dashboard.php') ?>" method="post" class="inline"
                                data-confirm="Vuoi davvero annullare questa prenotazione?">
                                <input type="hidden" name="action" value="annulla_prenotazione">
                                <input type="hidden" name="id_prenotazione" value="<?= $prenId ?>">
                                <button type="submit" class="btn danger small">Annulla</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- =====================================================
         A2) STORICO EVENTI (PASSATI)
         - non si annullano
         - utile per tracciabilità / UX (scelta professionale)
         ===================================================== -->
    <section class="panel" aria-labelledby="h-storico">
        <header class="panel-head">
            <div>
                <h2 id="h-storico">Storico eventi</h2>
                <p class="muted">Eventi già conclusi a cui hai partecipato (restano nel sistema, non si cancellano).</p>
            </div>
        </header>

        <?php if (count($storico) === 0): ?>
            <p class="muted">Ancora nessun evento concluso nel tuo storico.</p>
        <?php else: ?>
            <div class="grid cards" role="list">
                <?php foreach ($storico as $ev): ?>
                    <?php
                    $evId = (int)$ev['evento_id'];
                    ?>
                    <article class="card" role="listitem">
                        <div class="card-top">
                            <span class="pill"><?= e($ev['categoria'] ?? 'Categoria') ?></span>
                            <span class="pill"><?= e(fmt_datetime($ev['data_evento'])) ?></span>
                            <span class="pill hot">CONCLUSO</span>
                        </div>

                        <h3 class="card-title"><?= e($ev['titolo']) ?></h3>
                        <p class="card-meta muted"><?= e($ev['luogo']) ?> • Biglietti: <?= (int)$ev['quantita'] ?></p>

                        <div class="card-actions">
                            <a class="btn small" href="<?= base_url('evento.php?id=' . $evId) ?>">Rivedi dettagli</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- =====================================================
         B) RICERCA EVENTI (rimanda a eventi.php)
         ===================================================== -->
    <section class="search-card" aria-label="Cerca eventi">
        <form method="get" action="<?= base_url('eventi.php') ?>" class="search-grid" id="searchForm">
            <input class="input wide" type="text" name="q"
                placeholder="Cerca per titolo, luogo, descrizione…"
                aria-label="Cerca eventi">

            <select name="categoria" aria-label="Categoria">
                <option value="">Tutte le categorie</option>
                <?php foreach ($categorie as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= e($c['nome']) ?></option>
                <?php endforeach; ?>
            </select>

            <button class="btn-search" type="submit">Cerca</button>
            <a class="btn-search btn-reset" href="<?= base_url('eventi.php') ?>">Reset</a>
        </form>
    </section>

    <!-- =====================================================
         C) SCELTI PER TE (preferenze + esclusione prenotati)
         ===================================================== -->
    <section class="panel" aria-labelledby="h-scelti">
        <header class="panel-head">
            <div>
                <h2 id="h-scelti">Scelti per te</h2>
                <p class="muted">
                    <?php if (count($prefs) > 0): ?>
                        Eventi futuri nelle tue categorie di interesse (non già prenotati).
                    <?php else: ?>
                        Imposta le categorie in Area personale per vedere consigli su misura.
                    <?php endif; ?>
                </p>
            </div>
            <a class="btn" href="<?= base_url('area_personale.php') ?>">Area personale</a>
        </header>

        <?php if (count($prefs) === 0): ?>
            <div class="empty">
                Non hai ancora impostato preferenze. Vai in <strong>Area personale</strong> e scegli le categorie.
            </div>
        <?php elseif (count($consigliati) === 0): ?>
            <p class="muted">Nessun evento disponibile nelle tue categorie (o li hai già prenotati).</p>
        <?php else: ?>
            <div class="grid cards" role="list">
                <?php foreach ($consigliati as $ev): ?>
                    <?php $evId = (int)$ev['evento_id']; ?>
                    <article class="card" role="listitem">
                        <div class="card-top">
                            <span class="pill"><?= e($ev['categoria'] ?? 'Categoria') ?></span>
                            <span class="pill"><?= e(fmt_datetime($ev['data_evento'])) ?></span>
                        </div>

                        <h3 class="card-title"><?= e($ev['titolo']) ?></h3>
                        <p class="card-meta muted">
                            <?= e($ev['luogo']) ?> •
                            <?= ((float)$ev['prezzo'] <= 0) ? 'Gratis' : '€' . e(number_format((float)$ev['prezzo'], 2, ',', '.')) ?>
                        </p>

                        <div class="card-actions">
                            <a class="btn small" href="<?= base_url('evento.php?id=' . $evId) ?>">Dettagli</a>
                            <a class="btn small" href="<?= base_url('eventi.php?categoria=' . (int)$ev['categoria_id']) ?>">Simili</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- =====================================================
         D) RECENSIONI (form + preview)
         - Inserimento in stato "in_attesa" → moderazione admin
         ===================================================== -->
    <section class="panel" id="recensioni" aria-labelledby="h-recensioni">
        <header class="panel-head">
            <div>
                <h2 id="h-recensioni">Recensioni sul sito</h2>
                <p class="muted">La tua recensione sarà pubblicata dopo l’approvazione dell’admin.</p>
            </div>
        </header>

        <form id="reviewForm" class="review-form" action="<?= base_url('dashboard.php#recensioni') ?>"
            method="post" novalidate>
            <input type="hidden" name="action" value="invia_recensione">

            <div class="review-grid">
                <div class="field">
                    <label for="voto">Voto</label>
                    <select id="voto" name="voto" required>
                        <option value="">Seleziona</option>
                        <option value="5">★★★★★ (5)</option>
                        <option value="4">★★★★☆ (4)</option>
                        <option value="3">★★★☆☆ (3)</option>
                        <option value="2">★★☆☆☆ (2)</option>
                        <option value="1">★☆☆☆☆ (1)</option>
                    </select>
                </div>

                <div class="field">
                    <label for="testo">Testo (breve)</label>
                    <textarea id="testo" name="testo" rows="3" maxlength="250"
                        placeholder="Scrivi la tua esperienza (min 10 caratteri)" required></textarea>
                    <small class="muted">Max 250 caratteri.</small>
                </div>
            </div>

            <button class="btn primary btn-wide" type="submit">Invia recensione</button>
        </form>

        <?php if (count($recensioni) === 0): ?>
            <p class="muted review-space">Nessuna recensione approvata ancora.</p>
        <?php else: ?>
            <div class="grid reviews review-space" role="list">
                <?php foreach ($recensioni as $r): ?>
                    <?php
                    $voto = (int)($r['voto'] ?? 0);
                    $voto = max(0, min(5, $voto));
                    $stars = str_repeat('★', $voto) . str_repeat('☆', 5 - $voto);
                    ?>
                    <article class="review" role="listitem">
                        <header class="review-head">
                            <strong><?= e($r['nome'] ?? 'Utente') ?></strong>
                            <span class="stars" aria-label="Voto <?= $voto ?> su 5"><?= e($stars) ?></span>
                        </header>
                        <p class="review-text"><?= e($r['testo']) ?></p>
                        <p class="muted review-date"><?= e(date('d/m/Y', strtotime((string)$r['data_recensione']))) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

</main>

<link rel="stylesheet" href="<?= base_url('assets/css/dashboard.css') ?>">
<script src="<?= base_url('assets/js/dashboard.js') ?>"></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>