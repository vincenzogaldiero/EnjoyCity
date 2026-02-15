<?php
// =========================================================
// FILE: admin/admin_eventi.php
// Scopo (area admin):
// - Gestione completa eventi in 4 sezioni, in una sola pagina:
//   1) Approvati in vigore (futuri, attivi, non archiviati)
//   2) In attesa (moderazione)
//   3) Rifiutati (storico moderazione)
//   4) Conclusi (passati)
// Scelte didattiche:
// - Separiamo MODERAZIONE (e.stato) dal LIFECYCLE (archiviato / stato_evento)
// - Query parametrizzate con pg_query_params
// - UX: pagina unica + sezioni chiare + azioni rapide per riga
// - Pattern PRG: azioni POST gestite da admin_event_action.php
// =========================================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// =========================================================
// 1) Guard: SOLO ADMIN
// =========================================================
if (!isset($_SESSION['logged']) || $_SESSION['logged'] !== true || ($_SESSION['ruolo'] ?? '') !== 'admin') {
    $_SESSION['flash_error'] = "Accesso non autorizzato.";
    header("Location: " . base_url("login.php"));
    exit;
}

$page_title = "Eventi - Area Admin";
$conn = db_connect();

// =========================================================
// 2) Flash (PRG)
// =========================================================
$flash_ok    = $_SESSION['flash_ok'] ?? '';
$flash_error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

// =========================================================
// 3) Ricerca lato server (GET)
// =========================================================
$q = trim((string)($_GET['q'] ?? ''));

// ---------------------------------------------------------
// Helper: esegue query e torna array di righe
// ---------------------------------------------------------
function fetch_all_rows($conn, string $sql, array $params = []): array
{
    $res = $params ? pg_query_params($conn, $sql, $params) : pg_query($conn, $sql);
    if (!$res) {
        die("Errore query: " . pg_last_error($conn));
    }
    $out = [];
    while ($r = pg_fetch_assoc($res)) $out[] = $r;
    return $out;
}

// ---------------------------------------------------------
// Helper: costruisce filtro di ricerca parametrizzato
// - ritorna [sql_fragment, params]
// - evita problemi se un domani aggiungi altri parametri
// ---------------------------------------------------------
function build_search_condition(string $q, int $startIndex = 1): array
{
    if ($q === '') return ['', []];

    // NOTA: uso ILIKE per case-insensitive, coerente col resto
    // startIndex permette composizione pulita se aggiungi altri filtri
    $ph = '$' . $startIndex;
    $cond = " AND (e.titolo ILIKE {$ph} OR e.luogo ILIKE {$ph}) ";
    $params = ['%' . $q . '%'];

    return [$cond, $params];
}

// =========================================================
// 4) Query per sezioni
// =========================================================
list($condQ, $paramsQ) = build_search_condition($q, 1);

// query base (stessa SELECT per coerenza UI)
$selectBase = "
  SELECT e.id, e.titolo, e.data_evento, e.luogo,
         e.prezzo, e.prenotazione_obbligatoria,
         e.posti_totali, e.posti_prenotati,
         e.stato, e.archiviato, e.stato_evento,
         c.nome AS categoria
  FROM eventi e
  LEFT JOIN categorie c ON c.id = e.categoria_id
";

// 1) LIVE: approvati + futuri + attivi + non archiviati
$sql_live = $selectBase . "
  WHERE e.stato = 'approvato'
    AND e.archiviato = FALSE
    AND e.stato_evento = 'attivo'
    AND e.data_evento >= NOW()
    {$condQ}
  ORDER BY e.data_evento ASC;
";

// 2) PENDING: in attesa (moderazione)
$sql_pending = $selectBase . "
  WHERE e.stato = 'in_attesa'
    {$condQ}
  ORDER BY e.data_evento ASC;
";

// 3) REJECTED: rifiutati
$sql_rejected = $selectBase . "
  WHERE e.stato = 'rifiutato'
    {$condQ}
  ORDER BY e.data_evento DESC;
";

// 4) DONE: conclusi (approvati passati)
// Nota: li mostriamo sempre per audit, anche se archiviati/annullati
$sql_done = $selectBase . "
  WHERE e.stato = 'approvato'
    AND e.data_evento < NOW()
    {$condQ}
  ORDER BY e.data_evento DESC;
";

// Eseguo query
$eventi_live     = fetch_all_rows($conn, $sql_live, $paramsQ);
$eventi_pending  = fetch_all_rows($conn, $sql_pending, $paramsQ);
$eventi_rejected = fetch_all_rows($conn, $sql_rejected, $paramsQ);
$eventi_done     = fetch_all_rows($conn, $sql_done, $paramsQ);

db_close($conn);

require_once __DIR__ . '/../includes/admin_header.php';

// =========================================================
// 5) Render helpers (UI)
// =========================================================

// true/false postgres safe
function is_true_pg_local($v): bool
{
    return ($v === 't' || $v === true || $v === '1' || $v === 1);
}

// ---------------------------------------------------------
// Render pulsante azione (POST verso admin_event_action.php)
// - data-confirm (se nel tuo JS lo gestisci) evita click accidentali
// ---------------------------------------------------------
function render_action_btn(int $id, string $azione, string $label, string $class = 'btn'): void
{
    $confirm = "Confermi azione '{$azione}' su evento #{$id}?";
?>
    <form class="inline" method="post" action="<?= base_url('admin/admin_event_action.php') ?>">
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <input type="hidden" name="azione" value="<?= e($azione) ?>">
        <button type="submit" class="<?= e($class) ?>" data-confirm="<?= e($confirm) ?>"><?= e($label) ?></button>
    </form>
<?php
}

// ---------------------------------------------------------
// Render riga evento (riuso UI + azioni rapide)
// $section: serve per mostrare solo i bottoni sensati
// ---------------------------------------------------------
function render_event_row(array $ev, string $section): void
{
    $id = (int)($ev['id'] ?? 0);

    // DB pulito: informativo se posti_totali è NULL
    $isInfo = ($ev['posti_totali'] === null);
    $postiPren = (int)($ev['posti_prenotati'] ?? 0);
    $postiTot  = $isInfo ? null : (int)$ev['posti_totali'];

    $st = (string)($ev['stato'] ?? '');
    $prenObbl = is_true_pg_local($ev['prenotazione_obbligatoria'] ?? 'f');

    $arch = is_true_pg_local($ev['archiviato'] ?? 'f');
    $stEv = (string)($ev['stato_evento'] ?? 'attivo');

    // classi UI
    $rowClass = "row";
    if ($st === 'in_attesa') $rowClass .= " is-pending";
    if ($st === 'approvato') $rowClass .= " is-approved";
    if ($st === 'rifiutato') $rowClass .= " is-rejected";
    if ($isInfo) $rowClass .= " is-info";
    if ($arch) $rowClass .= " is-archived";
    if ($stEv === 'annullato') $rowClass .= " is-cancelled";
?>
    <article class="<?= e($rowClass) ?>">
        <div class="row-main">
            <h3 class="row-title"><?= e($ev['titolo'] ?? '') ?></h3>

            <p class="row-meta">
                <?= e(fmt_datetime($ev['data_evento'] ?? '')) ?>
                • <?= e($ev['luogo'] ?? '') ?>
                • ID #<?= $id ?>
                <?php if (!empty($ev['categoria'])): ?> • <?= e($ev['categoria']) ?><?php endif; ?>
            </p>

            <p class="row-meta" style="margin-top:6px;">
                <?php if ($isInfo): ?>
                    <strong>Tipo:</strong> Informativo (posti NULL → no prenotazioni)
                <?php else: ?>
                    <strong>Posti:</strong> <?= $postiPren ?>/<?= (int)$postiTot ?>
                    • <strong>Prenotazione:</strong> <?= $prenObbl ? 'Obbligatoria' : 'Non obbligatoria' ?>
                <?php endif; ?>

                • <strong>Prezzo:</strong> €<?= e(number_format((float)($ev['prezzo'] ?? 0), 2, '.', '')) ?>
                • <strong>Moderazione:</strong> <?= e($st) ?>
                • <strong>Lifecycle:</strong> <?= e($stEv) ?><?= $arch ? ' • archiviato' : '' ?>
            </p>
        </div>

        <div class="row-actions" style="flex-wrap:wrap;">
            <!-- Azioni "sempre" disponibili -->
            <a class="btn btn-ghost" href="<?= e(base_url('evento.php?id=' . $id)) ?>">Apri</a>
            <a class="btn btn-admin" href="<?= e(base_url('admin/admin_event_edit.php?id=' . $id)) ?>">Modifica</a>

            <!-- Azioni rapide contestuali -->
            <?php if ($section === 'pending'): ?>
                <?php render_action_btn($id, 'approva', 'Approva', 'btn btn-ok'); ?>
                <?php render_action_btn($id, 'rifiuta', 'Rifiuta', 'btn btn-danger'); ?>
            <?php endif; ?>

            <?php if ($section === 'live'): ?>
                <?php if ($stEv === 'attivo'): ?>
                    <?php render_action_btn($id, 'annulla', 'Annulla', 'btn btn-danger'); ?>
                <?php else: ?>
                    <?php render_action_btn($id, 'riattiva', 'Riattiva', 'btn btn-ok'); ?>
                <?php endif; ?>

                <?php if (!$arch): ?>
                    <?php render_action_btn($id, 'archivia', 'Archivia', 'btn btn-ghost'); ?>
                <?php else: ?>
                    <?php render_action_btn($id, 'ripristina', 'Ripristina', 'btn btn-ok'); ?>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($section === 'done'): ?>
                <!-- Su conclusi ha senso archivia/ripristina -->
                <?php if (!$arch): ?>
                    <?php render_action_btn($id, 'archivia', 'Archivia', 'btn btn-ghost'); ?>
                <?php else: ?>
                    <?php render_action_btn($id, 'ripristina', 'Ripristina', 'btn btn-ok'); ?>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($section === 'rejected'): ?>
                <!-- Su rifiutati, eventuale ripristino (solo se vuoi recuperarlo) -->
                <?php if ($arch): ?>
                    <?php render_action_btn($id, 'ripristina', 'Ripristina', 'btn btn-ok'); ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </article>
<?php
}
?>

<?php if ($flash_ok): ?>
    <div class="alert alert-success" role="status"><?= e($flash_ok) ?></div>
<?php endif; ?>
<?php if ($flash_error): ?>
    <div class="alert alert-error" role="alert"><?= e($flash_error) ?></div>
<?php endif; ?>

<!-- =====================================================
     NAV rapido sezioni (UX + accessibilità)
===================================================== -->
<section class="card" aria-label="Navigazione rapida">
    <header class="card-head">
        <h2>Eventi</h2>
        <p class="muted">Gestione completa in un’unica pagina: moderazione + lifecycle + storico.</p>
    </header>

    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
        <a class="btn btn-ghost" href="#live">In vigore (<?= count($eventi_live) ?>)</a>
        <a class="btn btn-ghost" href="#pending">In attesa (<?= count($eventi_pending) ?>)</a>
        <a class="btn btn-ghost" href="#rejected">Rifiutati (<?= count($eventi_rejected) ?>)</a>
        <a class="btn btn-ghost" href="#done">Conclusi (<?= count($eventi_done) ?>)</a>

        <span style="flex:1;"></span>

        <a class="btn btn-admin" href="<?= e(base_url('admin/admin_event_add.php')) ?>">
            <span class="admin-btn-content">➕ Aggiungi evento <span class="admin-badge">LIVE</span></span>
        </a>
    </div>
</section>

<!-- =====================================================
     Search
===================================================== -->
<section class="card" aria-label="Ricerca eventi">
    <header class="card-head">
        <h2>Ricerca</h2>
        <p class="muted">Filtro lato server (sicuro e coerente con DB).</p>
    </header>

    <form method="get" action="<?= e(base_url('admin/admin_eventi.php')) ?>"
        style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">
        <div class="field" style="min-width:260px;flex:1;">
            <label for="q">Cerca</label>
            <input id="q" name="q" type="text" value="<?= e($q) ?>" placeholder="Titolo o luogo…">
        </div>

        <div style="display:flex;gap:10px;align-items:flex-end;">
            <button class="btn" type="submit">Applica</button>
            <a class="btn btn-ghost" href="<?= e(base_url('admin/admin_eventi.php')) ?>">Reset</a>
        </div>
    </form>
</section>

<!-- =====================================================
     1) Live
===================================================== -->
<section class="card" id="live" aria-label="Eventi approvati in vigore">
    <header class="card-head">
        <h2>Approvati in vigore</h2>
        <p class="muted">Futuri, attivi e non archiviati (visibili al pubblico).</p>
    </header>

    <?php if (!$eventi_live): ?>
        <p class="muted" style="margin-top:12px;">Nessun evento in vigore.</p>
    <?php else: ?>
        <div class="list">
            <?php foreach ($eventi_live as $ev) render_event_row($ev, 'live'); ?>
        </div>
    <?php endif; ?>
</section>

<!-- =====================================================
     2) Pending
===================================================== -->
<section class="card" id="pending" aria-label="Eventi in attesa">
    <header class="card-head">
        <h2>In attesa</h2>
        <p class="muted">Eventi da moderare (approva/rifiuta).</p>
    </header>

    <?php if (!$eventi_pending): ?>
        <p class="muted" style="margin-top:12px;">Nessun evento in attesa 🎉</p>
    <?php else: ?>
        <div class="list">
            <?php foreach ($eventi_pending as $ev) render_event_row($ev, 'pending'); ?>
        </div>
    <?php endif; ?>
</section>

<!-- =====================================================
     3) Rejected
===================================================== -->
<section class="card" id="rejected" aria-label="Eventi rifiutati">
    <header class="card-head">
        <h2>Rifiutati</h2>
        <p class="muted">Storico eventi non pubblicati (audit moderazione).</p>
    </header>

    <?php if (!$eventi_rejected): ?>
        <p class="muted" style="margin-top:12px;">Nessun evento rifiutato.</p>
    <?php else: ?>
        <div class="list">
            <?php foreach ($eventi_rejected as $ev) render_event_row($ev, 'rejected'); ?>
        </div>
    <?php endif; ?>
</section>

<!-- =====================================================
     4) Done
===================================================== -->
<section class="card" id="done" aria-label="Eventi conclusi">
    <header class="card-head">
        <h2>Conclusi</h2>
        <p class="muted">Eventi passati (restano nel DB per tracciabilità e storico).</p>
    </header>

    <?php if (!$eventi_done): ?>
        <p class="muted" style="margin-top:12px;">Nessun evento concluso.</p>
    <?php else: ?>
        <div class="list">
            <?php foreach ($eventi_done as $ev) render_event_row($ev, 'done'); ?>
        </div>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>