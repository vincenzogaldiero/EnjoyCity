<?php
// dashboard.php (UTENTE LOGGATO - pg_* - tutto in uno)

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// accesso
if (!isset($_SESSION['logged']) || $_SESSION['logged'] !== true) {
    header("Location: " . base_url('login.php'));
    exit;
}
if (isset($_SESSION['ruolo']) && $_SESSION['ruolo'] === 'admin') {
    header("Location: " . base_url('admin_dashboard.php'));
    exit;
}

$user_id    = (int)($_SESSION['user_id'] ?? 0);
$nomeUtente = (string)($_SESSION['nome_utente'] ?? 'Utente');

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$conn = db_connect();

// flash via GET dopo redirect
$flash_ok  = isset($_GET['msg']) ? (string)$_GET['msg'] : '';
$flash_err = isset($_GET['err']) ? (string)$_GET['err'] : '';

/* =========================================
   POST ACTIONS (tutto nella dashboard)
   ========================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = (string)($_POST['action'] ?? '');

    // 1) Annulla prenotazione
    if ($action === 'annulla_prenotazione') {

        $id = (string)($_POST['id_prenotazione'] ?? '');
        $err = '';

        if (!ctype_digit($id)) {
            $err = "Prenotazione non valida.";
            db_close($conn);
            header("Location: " . base_url("dashboard.php?msg=&err=" . urlencode($err)));
            exit;
        }

        $pren_id = (int)$id;

        // TRANSAZIONE: delete + update contatore
        pg_query($conn, "BEGIN");

        // Recupero evento_id (e controllo ownership)
        $sqlGet = "SELECT evento_id
                   FROM prenotazioni
                   WHERE id = $1 AND utente_id = $2
                   LIMIT 1;";
        $resGet = pg_query_params($conn, $sqlGet, [$pren_id, $user_id]);
        $rowGet = $resGet ? pg_fetch_assoc($resGet) : null;

        if (!$rowGet) {
            pg_query($conn, "ROLLBACK");
            $err = "Non è stato possibile annullare la prenotazione.";
            db_close($conn);
            header("Location: " . base_url("dashboard.php?msg=&err=" . urlencode($err)));
            exit;
        }

        $evento_id = (int)$rowGet['evento_id'];

        // Delete prenotazione
        $sqlDel = "DELETE FROM prenotazioni WHERE id = $1 AND utente_id = $2;";
        $resDel = pg_query_params($conn, $sqlDel, [$pren_id, $user_id]);

        if (!$resDel || pg_affected_rows($resDel) <= 0) {
            pg_query($conn, "ROLLBACK");
            $err = "Non è stato possibile annullare la prenotazione.";
            db_close($conn);
            header("Location: " . base_url("dashboard.php?msg=&err=" . urlencode($err)));
            exit;
        }

        // Ricalcolo e aggiorno eventi.posti_prenotati
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
            $err = "Prenotazione annullata, ma errore aggiornamento posti.";
            db_close($conn);
            header("Location: " . base_url("dashboard.php?msg=&err=" . urlencode($err)));
            exit;
        }

        pg_query($conn, "COMMIT");

        $ok = "Prenotazione annullata con successo.";
        db_close($conn);
        header("Location: " . base_url("dashboard.php?msg=" . urlencode($ok) . "&err="));
        exit;
    }

    // 2) Invia recensione
    if ($action === 'invia_recensione') {
        $voto = (string)($_POST['voto'] ?? '');
        $testo = trim((string)($_POST['testo'] ?? ''));

        $err = '';
        $ok  = '';

        if (!ctype_digit($voto)) {
            $err = "Seleziona un voto valido.";
        } else {
            $v = (int)$voto;
            if ($v < 1 || $v > 5) $err = "Il voto deve essere tra 1 e 5.";
        }

        if ($err === '' && mb_strlen($testo) < 10) {
            $err = "Scrivi almeno 10 caratteri.";
        }
        if ($err === '' && mb_strlen($testo) > 250) {
            $err = "Massimo 250 caratteri.";
        }

        if ($err !== '') {
            db_close($conn);
            header("Location: " . base_url("dashboard.php?msg=&err=" . urlencode($err) . "#recensioni"));
            exit;
        }

        $sqlIns = "
            INSERT INTO recensioni (utente_id, testo, voto, stato, data_recensione)
            VALUES ($1, $2, $3, 'in_attesa', NOW());
        ";
        $resIns = pg_query_params($conn, $sqlIns, [$user_id, $testo, (int)$voto]);

        if (!$resIns) {
            $err = "Errore durante l'invio. Riprova.";
            db_close($conn);
            header("Location: " . base_url("dashboard.php?msg=&err=" . urlencode($err) . "#recensioni"));
            exit;
        }

        $ok = "Recensione inviata! Sarà visibile dopo l’approvazione dell’admin.";
        db_close($conn);
        header("Location: " . base_url("dashboard.php?msg=" . urlencode($ok) . "&err=#recensioni"));
        exit;
    }
}

/* =========================================
   QUERY DATI PAGINA
   ========================================= */

// A) prossimi eventi prenotati (approvati)
$prenotati = [];
$sql_pren = "
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
      AND e.data_evento >= NOW()
    ORDER BY e.data_evento ASC
    LIMIT 12;
";
$res = pg_query_params($conn, $sql_pren, [$user_id]);
if ($res) {
    while ($row = pg_fetch_assoc($res)) $prenotati[] = $row;
}

// B) categorie
$categorie = [];
$res = pg_query($conn, "SELECT id, nome FROM categorie ORDER BY nome;");
if ($res) {
    while ($row = pg_fetch_assoc($res)) $categorie[] = $row;
}

// C) preferenze (solo id)
$prefs = [];
$sql_pref = "
    SELECT categoria_id
    FROM preferenze_utente
    WHERE utente_id = $1
    ORDER BY ordine ASC;
";
$res = pg_query_params($conn, $sql_pref, [$user_id]);
if ($res) {
    while ($row = pg_fetch_assoc($res)) $prefs[] = (int)$row['categoria_id'];
}

// D) scelti per te: SOLO preferenze (e non già prenotati)
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
    if ($res) {
        while ($row = pg_fetch_assoc($res)) $consigliati[] = $row;
    }
}

// E) recensioni approvate (preview)
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
if ($res) {
    while ($row = pg_fetch_assoc($res)) $recensioni[] = $row;
}

db_close($conn);

$page_title = "Dashboard - EnjoyCity";
?>

<?php require_once __DIR__ . '/includes/header.php'; ?>

<main class="container dashboard" id="content">

    <?php if ($flash_ok !== ''): ?>
        <div class="alert alert-success" role="status"><?= h($flash_ok) ?></div>
    <?php endif; ?>

    <?php if ($flash_err !== ''): ?>
        <div class="alert alert-error" role="alert"><?= h($flash_err) ?></div>
    <?php endif; ?>

    <!-- A) Prenotati + countdown -->
    <section class="panel" aria-labelledby="h-prenotati">
        <header class="panel-head">
            <div>
                <h1 id="h-prenotati">Ciao <?= h($nomeUtente) ?> 👋</h1>
                <p class="muted">I tuoi prossimi eventi prenotati (con countdown).</p>
            </div>
            <a class="btn" href="<?= base_url('eventi.php') ?>">Esplora eventi</a>
        </header>

        <?php if (count($prenotati) === 0): ?>
            <p class="muted">Non hai prenotazioni attive. Vai su “Eventi” e prenotati!</p>
        <?php else: ?>
            <div class="grid cards" role="list">
                <?php foreach ($prenotati as $e): ?>
                    <?php
                    $prenId = (int)$e['prenotazione_id'];
                    $evId   = (int)$e['evento_id'];
                    $dtISO  = date('c', strtotime((string)$e['data_evento']));
                    ?>
                    <article class="card" role="listitem">
                        <div class="card-top">
                            <span class="pill"><?= h($e['categoria'] ?? 'Categoria') ?></span>
                            <span class="pill"><?= h(date('d/m/Y H:i', strtotime((string)$e['data_evento']))) ?></span>
                        </div>

                        <h2 class="card-title"><?= h($e['titolo']) ?></h2>
                        <p class="card-meta muted"><?= h($e['luogo']) ?> • Biglietti: <?= (int)$e['quantita'] ?></p>

                        <p class="countdown" data-countdown="<?= h($dtISO) ?>">Caricamento countdown…</p>

                        <div class="card-actions">
                            <a class="btn small" href="<?= base_url('evento.php?id=' . $evId) ?>">Dettagli</a>

                            <form action="<?= base_url('dashboard.php') ?>" method="post" class="inline" data-confirm="Vuoi davvero annullare questa prenotazione?">
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

    <!-- B) Ricerca (UGUALE a index/eventi) -->
    <section class="search-card" aria-label="Cerca eventi">
        <form method="get" action="<?= base_url('eventi.php') ?>" class="search-grid" id="searchForm">
            <input
                class="input wide"
                type="text"
                name="q"
                placeholder="Cerca per titolo, luogo, descrizione…"
                aria-label="Cerca eventi">

            <select name="categoria" aria-label="Categoria">
                <option value="">Tutte le categorie</option>
                <?php foreach ($categorie as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= h($c['nome']) ?></option>
                <?php endforeach; ?>
            </select>

            <button class="btn-search" type="submit">Cerca</button>
            <a class="btn-search btn-reset" href="<?= base_url('eventi.php') ?>">Reset</a>
        </form>
    </section>

    <!-- C) Scelti per te -->
    <section class="panel" aria-labelledby="h-scelti">
        <header class="panel-head">
            <div>
                <h2 id="h-scelti">Scelti per te</h2>
                <p class="muted">
                    <?php if (count($prefs) > 0): ?>
                        Solo eventi nelle tue categorie di interesse.
                    <?php else: ?>
                        Imposta le categorie in Area personale per vedere consigli su misura.
                    <?php endif; ?>
                </p>
            </div>
            <a class="btn" href="<?= base_url('area_personale.php') ?>">Area personale</a>
        </header>

        <?php if (count($prefs) === 0): ?>
            <div class="empty">
                Non hai ancora impostato preferenze. Vai in <strong>Area personale</strong> e scegli le categorie che ti interessano.
            </div>
        <?php elseif (count($consigliati) === 0): ?>
            <p class="muted">Nessun evento disponibile nelle tue categorie (o li hai già prenotati).</p>
        <?php else: ?>
            <div class="grid cards" role="list">
                <?php foreach ($consigliati as $e): ?>
                    <?php $evId = (int)$e['evento_id']; ?>
                    <article class="card" role="listitem">
                        <div class="card-top">
                            <span class="pill"><?= h($e['categoria'] ?? 'Categoria') ?></span>
                            <span class="pill"><?= h(date('d/m/Y H:i', strtotime((string)$e['data_evento']))) ?></span>
                        </div>

                        <h3 class="card-title"><?= h($e['titolo']) ?></h3>
                        <p class="card-meta muted">
                            <?= h($e['luogo']) ?> • <?= ((float)$e['prezzo'] <= 0) ? 'Gratis' : '€' . h(number_format((float)$e['prezzo'], 2, ',', '.')) ?>
                        </p>

                        <div class="card-actions">
                            <a class="btn small" href="<?= base_url('evento.php?id=' . $evId) ?>">Dettagli</a>
                            <a class="btn small" href="<?= base_url('eventi.php?categoria=' . (int)$e['categoria_id']) ?>">Simili</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- D) Recensioni (form + preview) -->
    <section class="panel" id="recensioni" aria-labelledby="h-recensioni">
        <header class="panel-head">
            <div>
                <h2 id="h-recensioni">Recensioni sul sito</h2>
                <p class="muted">La tua recensione sarà pubblicata dopo l’approvazione dell’admin.</p>
            </div>
            <!-- tolto bottone "Dicono di noi" -->
        </header>

        <form id="reviewForm" class="review-form" action="<?= base_url('dashboard.php') ?>#recensioni" method="post" novalidate>
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
                    <textarea id="testo" name="testo" rows="3" maxlength="250" placeholder="Scrivi la tua esperienza (min 10 caratteri)" required></textarea>
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
                            <strong><?= h($r['nome'] ?? 'Utente') ?></strong>
                            <span class="stars" aria-label="Voto <?= $voto ?> su 5"><?= h($stars) ?></span>
                        </header>
                        <p class="review-text"><?= h($r['testo']) ?></p>
                        <p class="muted review-date"><?= h(date('d/m/Y', strtotime((string)$r['data_recensione']))) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

</main>

<link rel="stylesheet" href="<?= base_url('assets/css/dashboard.css') ?>">
<script src="<?= base_url('assets/js/dashboard.js') ?>"></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>