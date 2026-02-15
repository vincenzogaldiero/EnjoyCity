<?php
// ============================================================
// FILE: admin/admin_dashboard.php
// Scopo:
// - Dashboard amministratore con KPI e "to-do list" (eventi/recensioni in attesa)
// - Solo utenti con ruolo = admin possono accedere
// - Query "leggere" + limit per evitare pagine pesanti
// ============================================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// 1) AUTH GUARD: accesso solo admin
// ============================================================
if (
    !isset($_SESSION['logged']) || $_SESSION['logged'] !== true ||
    ($_SESSION['ruolo'] ?? '') !== 'admin'
) {
    $_SESSION['flash_error'] = "Accesso non autorizzato.";
    header("Location: " . base_url("login.php"));
    exit;
}

$page_title = "Dashboard Admin - EnjoyCity";

// ============================================================
// 2) DB CONNECT
// ============================================================
$conn = db_connect();

// ============================================================
// 3) FLASH MESSAGES (messaggi una-tantum)
// ============================================================
$flash_ok    = $_SESSION['flash_ok'] ?? '';
$flash_error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

// ============================================================
// 4) Utility: COUNT "safe"
// - ritorna 0 se query fallisce
// - usata per KPI veloci
// ============================================================
function count_q($conn, string $sql, array $params = []): int
{
    $res = $params ? pg_query_params($conn, $sql, $params) : pg_query($conn, $sql);
    if (!$res) return 0;

    $row = pg_fetch_assoc($res);
    return (int)($row['c'] ?? 0);
}

// ============================================================
// 5) KPI DASHBOARD
// Nota:
// - utenti bloccati: includo blocchi "boolean" e blocchi temporanei futuri
// ============================================================

$kpi_utenti = count_q($conn, "SELECT COUNT(*) c FROM utenti;");

$kpi_eventi = count_q($conn, "SELECT COUNT(*) c FROM eventi;");

$kpi_eventi_attesa = count_q(
    $conn,
    "SELECT COUNT(*) c FROM eventi WHERE stato = $1;",
    ['in_attesa']
);

$kpi_rec_attesa = count_q(
    $conn,
    "SELECT COUNT(*) c FROM recensioni WHERE stato = $1;",
    ['in_attesa']
);

// Utenti bloccati OR temporaneamente bloccati fino a data futura
$kpi_bloccati = count_q(
    $conn,
    "SELECT COUNT(*) c
     FROM utenti
     WHERE bloccato = TRUE
        OR (bloccato_fino IS NOT NULL AND bloccato_fino > NOW());"
);

// ============================================================
// 6) EVENTI IN ATTESA (max 10)
// - lista operativa per admin: approva/rifiuta/modifica
// ============================================================
$pending_events = [];
$resE = pg_query_params($conn, "
    SELECT e.id, e.titolo, e.data_evento, e.luogo, e.prezzo,
           e.prenotazione_obbligatoria, e.posti_totali, e.posti_prenotati,
           u.nome AS org_nome, u.cognome AS org_cognome
    FROM eventi e
    LEFT JOIN utenti u ON u.id = e.organizzatore_id
    WHERE e.stato = $1
    ORDER BY e.data_evento ASC
    LIMIT 10;
", ['in_attesa']);

if ($resE) {
    while ($r = pg_fetch_assoc($resE)) $pending_events[] = $r;
}

// ============================================================
// 7) RECENSIONI IN ATTESA (max 10)
// - moderazione: approva/rifiuta
// ============================================================
$pending_reviews = [];
$resR = pg_query_params($conn, "
    SELECT r.id, r.testo, r.voto, r.data_recensione,
           u.nome, u.cognome, u.email
    FROM recensioni r
    LEFT JOIN utenti u ON u.id = r.utente_id
    WHERE r.stato = $1
    ORDER BY r.data_recensione DESC
    LIMIT 10;
", ['in_attesa']);

if ($resR) {
    while ($r = pg_fetch_assoc($resR)) $pending_reviews[] = $r;
}

// Chiudo connessione (best practice: pagina read-only)
db_close($conn);

// ============================================================
// 8) TEMPLATE HEADER ADMIN (topbar + sidebar + layout)
// ============================================================
require_once __DIR__ . '/../includes/admin_header.php';
?>

<?php if ($flash_ok): ?>
    <div class="alert alert-success" role="status"><?= e($flash_ok) ?></div>
<?php endif; ?>

<?php if ($flash_error): ?>
    <div class="alert alert-error" role="alert"><?= e($flash_error) ?></div>
<?php endif; ?>

<!-- ==========================================================
     KPI SECTION: panoramica numerica (veloce da leggere)
========================================================== -->
<section class="admin-kpi" aria-label="Statistiche rapide">
    <article class="kpi-card">
        <h2>Utenti</h2>
        <div class="kpi-num"><?= (int)$kpi_utenti ?></div>
    </article>

    <article class="kpi-card">
        <h2>Eventi</h2>
        <div class="kpi-num"><?= (int)$kpi_eventi ?></div>
    </article>

    <article class="kpi-card">
        <h2>Eventi in attesa</h2>
        <div class="kpi-num"><?= (int)$kpi_eventi_attesa ?></div>
    </article>

    <article class="kpi-card">
        <h2>Recensioni in attesa</h2>
        <div class="kpi-num"><?= (int)$kpi_rec_attesa ?></div>
    </article>

    <article class="kpi-card">
        <h2>Utenti bloccati</h2>
        <div class="kpi-num"><?= (int)$kpi_bloccati ?></div>
    </article>
</section>

<!-- ==========================================================
     GRID: due colonne (Eventi in attesa + Recensioni in attesa)
========================================================== -->
<section class="admin-grid" aria-label="Coda di moderazione admin">

    <!-- =========================
         EVENTI IN ATTESA
    ========================== -->
    <section class="card" aria-label="Eventi da approvare">
        <header class="card-head">
            <h2>Eventi da approvare</h2>
            <p class="muted">Approva, rifiuta o modifica prima della pubblicazione.</p>
        </header>

        <?php if (!$pending_events): ?>
            <p class="muted">Nessun evento in attesa 🎉</p>
        <?php else: ?>
            <div class="list">
                <?php foreach ($pending_events as $ev): ?>
                    <?php
                    $evId = (int)$ev['id'];
                    $org = trim((string)($ev['org_nome'] ?? '') . ' ' . (string)($ev['org_cognome'] ?? ''));
                    ?>

                    <article class="row" data-filter-row>
                        <div class="row-main">
                            <h3 class="row-title"><?= e($ev['titolo']) ?></h3>

                            <p class="row-meta">
                                <?= e(fmt_datetime($ev['data_evento'])) ?>
                                • <?= e($ev['luogo']) ?>
                                • ID #<?= $evId ?>
                                <?php if ($org !== ''): ?>
                                    • Proposto da: <?= e($org) ?>
                                <?php endif; ?>
                            </p>
                        </div>

                        <div class="row-actions">
                            <!-- Approva -->
                            <form class="inline" method="post" action="<?= base_url('admin/admin_event_action.php') ?>">
                                <input type="hidden" name="id" value="<?= $evId ?>">
                                <input type="hidden" name="azione" value="approva">
                                <button class="btn btn-ok" type="submit"
                                    data-confirm="Approvare l’evento #<?= $evId ?>?">
                                    Approva
                                </button>
                            </form>

                            <!-- Rifiuta -->
                            <form class="inline" method="post" action="<?= base_url('admin/admin_event_action.php') ?>">
                                <input type="hidden" name="id" value="<?= $evId ?>">
                                <input type="hidden" name="azione" value="rifiuta">
                                <button class="btn btn-danger" type="submit"
                                    data-confirm="Rifiutare l’evento #<?= $evId ?>?">
                                    Rifiuta
                                </button>
                            </form>

                            <!-- Modifica -->
                            <a class="btn btn-ghost" href="<?= base_url('admin/admin_event_edit.php?id=' . $evId) ?>">
                                Modifica
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div style="margin-top:10px;">
                <a class="btn btn-ghost" href="<?= base_url('admin/admin_eventi.php?stato=in_attesa') ?>">
                    Vedi tutti gli eventi in attesa →
                </a>
            </div>
        <?php endif; ?>
    </section>

    <!-- =========================
         RECENSIONI IN ATTESA
    ========================== -->
    <section class="card" aria-label="Recensioni da moderare">
        <header class="card-head">
            <h2>Recensioni da moderare</h2>
            <p class="muted">Approva o rifiuta. Dopo l’approvazione compaiono su “Dicono di noi”.</p>
        </header>

        <?php if (!$pending_reviews): ?>
            <p class="muted">Nessuna recensione in attesa 🎉</p>
        <?php else: ?>
            <div class="list">
                <?php foreach ($pending_reviews as $rv): ?>
                    <?php $rvId = (int)$rv['id']; ?>

                    <article class="row" data-filter-row>
                        <div class="row-main">
                            <h3 class="row-title">Voto <?= (int)$rv['voto'] ?>/5</h3>

                            <p class="row-meta">
                                <?= e(fmt_datetime($rv['data_recensione'])) ?>
                                <?php if (!empty($rv['email'])): ?> • <?= e($rv['email']) ?><?php endif; ?>
                                    • ID #<?= $rvId ?>
                            </p>

                            <p class="row-text"><?= e($rv['testo']) ?></p>
                        </div>

                        <div class="row-actions">
                            <form class="inline" method="post" action="<?= base_url('admin/admin_review_action.php') ?>">
                                <input type="hidden" name="id" value="<?= $rvId ?>">
                                <input type="hidden" name="azione" value="approva">
                                <button class="btn btn-ok" type="submit"
                                    data-confirm="Approvare la recensione #<?= $rvId ?>?">
                                    Approva
                                </button>
                            </form>

                            <form class="inline" method="post" action="<?= base_url('admin/admin_review_action.php') ?>">
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

            <div style="margin-top:10px;">
                <a class="btn btn-ghost" href="<?= base_url('admin/admin_recensioni.php?stato=in_attesa') ?>">
                    Vedi tutte le recensioni in attesa →
                </a>
            </div>
        <?php endif; ?>
    </section>

</section>

<?php
// ============================================================
// 9) TEMPLATE FOOTER ADMIN
// - qui dentro di solito importi admin.js (conferme ecc.)
// ============================================================
require_once __DIR__ . '/../includes/admin_footer.php';
?>