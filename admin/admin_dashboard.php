<?php
// =========================================================
// FILE: admin/admin_dashboard.php
// Scopo didattico:
// - Dashboard admin con KPI di sintesi sullo stato della piattaforma
// - Code di moderazione (eventi e recensioni)
// - Pannello di azioni rapide per raggiungere le sezioni chiave
// - Cookie "ec_admin_layout" per preferenza layout dashboard
// =========================================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// =========================================================
// 1) Guard: SOLO ADMIN
// =========================================================
if (
    !isset($_SESSION['logged']) ||
    $_SESSION['logged'] !== true ||
    ($_SESSION['ruolo'] ?? '') !== 'admin'
) {
    $_SESSION['flash_error'] = "Accesso non autorizzato.";
    header("Location: " . base_url("login.php"));
    exit;
}

// =========================================================
// 1-bis) Cookie layout admin: 'full' | 'compact'
// =========================================================
$adminLayoutCookie = 'ec_admin_layout';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_layout'])) {
    $layout = ($_POST['admin_layout'] === 'compact') ? 'compact' : 'full';
    $expire = time() + (60 * 60 * 24 * 30); // 30 giorni

    setcookie($adminLayoutCookie, $layout, $expire, '/');
    header("Location: " . base_url("admin/admin_dashboard.php"));
    exit;
}

$admin_layout = $_COOKIE[$adminLayoutCookie] ?? 'full';

$page_title = "Dashboard Admin - EnjoyCity";
$conn       = db_connect();

// =========================================================
// 2) Flash messages (PRG)
// =========================================================
$flash_ok    = $_SESSION['flash_ok']    ?? '';
$flash_error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

// =========================================================
// 3) Utility
// =========================================================
function count_q($conn, string $sql, array $params = []): int
{
    $res = $params ? pg_query_params($conn, $sql, $params) : pg_query($conn, $sql);
    if (!$res) return 0;
    $row = pg_fetch_assoc($res);
    return (int)($row['c'] ?? 0);
}

function is_true_pg_local($v): bool
{
    return ($v === 't' || $v === true || $v === '1' || $v === 1);
}

// =========================================================
// 4) KPI principali
// =========================================================

// Utenti registrati totali
$kpi_utenti = count_q($conn, "SELECT COUNT(*) c FROM utenti");

// Eventi totali
$kpi_eventi_totali = count_q($conn, "SELECT COUNT(*) c FROM eventi");

// Eventi in vigore (approvati, attivi, non archiviati, futuri)
$kpi_eventi_in_vigore = count_q($conn, "
    SELECT COUNT(*) c
    FROM eventi
    WHERE stato = 'approvato'
      AND archiviato = FALSE
      AND stato_evento = 'attivo'
      AND data_evento >= NOW()
");

// Eventi conclusi (approvati ma già passati)
$kpi_eventi_conclusi = count_q($conn, "
    SELECT COUNT(*) c
    FROM eventi
    WHERE stato = 'approvato'
      AND data_evento < NOW()
");

// Eventi in attesa di moderazione
$kpi_eventi_attesa = count_q($conn, "
    SELECT COUNT(*) c
    FROM eventi
    WHERE stato = $1
", ['in_attesa']);

// Eventi rifiutati
$kpi_eventi_rifiutati = count_q($conn, "
    SELECT COUNT(*) c
    FROM eventi
    WHERE stato = $1
", ['rifiutato']);

// Eventi annullati
$kpi_eventi_annullati = count_q($conn, "
    SELECT COUNT(*) c
    FROM eventi
    WHERE stato = 'approvato'
      AND stato_evento = 'annullato'
");

// Eventi archiviati
$kpi_eventi_archiviati = count_q($conn, "
    SELECT COUNT(*) c
    FROM eventi
    WHERE stato = 'approvato'
      AND archiviato = TRUE
");

// Recensioni in attesa
$kpi_rec_attesa = count_q($conn, "
    SELECT COUNT(*) c
    FROM recensioni
    WHERE stato = $1
", ['in_attesa']);

// Utenti bloccati
$kpi_bloccati = count_q($conn, "
    SELECT COUNT(*) c
    FROM utenti
    WHERE bloccato = TRUE
");

// =========================================================
// 5) Code di moderazione
// =========================================================

// Eventi in attesa
$pending_events = [];
$resE = pg_query_params($conn, "
    SELECT e.id, e.titolo, e.data_evento, e.luogo, e.prezzo,
           e.prenotazione_obbligatoria, e.posti_totali, e.posti_prenotati,
           u.nome AS org_nome, u.cognome AS org_cognome
    FROM eventi e
    LEFT JOIN utenti u ON u.id = e.organizzatore_id
    WHERE e.stato = $1
    ORDER BY e.data_evento ASC
    LIMIT 10
", ['in_attesa']);
if ($resE) {
    while ($r = pg_fetch_assoc($resE)) {
        $pending_events[] = $r;
    }
}

// Recensioni in attesa
$pending_reviews = [];
$resR = pg_query_params($conn, "
    SELECT r.id, r.testo, r.voto, r.data_recensione,
           u.nome, u.cognome, u.email
    FROM recensioni r
    LEFT JOIN utenti u ON u.id = r.utente_id
    WHERE r.stato = $1
    ORDER BY r.data_recensione DESC
    LIMIT 10
", ['in_attesa']);
if ($resR) {
    while ($r = pg_fetch_assoc($resR)) {
        $pending_reviews[] = $r;
    }
}

db_close($conn);

require_once __DIR__ . '/../includes/admin_header.php';

// =========================================================
// 6) Helper per action eventi
// =========================================================
function render_action_btn(int $id, string $azione, string $label, string $class): void
{
    $confirm = "Confermi '{$azione}' su evento #{$id}?";
?>
    <form class="inline" method="post" action="<?= base_url('admin/admin_event_action.php') ?>">
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <input type="hidden" name="azione" value="<?= e($azione) ?>">
        <button class="<?= e($class) ?>" type="submit" data-confirm="<?= e($confirm) ?>">
            <?= e($label) ?>
        </button>
    </form>
<?php
}

// =========================================================
// 7) Classi dinamiche per alcuni KPI
// =========================================================
$cls_attesa   = $kpi_eventi_attesa     > 0 ? 'kpi-card kpi-card--warn'   : 'kpi-card';
$cls_rifiuti  = $kpi_eventi_rifiutati  > 0 ? 'kpi-card kpi-card--muted'  : 'kpi-card';
$cls_annull   = $kpi_eventi_annullati  > 0 ? 'kpi-card kpi-card--muted'  : 'kpi-card';
$cls_arch     = $kpi_eventi_archiviati > 0 ? 'kpi-card kpi-card--muted'  : 'kpi-card';
$cls_rec_wait = $kpi_rec_attesa        > 0 ? 'kpi-card kpi-card--warn'   : 'kpi-card';
$cls_blocc    = $kpi_bloccati          > 0 ? 'kpi-card kpi-card--danger' : 'kpi-card';

?>

<?php if ($flash_ok): ?>
    <div class="alert alert-success" role="status"><?= e($flash_ok) ?></div>
<?php endif; ?>
<?php if ($flash_error): ?>
    <div class="alert alert-error" role="alert"><?= e($flash_error) ?></div>
<?php endif; ?>

<!-- =========================================================
     7-bis) Preferenza layout dashboard
========================================================= -->
<section class="card admin-layout-toggle" aria-label="Preferenze layout dashboard admin">
    <header class="card-head">
        <h2>Layout dashboard</h2>
        <p class="muted">Scegli se visualizzare solo le statistiche o anche le code di moderazione.</p>
    </header>

    <form method="post" class="admin-layout-form">
        <button
            type="submit"
            name="admin_layout"
            value="full"
            class="btn btn-ghost <?= $admin_layout === 'full' ? 'is-active' : '' ?>">
            Dettagliato
        </button>

        <button
            type="submit"
            name="admin_layout"
            value="compact"
            class="btn btn-ghost <?= $admin_layout === 'compact' ? 'is-active' : '' ?>">
            Compatto
        </button>
    </form>
</section>

<!-- =========================================================
     8) KPI - fotografia sintetica del sistema
========================================================= -->
<section class="admin-kpi admin-kpi-<?= e($admin_layout) ?>" aria-label="Statistiche rapide">
    <article class="kpi-card">
        <h2>Utenti</h2>
        <div class="kpi-num"><?= (int)$kpi_utenti ?></div>
    </article>

    <article class="kpi-card">
        <h2>Eventi (totali)</h2>
        <div class="kpi-num"><?= (int)$kpi_eventi_totali ?></div>
    </article>

    <article class="kpi-card">
        <h2>Eventi attivi</h2>
        <div class="kpi-num"><?= (int)$kpi_eventi_in_vigore ?></div>
        <p class="muted" style="margin-top:6px;font-size:12px;">online ora</p>
    </article>

    <article class="kpi-card">
        <h2>Conclusi</h2>
        <div class="kpi-num"><?= (int)$kpi_eventi_conclusi ?></div>
        <p class="muted" style="margin-top:6px;font-size:12px;">storico</p>
    </article>

    <article class="<?= $cls_attesa ?>">
        <h2>In attesa</h2>
        <div class="kpi-num"><?= (int)$kpi_eventi_attesa ?></div>
    </article>

    <article class="<?= $cls_rifiuti ?>">
        <h2>Rifiutati</h2>
        <div class="kpi-num"><?= (int)$kpi_eventi_rifiutati ?></div>
    </article>

    <article class="<?= $cls_annull ?>">
        <h2>Annullati</h2>
        <div class="kpi-num"><?= (int)$kpi_eventi_annullati ?></div>
    </article>

    <article class="<?= $cls_arch ?>">
        <h2>Archiviati</h2>
        <div class="kpi-num"><?= (int)$kpi_eventi_archiviati ?></div>
    </article>

    <article class="<?= $cls_rec_wait ?>">
        <h2>Recensioni in attesa</h2>
        <div class="kpi-num"><?= (int)$kpi_rec_attesa ?></div>
    </article>

    <article class="<?= $cls_blocc ?>">
        <h2>Utenti bloccati</h2>
        <div class="kpi-num"><?= (int)$kpi_bloccati ?></div>
    </article>
</section>

<?php if ($admin_layout === 'full'): ?>

    <!-- =========================================================
         9) Pannello Azioni rapide
    ========================================================= -->
    <section class="card admin-quick-panel" aria-label="Azioni rapide admin">
        <header class="card-head">
            <h2>Azioni rapide</h2>
            <p class="muted">
                Vai subito alle aree principali: eventi, utenti e moderazione delle recensioni.
            </p>
        </header>

        <div class="admin-quick-grid"
            style="display:grid;gap:24px;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));margin-top:8px;">

            <!-- ===================== EVENTI ===================== -->
            <article class="admin-quick-card" style="display:flex;flex-direction:column;gap:10px;">
                <div>
                    <h3>Eventi</h3>
                    <p class="muted">Gestisci l’intero ciclo di vita: proposta, moderazione e storico.</p>
                </div>

                <div class="admin-quick-actions"
                    style="display:flex;flex-wrap:wrap;gap:10px;margin-top:4px;">
                    <a class="btn btn-ghost" href="<?= base_url('admin/admin_eventi.php') ?>">
                        Tutti gli eventi →
                    </a>
                    <a class="btn btn-ghost" href="<?= base_url('admin/admin_eventi.php#pending') ?>">
                        In attesa
                    </a>
                    <a class="btn btn-ghost" href="<?= base_url('admin/admin_eventi.php#rejected') ?>">
                        Rifiutati
                    </a>
                    <a class="btn btn-ghost" href="<?= base_url('admin/admin_eventi.php#cancelled') ?>">
                        Annullati
                    </a>
                    <a class="btn btn-ghost" href="<?= base_url('admin/admin_eventi.php#done') ?>">
                        Conclusi
                    </a>
                    <a class="btn btn-ghost" href="<?= base_url('admin/admin_eventi.php#archived') ?>">
                        Archiviati
                    </a>
                    <a class="btn btn-admin" href="<?= base_url('admin/admin_event_add.php') ?>">
                        + Nuovo evento
                    </a>
                </div>
            </article>

            <!-- ===================== UTENTI ===================== -->
            <article class="admin-quick-card" style="display:flex;flex-direction:column;gap:10px;">
                <div>
                    <h3>Utenti</h3>
                    <p class="muted">Consulta l’elenco e gestisci gli eventuali blocchi.</p>
                </div>

                <div class="admin-quick-actions"
                    style="display:flex;flex-wrap:wrap;gap:10px;margin-top:4px;">
                    <a class="btn btn-ghost" href="<?= base_url('admin/admin_utenti.php') ?>">
                        Tutti gli utenti →
                    </a>
                    <a class="btn btn-ghost" href="<?= base_url('admin/admin_utenti.php#blocked') ?>">
                        Utenti bloccati
                    </a>
                </div>
            </article>

            <!-- ===================== RECENSIONI ===================== -->
            <article class="admin-quick-card" style="display:flex;flex-direction:column;gap:10px;">
                <div>
                    <h3>Recensioni</h3>
                    <p class="muted">
                        Controlla le recensioni lasciate dagli utenti in attesa di moderazione.
                    </p>
                </div>

                <div class="admin-quick-actions"
                    style="display:flex;flex-wrap:wrap;gap:10px;margin-top:4px;">
                    <a class="btn btn-ghost" id="sec-reviews" href="#sec-reviews">
                        Recensioni da moderare
                    </a>
                </div>
            </article>

        </div>
    </section>
    <!-- =========================================================
         10) Coda di moderazione: eventi + recensioni
    ========================================================= -->
    <section class="admin-grid" aria-label="Coda di moderazione admin">
        <div class="admin-stack">

            <!-- ===================== EVENTI IN ATTESA ===================== -->
            <section class="card" aria-label="Eventi da approvare" id="sec-events-pending">
                <header class="card-head">
                    <h2>
                        Eventi in attesa
                        <?php if ($kpi_eventi_attesa > 0): ?>
                            <span class="admin-badge"><?= (int)$kpi_eventi_attesa ?></span>
                        <?php endif; ?>
                    </h2>
                    <p class="muted">Approva, rifiuta o modifica prima della pubblicazione.</p>
                </header>

                <?php if (empty($pending_events)): ?>
                    <p class="muted">Nessun evento in attesa 🎉</p>
                <?php else: ?>
                    <div class="list">
                        <?php foreach ($pending_events as $ev): ?>
                            <?php
                            $evId = (int)($ev['id'] ?? 0);
                            $org  = trim((string)($ev['org_nome'] ?? '') . ' ' . (string)($ev['org_cognome'] ?? ''));
                            ?>
                            <article class="row">
                                <div class="row-main">
                                    <h3 class="row-title"><?= e($ev['titolo'] ?? '') ?></h3>
                                    <p class="row-meta">
                                        <?= e(fmt_datetime($ev['data_evento'] ?? '')) ?>
                                        • <?= e($ev['luogo'] ?? '') ?>
                                        • ID #<?= $evId ?>
                                        <?php if ($org !== ''): ?>
                                            • Proposto da: <?= e($org) ?>
                                        <?php endif; ?>
                                    </p>
                                </div>

                                <div class="row-actions" style="flex-wrap:wrap;">
                                    <?php render_action_btn($evId, 'approva', 'Approva', 'btn btn-ok'); ?>
                                    <?php render_action_btn($evId, 'rifiuta', 'Rifiuta', 'btn btn-danger'); ?>
                                    <a class="btn btn-ghost"
                                        href="<?= base_url('admin/admin_event_edit.php?id=' . $evId) ?>">
                                        Modifica
                                    </a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <div style="margin-top:10px;">
                        <a class="btn btn-ghost" href="<?= base_url('admin/admin_eventi.php#pending') ?>">
                            Vai alla gestione eventi →
                        </a>
                    </div>
                <?php endif; ?>
            </section>

            <!-- ===================== RECENSIONI IN ATTESA ===================== -->
            <section class="card" aria-label="Recensioni da moderare" id="sec-reviews">
                <header class="card-head">
                    <h2>
                        Recensioni da moderare
                        <?php if ($kpi_rec_attesa > 0): ?>
                            <span class="admin-badge"><?= (int)$kpi_rec_attesa ?></span>
                        <?php endif; ?>
                    </h2>
                    <p class="muted">
                        Approva o rifiuta le recensioni prima della pubblicazione su “Dicono di noi”.
                    </p>
                </header>

                <?php if (empty($pending_reviews)): ?>
                    <p class="muted">Nessuna recensione in attesa 🎉</p>
                <?php else: ?>
                    <div class="list">
                        <?php foreach ($pending_reviews as $rv): ?>
                            <?php
                            $rvId   = (int)($rv['id'] ?? 0);
                            $autore = trim((string)($rv['nome'] ?? '') . ' ' . (string)($rv['cognome'] ?? ''));
                            if ($autore === '') $autore = 'Utente';
                            ?>
                            <article class="row">
                                <div class="row-main">
                                    <h3 class="row-title">
                                        <?= e($autore) ?> • Voto <?= (int)($rv['voto'] ?? 0) ?>/5
                                    </h3>
                                    <p class="row-meta">
                                        <?= e(fmt_datetime($rv['data_recensione'] ?? '')) ?>
                                        <?php if (!empty($rv['email'])): ?>
                                            • <?= e($rv['email']) ?>
                                        <?php endif; ?>
                                        • ID #<?= $rvId ?>
                                    </p>
                                    <p class="row-text"><?= e($rv['testo'] ?? '') ?></p>
                                </div>

                                <div class="row-actions" style="flex-wrap:wrap;">
                                    <form class="inline" method="post"
                                        action="<?= base_url('admin/admin_review_action.php') ?>">
                                        <input type="hidden" name="id" value="<?= $rvId ?>">
                                        <input type="hidden" name="azione" value="approva">
                                        <button class="btn btn-ok" type="submit"
                                            data-confirm="Approvare la recensione #<?= $rvId ?>?">
                                            Approva
                                        </button>
                                    </form>

                                    <form class="inline" method="post"
                                        action="<?= base_url('admin/admin_review_action.php') ?>">
                                        <input type="hidden" name="id" value="<?= $rvId ?>">
                                        <input type="hidden" name="azione" value="rifiuta">
                                        <button class="btn btn-danger" type="submit"
                                            data-confirm="Rifiutare la recensione #<?= $rvId ?>?">
                                            Rifiuta
                                        </button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

        </div>
    </section>

<?php endif; // fine layout full 
?>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>