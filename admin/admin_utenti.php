<?php
// FILE: admin/admin_utenti.php
// Scopo: Gestione utenti (admin)
// - Lista utenti + dettaglio prenotazioni
// - Azioni blocco/sblocco (24h / 7g / 30g / permanente)
// Regola progetto: utenti bloccati non possono prenotare né proporre eventi.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

/* =========================================================
   1) Guard: SOLO ADMIN
========================================================= */
$logged = isset($_SESSION['logged']) && $_SESSION['logged'] === true;
$ruolo  = $_SESSION['ruolo'] ?? '';
if (!$logged || $ruolo !== 'admin') {
    $_SESSION['flash_error'] = "Accesso non autorizzato.";
    header("Location: " . base_url("login.php"));
    exit;
}

$page_title = "Utenti - Area Admin";
$conn = db_connect();

/* =========================================================
   2) POST: Azioni blocco/sblocco
   - Validazione parametri
   - Update DB
   - Redirect PRG (Post/Redirect/Get) per evitare doppio submit
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $idRaw   = $_POST['id'] ?? '';
    $azione  = (string)($_POST['azione'] ?? '');

    $azioniAmmesse = ['sblocca', 'blocca_24h', 'blocca_7g', 'blocca_30g', 'blocca_perm'];

    if (!ctype_digit((string)$idRaw) || !in_array($azione, $azioniAmmesse, true)) {
        $_SESSION['flash_error'] = "Parametri non validi.";
        db_close($conn);
        header("Location: " . base_url("admin/admin_utenti.php"));
        exit;
    }

    $uid = (int)$idRaw;

    // Non permettere di bloccare/sbloccare account admin (best practice)
    $resRole = pg_query_params($conn, "SELECT ruolo FROM utenti WHERE id = $1 LIMIT 1;", [$uid]);
    if (!$resRole || pg_num_rows($resRole) !== 1) {
        $_SESSION['flash_error'] = "Utente non trovato.";
        db_close($conn);
        header("Location: " . base_url("admin/admin_utenti.php"));
        exit;
    }
    $roleRow = pg_fetch_assoc($resRole);
    if (($roleRow['ruolo'] ?? '') === 'admin') {
        $_SESSION['flash_error'] = "Non puoi applicare il blocco a un admin.";
        db_close($conn);
        header("Location: " . base_url("admin/admin_utenti.php"));
        exit;
    }

    // Calcolo scadenza blocco (se prevista)
    $until = null; // NULL = permanente (con bloccato=TRUE)
    if ($azione === 'blocca_24h') $until = date('Y-m-d H:i:s', time() + 24 * 3600);
    if ($azione === 'blocca_7g')  $until = date('Y-m-d H:i:s', time() + 7 * 24 * 3600);
    if ($azione === 'blocca_30g') $until = date('Y-m-d H:i:s', time() + 30 * 24 * 3600);

    if ($azione === 'sblocca') {
        // Sblocca totale
        $res = pg_query_params(
            $conn,
            "UPDATE utenti SET bloccato = FALSE, bloccato_fino = NULL WHERE id = $1;",
            [$uid]
        );

        if ($res) $_SESSION['flash_ok'] = "Utente #$uid sbloccato.";
        else      $_SESSION['flash_error'] = "Errore DB: " . pg_last_error($conn);
    } else {
        // Blocca (sempre bloccato=TRUE, scadenza opzionale)
        $res = pg_query_params(
            $conn,
            "UPDATE utenti SET bloccato = TRUE, bloccato_fino = $1 WHERE id = $2;",
            [$until, $uid]
        );

        if ($res) {
            $_SESSION['flash_ok'] = ($until === null)
                ? "Utente #$uid bloccato (permanente)."
                : "Utente #$uid bloccato fino a: " . $until . ".";
        } else {
            $_SESSION['flash_error'] = "Errore DB: " . pg_last_error($conn);
        }
    }

    db_close($conn);
    header("Location: " . base_url("admin/admin_utenti.php"));
    exit;
}

/* =========================================================
   3) Flash messages (GET)
========================================================= */
$flash_ok    = $_SESSION['flash_ok'] ?? '';
$flash_error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

/* =========================================================
   4) GET: Lista utenti
========================================================= */
$resU = pg_query($conn, "
  SELECT id, nome, cognome, email, ruolo, bloccato, bloccato_fino, data_registrazione
  FROM utenti
  ORDER BY cognome, nome;
");
if (!$resU) {
    db_close($conn);
    die("Errore query utenti: " . pg_last_error($conn));
}

$utenti = [];
while ($u = pg_fetch_assoc($resU)) $utenti[] = $u;

/* =========================================================
   5) GET: Prenotazioni (join eventi)
   - Raggruppo per utente per mostrarle dentro <details>
========================================================= */
$resP = pg_query($conn, "
  SELECT p.id, p.utente_id, p.evento_id, p.quantita, p.data_prenotazione,
         e.titolo AS evento_titolo, e.data_evento AS evento_data, e.luogo AS evento_luogo
  FROM prenotazioni p
  JOIN eventi e ON e.id = p.evento_id
  ORDER BY p.data_prenotazione DESC;
");

$prenByUser = [];
if ($resP) {
    while ($p = pg_fetch_assoc($resP)) {
        $uid = (int)$p['utente_id'];
        if (!isset($prenByUser[$uid])) $prenByUser[$uid] = [];
        $prenByUser[$uid][] = $p;
    }
}

db_close($conn);

require_once __DIR__ . '/../includes/admin_header.php';
?>

<?php /* =========================================================
        6) Flash UI
========================================================= */ ?>
<?php if ($flash_ok): ?>
    <div class="alert alert-success" role="status"><?= e($flash_ok) ?></div>
<?php endif; ?>
<?php if ($flash_error): ?>
    <div class="alert alert-error" role="alert"><?= e($flash_error) ?></div>
<?php endif; ?>

<?php /* =========================================================
        7) Intro + Ricerca live (admin.js)
        - data-filter="utenti" / data-filter-scope="utenti"
========================================================= */ ?>
<section class="card" aria-label="Gestione utenti">
    <header class="card-head">
        <h2>Utenti registrati</h2>
        <p class="muted">
            Blocco “smart”: puoi bloccare per 24h / 7g / 30g o permanente.
            Gli utenti bloccati non possono prenotare né proporre eventi.
        </p>
    </header>

    <div class="field" style="margin-top:12px;">
        <label for="searchUtenti">Ricerca Live</label>
        <input id="searchUtenti" type="search" placeholder="Cerca per nome, cognome, email, ruolo…"
            data-filter="utenti">
        <small class="hint">Filtra solo la lista già caricata (non modifica il DB).</small>
    </div>
</section>

<?php /* =========================================================
        8) Lista utenti
========================================================= */ ?>
<section class="card" aria-label="Elenco utenti">
    <header class="card-head">
        <h2>Elenco</h2>
        <p class="muted">Suggerimento: apri “Prenotazioni” per vedere lo storico utente.</p>
    </header>

    <?php if (!$utenti): ?>
        <p class="muted" style="margin-top:12px;">Nessun utente trovato.</p>
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

                // Stato blocco
                $bloccatoBool = (($u['bloccato'] ?? 'f') === 't' || $u['bloccato'] === true || $u['bloccato'] === '1');
                $bf           = (string)($u['bloccato_fino'] ?? '');

                $nowTs = time();
                $bfTs  = ($bf !== '') ? strtotime($bf) : false;

                // "Bloccato adesso" se:
                // - flag bloccato TRUE (permanente o comunque attivo)
                // - oppure bloccato_fino nel futuro
                $isBlockedNow = $bloccatoBool || ($bfTs !== false && $bfTs > $nowTs);

                // Temp vs perm: se c'è una scadenza futura => temporaneo
                $isTemp = ($bfTs !== false && $bfTs > $nowTs);

                // Prenotazioni utente
                $pren = $prenByUser[$uid] ?? [];

                // CSS class per highlight
                $rowClass = "row";
                if ($isBlockedNow) $rowClass .= " is-blocked";
                ?>

                <article class="<?= e($rowClass) ?>" data-filter-row>
                    <div class="row-main">
                        <h3 class="row-title"><?= e($full !== '' ? $full : ("Utente #$uid")) ?></h3>

                        <p class="row-meta">
                            <?= e($email) ?> • Ruolo: <?= e($role) ?> • ID #<?= $uid ?>
                            • Registrato: <?= e(fmt_datetime($u['data_registrazione'] ?? '')) ?>
                        </p>

                        <p class="row-meta" style="margin-top:6px;">
                            Stato:
                            <?php if ($isBlockedNow): ?>
                                <strong style="color:#b91c1c;">BLOCCATO</strong>
                                <?php if ($isTemp): ?>
                                    • fino a: <strong><?= e(fmt_datetime($bf)) ?></strong>
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
                                    <p class="muted" style="margin:10px 0 0;">Nessuna prenotazione.</p>
                                <?php else: ?>
                                    <div style="overflow:auto;margin-top:10px;">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>ID</th>
                                                    <th>Data pren.</th>
                                                    <th>Evento</th>
                                                    <th>Quando</th>
                                                    <th>Luogo</th>
                                                    <th>Qtà</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($pren as $p): ?>
                                                    <tr>
                                                        <td><?= (int)$p['id'] ?></td>
                                                        <td><?= e(fmt_datetime($p['data_prenotazione'])) ?></td>
                                                        <td><?= e($p['evento_titolo']) ?> (ID #<?= (int)$p['evento_id'] ?>)</td>
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
                            <span class="muted">Admin (azioni disabilitate)</span>

                        <?php elseif ($isBlockedNow): ?>
                            <form class="inline" method="post" action="<?= base_url('admin/admin_utenti.php') ?>">
                                <input type="hidden" name="id" value="<?= $uid ?>">
                                <input type="hidden" name="azione" value="sblocca">
                                <button class="btn btn-admin" type="submit" data-confirm="Sbloccare questo utente?">
                                    Sblocca
                                </button>
                            </form>

                        <?php else: ?>
                            <form class="inline" method="post" action="<?= base_url('admin/admin_utenti.php') ?>">
                                <input type="hidden" name="id" value="<?= $uid ?>">
                                <input type="hidden" name="azione" value="blocca_24h">
                                <button class="btn btn-ghost" type="submit" data-confirm="Bloccare per 24 ore?">Blocca 24h</button>
                            </form>

                            <form class="inline" method="post" action="<?= base_url('admin/admin_utenti.php') ?>">
                                <input type="hidden" name="id" value="<?= $uid ?>">
                                <input type="hidden" name="azione" value="blocca_7g">
                                <button class="btn btn-ghost" type="submit" data-confirm="Bloccare per 7 giorni?">Blocca 7g</button>
                            </form>

                            <form class="inline" method="post" action="<?= base_url('admin/admin_utenti.php') ?>">
                                <input type="hidden" name="id" value="<?= $uid ?>">
                                <input type="hidden" name="azione" value="blocca_30g">
                                <button class="btn btn-ghost" type="submit" data-confirm="Bloccare per 30 giorni?">Blocca 30g</button>
                            </form>

                            <form class="inline" method="post" action="<?= base_url('admin/admin_utenti.php') ?>">
                                <input type="hidden" name="id" value="<?= $uid ?>">
                                <input type="hidden" name="azione" value="blocca_perm">
                                <button class="btn btn-danger" type="submit" data-confirm="Bloccare in modo permanente?">
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

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>