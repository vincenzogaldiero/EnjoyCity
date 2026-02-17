<?php
// =========================================================
// FILE: admin/admin_dashboard.php
// Scopo didattico:
// - Dashboard admin con KPI + code di moderazione + preview rapido
// - Dashboard mostra ciò che è "online ora" (live) + accesso rapido ad archiviati
// =========================================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// =========================================================
// SOLO ADMIN
// =========================================================
if (!isset($_SESSION['logged']) || $_SESSION['logged'] !== true || ($_SESSION['ruolo'] ?? '') !== 'admin') {
    $_SESSION['flash_error'] = "Accesso non autorizzato.";
    header("Location: " . base_url("login.php"));
    exit;
}

$page_title = "Dashboard Admin - EnjoyCity";
$conn = db_connect();

// =========================================================
// Flash (PRG)
// =========================================================
$flash_ok    = $_SESSION['flash_ok'] ?? '';
$flash_error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

// =========================================================
// Utility: count query (parametrizzata se serve)
// =========================================================
function count_q($conn, string $sql, array $params = []): int
{
    $res = $params ? pg_query_params($conn, $sql, $params) : pg_query($conn, $sql);
    if (!$res) return 0;
    $row = pg_fetch_assoc($res);
    return (int)($row['c'] ?? 0);
}

// boolean postgres safe
function is_true_pg_local($v): bool
{
    return ($v === 't' || $v === true || $v === '1' || $v === 1);
}

// =========================================================
// KPI
// =========================================================
$kpi_utenti = count_q($conn, "SELECT COUNT(*) c FROM utenti");
$kpi_eventi_totali = count_q($conn, "SELECT COUNT(*) c FROM eventi");

// "online ora" = approvati + futuri + attivi + NON archiviati
$kpi_eventi_in_vigore = count_q($conn, "
    SELECT COUNT(*) c
    FROM eventi
    WHERE stato = 'approvato'
      AND archiviato = FALSE
      AND stato_evento = 'attivo'
      AND data_evento >= NOW()
");

// conclusi = approvati passati (storico)
$kpi_eventi_conclusi = count_q($conn, "
    SELECT COUNT(*) c
    FROM eventi
    WHERE stato = 'approvato'
      AND data_evento < NOW()
");

$kpi_eventi_attesa = count_q($conn, "
    SELECT COUNT(*) c
    FROM eventi
    WHERE stato = $1
", ['in_attesa']);

$kpi_eventi_rifiutati = count_q($conn, "
    SELECT COUNT(*) c
    FROM eventi
    WHERE stato = $1
", ['rifiutato']);

$kpi_eventi_annullati = count_q($conn, "
    SELECT COUNT(*) c
    FROM eventi
    WHERE stato = 'approvato'
      AND stato_evento = 'annullato'
");

$kpi_eventi_archiviati = count_q($conn, "
    SELECT COUNT(*) c
    FROM eventi
    WHERE stato = 'approvato'
      AND archiviato = TRUE
");

$kpi_rec_attesa = count_q($conn, "
    SELECT COUNT(*) c
    FROM recensioni
    WHERE stato = $1
", ['in_attesa']);

$kpi_bloccati = count_q($conn, "
    SELECT COUNT(*) c
    FROM utenti
    WHERE bloccato = TRUE
");

// =========================================================
// Coda moderazione: EVENTI in attesa
// =========================================================
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
if ($resE) while ($r = pg_fetch_assoc($resE)) $pending_events[] = $r;

// =========================================================
// Coda moderazione: RECENSIONI in attesa
// =========================================================
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
if ($resR) while ($r = pg_fetch_assoc($resR)) $pending_reviews[] = $r;

// =========================================================
// Preview: EVENTI LIVE (max 6) -> online ora
// =========================================================
$live_events = [];
$resL = pg_query($conn, "
    SELECT e.id, e.titolo, e.data_evento, e.luogo,
           e.prezzo, e.prenotazione_obbligatoria,
           e.posti_totali, e.posti_prenotati,
           e.archiviato, e.stato_evento,
           c.nome AS categoria
    FROM eventi e
    LEFT JOIN categorie c ON c.id = e.categoria_id
    WHERE e.stato = 'approvato'
      AND e.archiviato = FALSE
      AND e.stato_evento = 'attivo'
      AND e.data_evento >= NOW()
    ORDER BY e.data_evento ASC
    LIMIT 6;
");
if ($resL) while ($r = pg_fetch_assoc($resL)) $live_events[] = $r;

// =========================================================
// Preview: EVENTI ARCHIVIATI (max 6) -> solo admin
// =========================================================
$archived_events = [];
$resA = pg_query($conn, "
    SELECT e.id, e.titolo, e.data_evento, e.luogo,
           e.prezzo, e.prenotazione_obbligatoria,
           e.posti_totali, e.posti_prenotati,
           e.archiviato, e.stato_evento,
           c.nome AS categoria
    FROM eventi e
    LEFT JOIN categorie c ON c.id = e.categoria_id
    WHERE e.stato = 'approvato'
      AND e.archiviato = TRUE
    ORDER BY e.data_evento DESC
    LIMIT 6;
");
if ($resA) while ($r = pg_fetch_assoc($resA)) $archived_events[] = $r;

db_close($conn);

require_once __DIR__ . '/../includes/admin_header.php';

// =========================================================
// Render helper: bottone azione (POST a admin_event_action.php)
// =========================================================
function render_action_btn(int $id, string $azione, string $label, string $class): void
{
    $confirm = "Confermi '{$azione}' su evento #{$id}?";
?>
    <form class="inline" method="post" action="<?= base_url('admin/admin_event_action.php') ?>">
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <input type="hidden" name="azione" value="<?= e($azione) ?>">
        <button class="<?= e($class) ?>" type="submit" data-confirm="<?= e($confirm) ?>"><?= e($label) ?></button>
    </form>
<?php
}
?>

<?php if ($flash_ok): ?>
    <div class="alert alert-success" role="status"><?= e($flash_ok) ?></div>
<?php endif; ?>
<?php if ($flash_error): ?>
    <div class="alert alert-error" role="alert"><?= e($flash_error) ?></div>
<?php endif; ?>

<!-- =========================================================
     KPI
========================================================= -->
<section class="admin-kpi" aria-label="Statistiche rapide">
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
        <p class="muted" style="margin:6px 0 0 0;font-size:12px;">online ora</p>
    </article>

    <article class="kpi-card">
        <h2>Conclusi</h2>
        <div class="kpi-num"><?= (int)$kpi_eventi_conclusi ?></div>
        <p class="muted" style="margin:6px 0 0 0;font-size:12px;">storico</p>
    </article>

    <article class="kpi-card">
        <h2>In attesa</h2>
        <div class="kpi-num"><?= (int)$kpi_eventi_attesa ?></div>
    </article>

    <article class="kpi-card">
        <h2>Rifiutati</h2>
        <div class="kpi-num"><?= (int)$kpi_eventi_rifiutati ?></div>
    </article>

    <article class="kpi-card">
        <h2>Annullati</h2>
        <div class="kpi-num"><?= (int)$kpi_eventi_annullati ?></div>
    </article>

    <article class="kpi-card">
        <h2>Archiviati</h2>
        <div class="kpi-num"><?= (int)$kpi_eventi_archiviati ?></div>
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

<!-- =========================================================
     Link rapidi (UX)
========================================================= -->
<section class="card" aria-label="Azioni rapide admin">
    <header class="card-head">
        <h2>Azioni rapide</h2>
    </header>

    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <a class="btn btn-ghost" href="<?= base_url('admin/admin_eventi.php#live') ?>">Eventi in vigore →</a>
        <a class="btn btn-ghost" href="<?= base_url('admin/admin_eventi.php#pending') ?>">Eventi in attesa →</a>
        <a class="btn btn-ghost" href="<?= base_url('admin/admin_eventi.php#rejected') ?>">Rifiutati →</a>
        <a class="btn btn-ghost" href="<?= base_url('admin/admin_eventi.php#done') ?>">Conclusi →</a>
        <a class="btn btn-ghost" href="<?= base_url('admin/admin_eventi.php#archived') ?>">Archiviati →</a>
    </div>
</section>
<section class="admin-grid" aria-label="Coda di moderazione admin">

    <div class="admin-stack">

        <!-- =====================================================
             EVENTI IN ATTESA (moderazione)
        ====================================================== -->
        <section class="card" aria-label="Eventi da approvare">
            <header class="card-head">
                <h2>Eventi da approvare</h2>
                <p class="muted">Approva, rifiuta o modifica prima della pubblicazione.</p>
            </header>

            <?php if (empty($pending_events)): ?>
                <p class="muted">Nessun evento in attesa 🎉</p>
            <?php else: ?>
                <div class="list">
                    <?php foreach ($pending_events as $ev): ?>
                        <?php
                        $evId = (int)($ev['id'] ?? 0);
                        $org = trim((string)($ev['org_nome'] ?? '') . ' ' . (string)($ev['org_cognome'] ?? ''));
                        ?>
                        <article class="row">
                            <div class="row-main">
                                <h3 class="row-title"><?= e($ev['titolo'] ?? '') ?></h3>
                                <p class="row-meta">
                                    <?= e(fmt_datetime($ev['data_evento'] ?? '')) ?> • <?= e($ev['luogo'] ?? '') ?> • ID #<?= $evId ?>
                                    <?php if ($org !== ''): ?> • Proposto da: <?= e($org) ?><?php endif; ?>
                                </p>
                            </div>

                            <div class="row-actions" style="flex-wrap:wrap;">
                                <?php render_action_btn($evId, 'approva', 'Approva', 'btn btn-ok'); ?>
                                <?php render_action_btn($evId, 'rifiuta', 'Rifiuta', 'btn btn-danger'); ?>
                                <a class="btn btn-ghost" href="<?= base_url('admin/admin_event_edit.php?id=' . $evId) ?>">Modifica</a>
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

        <!-- =====================================================
             RECENSIONI IN ATTESA (moderazione)
        ====================================================== -->
        <section class="card" aria-label="Recensioni da moderare">
            <header class="card-head">
                <h2>Recensioni da moderare</h2>
                <p class="muted">Approva o rifiuta. Dopo l’approvazione sarà pubblicata su “Dicono di noi”.</p>
            </header>

            <?php if (empty($pending_reviews)): ?>
                <p class="muted">Nessuna recensione in attesa 🎉</p>
            <?php else: ?>
                <div class="list">
                    <?php foreach ($pending_reviews as $rv): ?>
                        <?php
                        $rvId = (int)($rv['id'] ?? 0);
                        $autore = trim((string)($rv['nome'] ?? '') . ' ' . (string)($rv['cognome'] ?? ''));
                        if ($autore === '') $autore = 'Utente';
                        ?>
                        <article class="row">
                            <div class="row-main">
                                <h3 class="row-title"><?= e($autore) ?> • Voto <?= (int)($rv['voto'] ?? 0) ?>/5</h3>
                                <p class="row-meta">
                                    <?= e(fmt_datetime($rv['data_recensione'] ?? '')) ?>
                                    <?php if (!empty($rv['email'])): ?> • <?= e($rv['email']) ?><?php endif; ?>
                                        • ID #<?= $rvId ?>
                                </p>
                                <p class="row-text"><?= e($rv['testo'] ?? '') ?></p>
                            </div>

                            <div class="row-actions" style="flex-wrap:wrap;">
                                <form class="inline" method="post" action="<?= base_url('admin/admin_review_action.php') ?>">
                                    <input type="hidden" name="id" value="<?= $rvId ?>">
                                    <input type="hidden" name="azione" value="approva">
                                    <button class="btn btn-ok" type="submit" data-confirm="Approvare la recensione #<?= $rvId ?>?">Approva</button>
                                </form>

                                <form class="inline" method="post" action="<?= base_url('admin/admin_review_action.php') ?>">
                                    <input type="hidden" name="id" value="<?= $rvId ?>">
                                    <input type="hidden" name="azione" value="rifiuta">
                                    <button class="btn btn-danger" type="submit" data-confirm="Rifiutare la recensione #<?= $rvId ?>?">Rifiuta</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

    </div>
</section>

<!-- =========================================================
     Preview Eventi LIVE (online ora)
========================================================= -->
<section class="card" aria-label="Eventi in vigore">
    <header class="card-head">
        <h2>Accesso rapido: eventi approvati e attivi</h2>
        <p class="muted">Online al pubblico: futuri, attivi, non archiviati.</p>
    </header>

    <?php if (!$live_events): ?>
        <p class="muted" style="margin-top:12px;">Nessun evento attivo e futuro al momento.</p>
    <?php else: ?>
        <div class="list">
            <?php foreach ($live_events as $ev): ?>
                <?php
                $id = (int)$ev['id'];
                $isInfo = ($ev['posti_totali'] === null);
                $prenObbl = is_true_pg_local($ev['prenotazione_obbligatoria'] ?? 'f');
                ?>
                <article class="row">
                    <div class="row-main">
                        <h3 class="row-title"><?= e($ev['titolo']) ?></h3>
                        <p class="row-meta">
                            <?= e(fmt_datetime($ev['data_evento'])) ?> • <?= e($ev['luogo']) ?> • ID #<?= $id ?>
                            <?php if (!empty($ev['categoria'])): ?> • <?= e($ev['categoria']) ?><?php endif; ?>
                        </p>
                        <p class="row-meta" style="margin-top:6px;">
                            <?php if ($isInfo): ?>
                                <strong>Tipo:</strong> Informativo
                            <?php else: ?>
                                <strong>Posti:</strong> <?= (int)($ev['posti_prenotati'] ?? 0) ?>/<?= (int)$ev['posti_totali'] ?>
                                • <strong>Prenotazione:</strong> <?= $prenObbl ? 'Obbligatoria' : 'Non obbligatoria' ?>
                            <?php endif; ?>
                            • <strong>Prezzo:</strong> €<?= e(number_format((float)($ev['prezzo'] ?? 0), 2, '.', '')) ?>
                        </p>
                    </div>

                    <div class="row-actions" style="flex-wrap:wrap;">
                        <a class="btn btn-ghost" href="<?= base_url('evento.php?id=' . $id) ?>">Apri</a>
                        <a class="btn btn-admin" href="<?= base_url('admin/admin_event_edit.php?id=' . $id) ?>">Modifica</a>
                        <?php render_action_btn($id, 'annulla', 'Annulla', 'btn btn-danger'); ?>
                        <?php render_action_btn($id, 'archivia', 'Archivia', 'btn btn-ghost'); ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <div style="margin-top:10px;">
            <a class="btn btn-ghost" href="<?= base_url('admin/admin_eventi.php#live') ?>">
                Vedi tutti gli eventi attivi →
            </a>
        </div>
    <?php endif; ?>
</section>

<!-- =========================================================
     Preview Eventi ARCHIVIATI (solo admin)
========================================================= -->
<section class="card" aria-label="Eventi archiviati">
    <header class="card-head">
        <h2>Accesso rapido: eventi archiviati</h2>
        <p class="muted">Non visibili al pubblico. Gestibili solo dall’admin.</p>
    </header>

    <?php if (!$archived_events): ?>
        <p class="muted" style="margin-top:12px;">Nessun evento archiviato al momento.</p>
    <?php else: ?>
        <div class="list">
            <?php foreach ($archived_events as $ev): ?>
                <?php
                $id = (int)$ev['id'];
                $isInfo = ($ev['posti_totali'] === null);
                $prenObbl = is_true_pg_local($ev['prenotazione_obbligatoria'] ?? 'f');
                $stEv = (string)($ev['stato_evento'] ?? 'attivo');
                ?>
                <article class="row is-archived">
                    <div class="row-main">
                        <h3 class="row-title"><?= e($ev['titolo']) ?></h3>
                        <p class="row-meta">
                            <?= e(fmt_datetime($ev['data_evento'])) ?> • <?= e($ev['luogo']) ?> • ID #<?= $id ?>
                            <?php if (!empty($ev['categoria'])): ?> • <?= e($ev['categoria']) ?><?php endif; ?>
                        </p>
                        <p class="row-meta" style="margin-top:6px;">
                            <?php if ($isInfo): ?>
                                <strong>Tipo:</strong> Informativo
                            <?php else: ?>
                                <strong>Posti:</strong> <?= (int)($ev['posti_prenotati'] ?? 0) ?>/<?= (int)$ev['posti_totali'] ?>
                                • <strong>Prenotazione:</strong> <?= $prenObbl ? 'Obbligatoria' : 'Non obbligatoria' ?>
                            <?php endif; ?>
                            • <strong>Prezzo:</strong> €<?= e(number_format((float)($ev['prezzo'] ?? 0), 2, '.', '')) ?>
                            • <strong>Stato evento:</strong> <?= e($stEv) ?> • <strong>Archiviato:</strong> Sì
                        </p>
                    </div>

                    <div class="row-actions" style="flex-wrap:wrap;">
                        <a class="btn btn-ghost" href="<?= base_url('evento.php?id=' . $id) ?>">Apri</a>
                        <a class="btn btn-admin" href="<?= base_url('admin/admin_event_edit.php?id=' . $id) ?>">Modifica</a>
                        <?php render_action_btn($id, 'ripristina', 'Ripristina', 'btn btn-ok'); ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <div style="margin-top:10px;">
            <a class="btn btn-ghost" href="<?= base_url('admin/admin_eventi.php#archived') ?>">
                Vedi tutti gli archiviati →
            </a>
        </div>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>