<?php
// =========================================================
// FILE: admin/admin_utenti.php
// =========================================================
// Area Admin - Gestione Utenti
//
// Scopo didattico:
// - Mostrare come gestire lo stato degli utenti:
//   • lettura elenco utenti
//   • gestione blocco/sblocco (anche temporaneo, con data di scadenza)
//   • visualizzazione dello storico prenotazioni per ciascun utente
//   • sezione di riepilogo degli utenti attualmente bloccati
//
// Funzionalità coperte (riassunto):
// - Lista di tutti gli utenti registrati.
// - Stato di blocco (attivo / bloccato) con eventuale scadenza temporale.
// - Storico prenotazioni per singolo utente (visualizzazione in <details>).
// - Azioni di blocco/sblocco con diverse durate (24h, 7 giorni, 30 giorni, permanente).
// - Sezione riepilogativa con soli utenti attualmente bloccati.
// =========================================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Config generale (connessione al DB, helper base_url, e(), ecc.)
require_once __DIR__ . '/../includes/config.php';

// Avvio sessione (necessaria per autenticazione e messaggi flash)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------------------------------------------------------
// Helper: redirect verso la stessa pagina (PRG)
// ---------------------------------------------------------
// Usato dopo operazioni POST per:
// - chiudere la connessione
// - reindirizzare su GET evitando il reinvio del form con F5
function redirect_admin_utenti($conn = null): void
{
    if ($conn) {
        db_close($conn);
    }
    header("Location: " . base_url("admin/admin_utenti.php"));
    exit;
}

/* =========================================================
   1) Guard: SOLO ADMIN
   ---------------------------------------------------------
   - Verifica che l'utente sia loggato e abbia ruolo 'admin'
   - In caso contrario: messaggio di errore + redirect al login
========================================================= */
$logged = isset($_SESSION['logged']) && $_SESSION['logged'] === true;
$ruolo  = $_SESSION['ruolo'] ?? '';

if (!$logged || $ruolo !== 'admin') {
    $_SESSION['flash_error'] = "Accesso non autorizzato.";
    header("Location: " . base_url("login.php"));
    exit;
}

// Titolo pagina per il layout admin
$page_title = "Utenti - Area Admin";

// Apertura connessione a PostgreSQL
$conn = db_connect();

/* =========================================================
   2) POST: Azioni blocco/sblocco
   ---------------------------------------------------------
   - Gestisce tutte le azioni di blocco/sblocco inviate via POST
   - Azioni possibili:
     • sblocca
     • blocca_24h
     • blocca_7g
     • blocca_30g
     • blocca_perm
   - Per ogni azione:
     • validazione parametri
     • controllo che l'utente NON sia admin
     • aggiornamento campi bloccato / bloccato_fino
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $idRaw  = $_POST['id'] ?? '';
    $azione = (string)($_POST['azione'] ?? '');

    // Lista delle azioni ammesse (white-list)
    $azioniAmmesse = [
        'sblocca',      // rimuove qualsiasi blocco
        'blocca_24h',   // blocco temporaneo 24 ore
        'blocca_7g',    // blocco temporaneo 7 giorni
        'blocca_30g',   // blocco temporaneo 30 giorni
        'blocca_perm',  // blocco permanente
    ];

    // Validazione di base su ID e nome azione
    if (!ctype_digit((string)$idRaw) || !in_array($azione, $azioniAmmesse, true)) {
        $_SESSION['flash_error'] = "Parametri non validi per l’azione sugli utenti.";
        redirect_admin_utenti($conn);
    }

    $uid = (int)$idRaw;

    // 2.1 Lettura ruolo utente
    // Evito di bloccare eventuali altri admin oltre a me.
    $resRole = pg_query_params(
        $conn,
        "SELECT ruolo FROM utenti WHERE id = $1 LIMIT 1;",
        [$uid]
    );

    if (!$resRole || pg_num_rows($resRole) !== 1) {
        $_SESSION['flash_error'] = "Utente non trovato.";
        redirect_admin_utenti($conn);
    }

    $roleRow = pg_fetch_assoc($resRole);
    if (($roleRow['ruolo'] ?? '') === 'admin') {
        $_SESSION['flash_error'] = "Non è possibile applicare il blocco a un account admin.";
        redirect_admin_utenti($conn);
    }

    // 2.2 Calcolo scadenza blocco (bloccato_fino)
    // Se $until è NULL -> blocco permanente.
    $until = null; // default: blocco permanente

    if ($azione === 'blocca_24h') {
        $until = date('Y-m-d H:i:s', time() + 24 * 3600);
    } elseif ($azione === 'blocca_7g') {
        $until = date('Y-m-d H:i:s', time() + 7 * 24 * 3600);
    } elseif ($azione === 'blocca_30g') {
        $until = date('Y-m-d H:i:s', time() + 30 * 24 * 3600);
    }

    // 2.3 Sblocca
    // In questo caso azzero sia il flag che la data di scadenza.
    if ($azione === 'sblocca') {

        $res = pg_query_params(
            $conn,
            "UPDATE utenti
             SET bloccato = FALSE,
                 bloccato_fino = NULL
             WHERE id = $1;",
            [$uid]
        );

        if ($res) {
            $_SESSION['flash_ok'] = "Utente #$uid sbloccato correttamente.";
        } else {
            $_SESSION['flash_error'] = "Errore DB durante lo sblocco: " . pg_last_error($conn);
        }

        redirect_admin_utenti($conn);
    }

    // 2.4 Blocchi (temporanei o permanenti)
    // Se $until è NULL, il blocco viene considerato permanente.
    $res = pg_query_params(
        $conn,
        "UPDATE utenti
         SET bloccato = TRUE,
             bloccato_fino = $1
         WHERE id = $2;",
        [$until, $uid]
    );

    if ($res) {
        if ($until === null) {
            $_SESSION['flash_ok'] = "Utente #$uid bloccato in modo permanente.";
        } else {
            $_SESSION['flash_ok'] = "Utente #$uid bloccato fino a: " . $until . ".";
        }
    } else {
        $_SESSION['flash_error'] = "Errore DB durante il blocco utente: " . pg_last_error($conn);
    }

    // Chiudo connessione e applico pattern PRG
    redirect_admin_utenti($conn);
}

/* =========================================================
   3) Flash messages (GET)
   ---------------------------------------------------------
   - Recupero eventuali messaggi salvati in sessione
   - Li mostro una sola volta e poi li elimino
========================================================= */
$flash_ok    = $_SESSION['flash_ok']    ?? '';
$flash_error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

/* =========================================================
   4) GET: Lista utenti
   ---------------------------------------------------------
   - Recupero tutti gli utenti dal DB
   - Ordinamento per cognome, nome
========================================================= */
$resU = pg_query(
    $conn,
    "SELECT id, nome, cognome, email, ruolo,
            bloccato, bloccato_fino, data_registrazione
     FROM utenti
     ORDER BY cognome, nome;"
);

if (!$resU) {
    db_close($conn);
    die("Errore query utenti: " . pg_last_error($conn));
}

$utenti = [];
while ($u = pg_fetch_assoc($resU)) {
    $utenti[] = $u;
}

/* =========================================================
   5) GET: Prenotazioni (storico per utente)
   ---------------------------------------------------------
   - Precarico tutte le prenotazioni con JOIN sugli eventi
   - Indicizzo per utente in $prenByUser[utente_id]
   - Questo permette di mostrare lo storico di ciascun utente
     senza dover eseguire N query separate.
// ========================================================= */
$resP = pg_query(
    $conn,
    "SELECT p.id,
            p.utente_id,
            p.evento_id,
            p.quantita,
            p.data_prenotazione,
            e.titolo      AS evento_titolo,
            e.data_evento AS evento_data,
            e.luogo       AS evento_luogo
     FROM prenotazioni p
     JOIN eventi e ON e.id = p.evento_id
     ORDER BY p.data_prenotazione DESC;"
);

$prenByUser = [];
if ($resP) {
    while ($p = pg_fetch_assoc($resP)) {
        $uidP = (int)$p['utente_id'];
        if (!isset($prenByUser[$uidP])) {
            $prenByUser[$uidP] = [];
        }
        $prenByUser[$uidP][] = $p;
    }
}

/* =========================================================
   5-bis) Costruzione elenco utenti bloccati
   ---------------------------------------------------------
   - Usiamo la stessa logica della view per determinare
     se un utente è "bloccato adesso".
   - Condizioni:
     • campo bloccato = TRUE
       oppure
     • bloccato_fino > adesso (blocco temporaneo ancora attivo)
========================================================= */
$utenti_bloccati = [];
$nowTsGlobal     = time();

foreach ($utenti as $u) {
    // Flag raw: diversi formati possibili ('t', true, '1')
    $bloccatoFlag = (
        ($u['bloccato'] ?? 'f') === 't' ||
        $u['bloccato'] === true ||
        $u['bloccato'] === '1'
    );

    $bfRaw = (string)($u['bloccato_fino'] ?? '');
    $bfTs  = ($bfRaw !== '') ? strtotime($bfRaw) : false;

    // Utente considerato "bloccato ora" se:
    // - flag bloccato = TRUE
    //   oppure
    // - esiste una data futura di sblocco
    $isBlockedNow = $bloccatoFlag || ($bfTs !== false && $bfTs > $nowTsGlobal);

    if ($isBlockedNow) {
        $utenti_bloccati[] = $u;
    }
}

// Chiudo connessione (solo lettura da qui in poi)
db_close($conn);

// Header comune dell'area admin (navbar, layout, ecc.)
require_once __DIR__ . '/../includes/admin_header.php';
?>

<?php /* =========================================================
        6) Flash UI
        ----------------------------------------------------------
        - Visualizzazione dei messaggi di esito delle operazioni
========================================================= */ ?>
<?php if ($flash_ok): ?>
    <div class="alert alert-success" role="status"><?= e($flash_ok) ?></div>
<?php endif; ?>
<?php if ($flash_error): ?>
    <div class="alert alert-error" role="alert"><?= e($flash_error) ?></div>
<?php endif; ?>

<?php /* =========================================================
        7) Intro + Ricerca live
        ----------------------------------------------------------
        - Campo di ricerca "live" lato client, gestito da JS
        - Filtra gli utenti solamente sulla lista della pagina
========================================================= */ ?>
<section class="card" aria-label="Gestione utenti">
    <header class="card-head">
        <h2>Utenti registrati</h2>
    </header>

    <div class="field" style="margin-top:12px;">
        <label for="searchUtenti">Ricerca live</label>
        <input
            id="searchUtenti"
            type="search"
            placeholder="Cerca per nome, cognome, email, ruolo…"
            data-filter="utenti">
        <small class="hint">Il filtro agisce solo sulla lista in questa pagina.</small>
    </div>
</section>

<?php /* =========================================================
        8) Lista completa utenti + storico prenotazioni
        ----------------------------------------------------------
        - Per ogni utente:
          • dati anagrafici e ruolo
          • stato di blocco attuale (attivo / bloccato)
          • elenco prenotazioni in <details> espandibile
========================================================= */ ?>
<section class="card" aria-label="Elenco utenti">
    <header class="card-head">
        <h2>Elenco completo</h2>
        <p class="muted">
            Suggerimento: apri “Prenotazioni” per visualizzare lo storico di ciascun utente.
        </p>
        <p class="muted">
            Gli utenti bloccati non possono prenotare eventi né proporne di nuovi.
        </p>
    </header>

    <?php if (!$utenti): ?>
        <p class="muted" style="margin-top:12px;">Nessun utente presente al momento.</p>
    <?php else: ?>

        <div class="list" data-filter-scope="utenti">
            <?php foreach ($utenti as $u): ?>
                <?php
                $uid   = (int)($u['id'] ?? 0);
                $nome  = (string)($u['nome'] ?? '');
                $cogn  = (string)($u['cognome'] ?? '');
                $full  = trim($cogn . ' ' . $nome);

                $email = (string)($u['email'] ?? '');
                $role  = (string)($u['ruolo'] ?? '');

                // Stato blocco (stessa logica usata sopra)
                $bloccatoFlag = (
                    ($u['bloccato'] ?? 'f') === 't' ||
                    $u['bloccato'] === true ||
                    $u['bloccato'] === '1'
                );
                $bfRaw = (string)($u['bloccato_fino'] ?? '');

                $nowTs = time();
                $bfTs  = ($bfRaw !== '') ? strtotime($bfRaw) : false;

                $isBlockedNow = $bloccatoFlag || ($bfTs !== false && $bfTs > $nowTs);
                $isTemp       = ($bfTs !== false && $bfTs > $nowTs);

                // Prenotazioni indicizzate per utente
                $pren = $prenByUser[$uid] ?? [];

                // Classe CSS per evidenziare utenti bloccati nella lista principale
                $rowClass = "row";
                if ($isBlockedNow) {
                    $rowClass .= " is-blocked";
                }
                ?>

                <article class="<?= e($rowClass) ?>" data-filter-row>
                    <div class="row-main">
                        <h3 class="row-title">
                            <?= e($full !== '' ? $full : ("Utente #" . $uid)) ?>
                        </h3>

                        <p class="row-meta">
                            <?= e($email) ?>
                            • Ruolo: <?= e($role) ?>
                            • ID #<?= $uid ?>
                            • Registrato: <?= e(fmt_datetime($u['data_registrazione'] ?? '')) ?>
                        </p>

                        <p class="row-meta" style="margin-top:6px;">
                            Stato:
                            <?php if ($isBlockedNow): ?>
                                <strong style="color:#b91c1c;">BLOCCATO</strong>
                                <?php if ($isTemp): ?>
                                    • fino a: <strong><?= e(fmt_datetime($bfRaw)) ?></strong>
                                <?php else: ?>
                                    • (permanente)
                                <?php endif; ?>
                            <?php else: ?>
                                <strong style="color:#047857;">ATTIVO</strong>
                            <?php endif; ?>
                        </p>

                        <div style="margin-top:10px;">
                            <details>
                                <summary style="cursor:pointer;">Prenotazioni (<?= count($pren) ?>)</summary>

                                <?php if (!$pren): ?>
                                    <p class="muted" style="margin:10px 0 0;">Nessuna prenotazione effettuata.</p>
                                <?php else: ?>
                                    <div style="overflow:auto;margin-top:10px;">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>ID</th>
                                                    <th>Data pren.</th>
                                                    <th>Evento</th>
                                                    <th>Data evento</th>
                                                    <th>Luogo</th>
                                                    <th>Qtà</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($pren as $p): ?>
                                                    <tr>
                                                        <td><?= (int)$p['id'] ?></td>
                                                        <td><?= e(fmt_datetime($p['data_prenotazione'])) ?></td>
                                                        <td>
                                                            <?= e($p['evento_titolo']) ?>
                                                            (ID #<?= (int)$p['evento_id'] ?>)
                                                        </td>
                                                        <td><?= e(fmt_datetime($p['evento_data'])) ?></td>
                                                        <td><?= e($p['evento_luogo']) ?></td>
                                                        <td><?= (int)$p['quantita'] ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </details>
                        </div>
                    </div>

                    <div class="row-actions">
                        <?php if ($role === 'admin'): ?>

                            <!-- Gli account admin non sono bloccabili da qui -->
                            <span class="muted">Account amministratore</span>

                        <?php elseif ($isBlockedNow): ?>

                            <!-- Utente attualmente bloccato: mostro solo azione di sblocco -->
                            <form class="inline" method="post" action="<?= e(base_url('admin/admin_utenti.php')) ?>">
                                <input type="hidden" name="id" value="<?= $uid ?>">
                                <input type="hidden" name="azione" value="sblocca">
                                <button
                                    class="btn btn-admin"
                                    type="submit"
                                    data-confirm="Sbloccare questo utente?">
                                    Sblocca
                                </button>
                            </form>

                        <?php else: ?>

                            <!-- Utente attivo: offro diverse durate di blocco (24h, 7g, 30g, permanente) -->
                            <form class="inline" method="post" action="<?= e(base_url('admin/admin_utenti.php')) ?>">
                                <input type="hidden" name="id" value="<?= $uid ?>">
                                <input type="hidden" name="azione" value="blocca_24h">
                                <button
                                    class="btn btn-ghost"
                                    type="submit"
                                    data-confirm="Bloccare per 24 ore?">
                                    Blocca 24h
                                </button>
                            </form>

                            <form class="inline" method="post" action="<?= e(base_url('admin/admin_utenti.php')) ?>">
                                <input type="hidden" name="id" value="<?= $uid ?>">
                                <input type="hidden" name="azione" value="blocca_7g">
                                <button
                                    class="btn btn-ghost"
                                    type="submit"
                                    data-confirm="Bloccare per 7 giorni?">
                                    Blocca 7g
                                </button>
                            </form>

                            <form class="inline" method="post" action="<?= e(base_url('admin/admin_utenti.php')) ?>">
                                <input type="hidden" name="id" value="<?= $uid ?>">
                                <input type="hidden" name="azione" value="blocca_30g">
                                <button
                                    class="btn btn-ghost"
                                    type="submit"
                                    data-confirm="Bloccare per 30 giorni?">
                                    Blocca 30g
                                </button>
                            </form>

                            <form class="inline" method="post" action="<?= e(base_url('admin/admin_utenti.php')) ?>">
                                <input type="hidden" name="id" value="<?= $uid ?>">
                                <input type="hidden" name="azione" value="blocca_perm">
                                <button
                                    class="btn btn-danger"
                                    type="submit"
                                    data-confirm="Bloccare in modo permanente?">
                                    Blocca
                                </button>
                            </form>

                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php /* =========================================================
        9) Sezione riepilogativa: soli utenti bloccati
        ----------------------------------------------------------
        - Vista compatta con i soli account attualmente bloccati
        - Utile come "pannello di controllo" rapido per lo stato blocchi
========================================================= */ ?>
<section class="card" id="blocked" aria-label="Utenti bloccati">
    <header class="card-head">
        <h2>Utenti bloccati (<?= count($utenti_bloccati) ?>)</h2>
        <p class="muted">
            Riepilogo rapido degli account attualmente bloccati.
            Da qui puoi sbloccarli in un solo click.
        </p>
    </header>

    <?php if (count($utenti_bloccati) === 0): ?>
        <p class="muted" style="margin-top:12px;">
            Nessun utente risulta bloccato in questo momento.
        </p>
    <?php else: ?>
        <div class="list">
            <?php foreach ($utenti_bloccati as $u): ?>
                <?php
                $uid   = (int)($u['id'] ?? 0);
                $nome  = (string)($u['nome'] ?? '');
                $cogn  = (string)($u['cognome'] ?? '');
                $full  = trim($cogn . ' ' . $nome);

                $email = (string)($u['email'] ?? '');
                $role  = (string)($u['ruolo'] ?? '');

                $bfRaw = (string)($u['bloccato_fino'] ?? '');
                $bfTs  = ($bfRaw !== '') ? strtotime($bfRaw) : false;
                $isTemp = ($bfTs !== false && $bfTs > $nowTsGlobal);
                ?>
                <article class="row is-blocked">
                    <div class="row-main">
                        <h3 class="row-title">
                            <?= e($full !== '' ? $full : ("Utente #" . $uid)) ?>
                        </h3>
                        <p class="row-meta">
                            <?= e($email) ?> • Ruolo: <?= e($role) ?> • ID #<?= $uid ?>
                        </p>
                        <p class="row-meta" style="margin-top:6px;">
                            Blocco:
                            <?php if ($isTemp): ?>
                                <strong>temporaneo</strong> fino al
                                <strong><?= e(fmt_datetime($bfRaw)) ?></strong>
                            <?php else: ?>
                                <strong>permanente</strong>
                            <?php endif; ?>
                        </p>
                    </div>

                    <?php if ($role !== 'admin'): ?>
                        <div class="row-actions">
                            <form class="inline" method="post" action="<?= e(base_url('admin/admin_utenti.php')) ?>">
                                <input type="hidden" name="id" value="<?= $uid ?>">
                                <input type="hidden" name="azione" value="sblocca">
                                <button
                                    class="btn btn-admin"
                                    type="submit"
                                    data-confirm="Sbloccare questo utente?">
                                    Sblocca
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>