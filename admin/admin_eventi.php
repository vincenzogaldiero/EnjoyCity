<?php
// =========================================================
// FILE: admin/admin_eventi.php
// Area Admin - Gestione Eventi
//
// Sezioni funzionali:
//  1) LIVE      -> approvati, futuri, attivi, NON archiviati (online al pubblico)
//  2) PENDING   -> in attesa (moderazione contenuti)
//  3) REJECTED  -> rifiutati (storico moderazione)
//  4) DONE      -> approvati, passati (audit / eventi conclusi)
//  5) CANCELLED -> approvati, stato_evento = 'annullato' (solo admin)
//  6) ARCHIVED  -> approvati, archiviati = TRUE (solo admin, non pubblico)
// =========================================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

$page_title = "Eventi - Area Admin";
$conn = db_connect();

// =========================================================
// 2) Flash message (pattern PRG: Post/Redirect/Get)
// =========================================================
$flash_ok    = $_SESSION['flash_ok'] ?? '';
$flash_error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

// =========================================================
// 3) Ricerca lato server (filtro testuale su titolo/luogo)
// =========================================================
$q = trim((string)($_GET['q'] ?? ''));

// ---------------------------------------------------------
// Helper: esegue query e torna array di righe associative
// ---------------------------------------------------------
function fetch_all_rows($conn, string $sql, array $params = []): array
{
    $res = $params ? pg_query_params($conn, $sql, $params) : pg_query($conn, $sql);
    if (!$res) {
        die("Errore query: " . pg_last_error($conn));
    }
    $out = [];
    while ($r = pg_fetch_assoc($res)) {
        $out[] = $r;
    }
    return $out;
}

// ---------------------------------------------------------
// Helper: costruisce filtro di ricerca parametrizzato
// ---------------------------------------------------------
function build_search_condition(string $q, int $startIndex = 1): array
{
    if ($q === '') {
        return ['', []];
    }

    $ph = '$' . $startIndex;
    $cond = " AND (e.titolo ILIKE {$ph} OR e.luogo ILIKE {$ph}) ";
    $params = ['%' . $q . '%'];

    return [$cond, $params];
}

// =========================================================
// 4) Query per sezioni
// =========================================================
list($condQ, $paramsQ) = build_search_condition($q, 1);

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

// 3) REJECTED: rifiutati (storico moderazione)
$sql_rejected = $selectBase . "
  WHERE e.stato = 'rifiutato'
    {$condQ}
  ORDER BY e.data_evento DESC;
";

// 4) DONE: approvati passati (qualsiasi stato_evento, utile per audit)
$sql_done = $selectBase . "
  WHERE e.stato = 'approvato'
    AND e.data_evento < NOW()
    {$condQ}
  ORDER BY e.data_evento DESC;
";

// 5) CANCELLED: approvati, stato_evento = 'annullato'
$sql_cancelled = $selectBase . "
  WHERE e.stato = 'approvato'
    AND e.stato_evento = 'annullato'
    {$condQ}
  ORDER BY e.data_evento DESC;
";

// 6) ARCHIVED: approvati archiviati (TRUE)
$sql_archived = $selectBase . "
  WHERE e.stato = 'approvato'
    AND e.archiviato = TRUE
    {$condQ}
  ORDER BY e.data_evento DESC;
";

// Esecuzione query (solo lettura)
$eventi_live      = fetch_all_rows($conn, $sql_live, $paramsQ);
$eventi_pending   = fetch_all_rows($conn, $sql_pending, $paramsQ);
$eventi_rejected  = fetch_all_rows($conn, $sql_rejected, $paramsQ);
$eventi_done      = fetch_all_rows($conn, $sql_done, $paramsQ);
$eventi_cancelled = fetch_all_rows($conn, $sql_cancelled, $paramsQ);
$eventi_archived  = fetch_all_rows($conn, $sql_archived, $paramsQ);

db_close($conn);

require_once __DIR__ . '/../includes/admin_header.php';

// =========================================================
// 5) Render helpers (UI)
// =========================================================

function is_true_pg_local($v): bool
{
    return ($v === 't' || $v === true || $v === '1' || $v === 1);
}

function render_action_btn(int $id, string $azione, string $label, string $class = 'btn'): void
{
    $confirm = "Confermi l'azione '{$azione}' sull'evento #{$id}?";
?>
    <form class="inline" method="post" action="<?= base_url('admin/admin_event_action.php') ?>">
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <input type="hidden" name="azione" value="<?= e($azione) ?>">
        <button
            type="submit"
            class="<?= e($class) ?>"
            data-confirm="<?= e($confirm) ?>"><?= e($label) ?></button>
    </form>
<?php
}

function render_event_row(array $ev, string $section): void
{
    $id = (int)($ev['id'] ?? 0);

    $isInfo    = ($ev['posti_totali'] === null);
    $postiPren = (int)($ev['posti_prenotati'] ?? 0);
    $postiTot  = $isInfo ? null : (int)$ev['posti_totali'];

    $st        = (string)($ev['stato'] ?? '');
    $arch      = is_true_pg_local($ev['archiviato'] ?? 'f');
    $stEv      = (string)($ev['stato_evento'] ?? 'attivo');
    $prenObbl  = is_true_pg_local($ev['prenotazione_obbligatoria'] ?? 'f');

    $rowClass = "row";
    if ($st === 'in_attesa')   $rowClass .= " is-pending";
    if ($st === 'approvato')   $rowClass .= " is-approved";
    if ($st === 'rifiutato')   $rowClass .= " is-rejected";
    if ($isInfo)               $rowClass .= " is-info";
    if ($arch)                 $rowClass .= " is-archived";
    if ($stEv === 'annullato') $rowClass .= " is-cancelled";
?>
    <article class="<?= e($rowClass) ?>" data-filter-row>
        <div class="row-main">
            <h3 class="row-title"><?= e($ev['titolo'] ?? '') ?></h3>

            <p class="row-meta">
                <?= e(fmt_datetime($ev['data_evento'] ?? '')) ?>
                • <?= e($ev['luogo'] ?? '') ?>
                • ID #<?= $id ?>
                <?php if (!empty($ev['categoria'])): ?>
                    • <?= e($ev['categoria']) ?>
                <?php endif; ?>
            </p>

            <p class="row-meta" style="margin-top:6px;">
                <?php if ($isInfo): ?>
                    <strong>Tipo:</strong> Informativo
                <?php else: ?>
                    <strong>Posti:</strong> <?= $postiPren ?>/<?= (int)$postiTot ?>
                    • <strong>Prenotazione:</strong> <?= $prenObbl ? 'Obbligatoria' : 'Non obbligatoria' ?>
                <?php endif; ?>

                • <strong>Prezzo:</strong> €<?= e(number_format((float)($ev['prezzo'] ?? 0), 2, '.', '')) ?>
                • <strong>Moderazione:</strong> <?= e($st) ?>
                • <strong>Stato evento:</strong> <?= e($stEv) ?><?= $arch ? ' • Archiviato' : '' ?>
            </p>
        </div>

        <div class="row-actions" style="flex-wrap:wrap;">
            <a class="btn btn-ghost" href="<?= e(base_url('evento.php?id=' . $id)) ?>">Apri</a>
            <a class="btn btn-admin" href="<?= e(base_url('admin/admin_event_edit.php?id=' . $id)) ?>">Modifica</a>

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
                <?php render_action_btn($id, 'archivia', 'Archivia', 'btn btn-ghost'); ?>
            <?php endif; ?>

            <?php if ($section === 'done'): ?>
                <?php if (!$arch): ?>
                    <?php render_action_btn($id, 'archivia', 'Archivia', 'btn btn-ghost'); ?>
                <?php else: ?>
                    <?php render_action_btn($id, 'ripristina', 'Ripristina', 'btn btn-ok'); ?>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($section === 'cancelled'): ?>
                <?php render_action_btn($id, 'riattiva', 'Riattiva', 'btn btn-ok'); ?>
                <?php if (!$arch): ?>
                    <?php render_action_btn($id, 'archivia', 'Archivia', 'btn btn-ghost'); ?>
                <?php else: ?>
                    <?php render_action_btn($id, 'ripristina', 'Ripristina', 'btn btn-ok'); ?>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($section === 'archived'): ?>
                <?php render_action_btn($id, 'ripristina', 'Ripristina', 'btn btn-ok'); ?>
                <?php if ($stEv === 'attivo'): ?>
                    <?php render_action_btn($id, 'annulla', 'Annulla', 'btn btn-danger'); ?>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($section === 'rejected'): ?>
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

<section class="card" aria-label="Navigazione rapida eventi">
    <header class="card-head">
        <h2>Eventi</h2>
    </header>

    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
        <a class="btn btn-ghost" href="#live">Attivi (<?= count($eventi_live) ?>)</a>
        <a class="btn btn-ghost" href="#pending">In attesa (<?= count($eventi_pending) ?>)</a>
        <a class="btn btn-ghost" href="#rejected">Rifiutati (<?= count($eventi_rejected) ?>)</a>
        <a class="btn btn-ghost" href="#done">Conclusi (<?= count($eventi_done) ?>)</a>
        <a class="btn btn-ghost" href="#cancelled">Annullati (<?= count($eventi_cancelled) ?>)</a>
        <a class="btn btn-ghost" href="#archived">Archiviati (<?= count($eventi_archived) ?>)</a>

        <span style="flex:1;"></span>

        <a class="btn btn-admin" href="<?= e(base_url('admin/admin_event_add.php')) ?>">
            <span class="admin-btn-content">
                ➕ Aggiungi evento
                <span class="admin-badge">LIVE</span>
            </span>
        </a>
    </div>
</section>

<section class="card" aria-label="Ricerca eventi">
    <header class="card-head">
        <h2>Ricerca</h2>
    </header>

    <form method="get"
        action="<?= e(base_url('admin/admin_eventi.php')) ?>"

        style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">
        <div class="field" style="min-width:260px;flex:1;">
            <label for="q">Ricerca live</label>
            <!-- Ricerca lato server (name="q") + live (data-filter="eventi") -->
            <input
                id="q"
                name="q"
                type="text"
                value="<?= e($q) ?>"
                placeholder="Titolo o luogo…"
                data-filter="eventi">
        </div>

        <div style="display:flex;gap:10px;align-items:flex-end;">
            <button class="btn" type="submit">Applica</button>
            <a class="btn btn-ghost" href="<?= e(base_url('admin/admin_eventi.php')) ?>">Reset</a>
        </div>
    </form>
</section>

<!-- Scope per la ricerca live sugli eventi -->
<div data-filter-scope="eventi">
    <section class="card" id="live" aria-label="Eventi approvati e attivi">
        <header class="card-head">
            <h2>Approvati e attivi (<?= count($eventi_live) ?>)</h2>
            <p class="muted">Online al pubblico: futuri, attivi, non archiviati.</p>
        </header>

        <?php if (!$eventi_live): ?>
            <p class="muted" style="margin-top:12px;">Nessun evento in vigore.</p>
        <?php else: ?>
            <div class="list">
                <?php foreach ($eventi_live as $ev) render_event_row($ev, 'live'); ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="card" id="pending" aria-label="Eventi in attesa di moderazione">
        <header class="card-head">
            <h2>In attesa (<?= count($eventi_pending) ?>)</h2>
            <p class="muted">Eventi che richiedono una decisione di moderazione.</p>
        </header>

        <?php if (!$eventi_pending): ?>
            <p class="muted" style="margin-top:12px;">Nessun evento in attesa 🎉</p>
        <?php else: ?>
            <div class="list">
                <?php foreach ($eventi_pending as $ev) render_event_row($ev, 'pending'); ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="card" id="rejected" aria-label="Eventi rifiutati">
        <header class="card-head">
            <h2>Rifiutati (<?= count($eventi_rejected) ?>)</h2>
            <p class="muted">Storico degli eventi non pubblicati.</p>
        </header>

        <?php if (!$eventi_rejected): ?>
            <p class="muted" style="margin-top:12px;">Nessun evento rifiutato.</p>
        <?php else: ?>
            <div class="list">
                <?php foreach ($eventi_rejected as $ev) render_event_row($ev, 'rejected'); ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="card" id="done" aria-label="Eventi conclusi">
        <header class="card-head">
            <h2>Conclusi (<?= count($eventi_done) ?>)</h2>
            <p class="muted">Eventi passati: utili per storico. Possono essere archiviati.</p>
        </header>

        <?php if (!$eventi_done): ?>
            <p class="muted" style="margin-top:12px;">Nessun evento concluso.</p>
        <?php else: ?>
            <div class="list">
                <?php foreach ($eventi_done as $ev) render_event_row($ev, 'done'); ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="card" id="cancelled" aria-label="Eventi annullati">
        <header class="card-head">
            <h2>Annullati (<?= count($eventi_cancelled) ?>)</h2>
            <p class="muted">Eventi approvati che sono stati annullati e non più erogati. Non visibili al pubblico.</p>
        </header>

        <?php if (!$eventi_cancelled): ?>
            <p class="muted" style="margin-top:12px;">Nessun evento annullato.</p>
        <?php else: ?>
            <div class="list">
                <?php foreach ($eventi_cancelled as $ev) render_event_row($ev, 'cancelled'); ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="card" id="archived" aria-label="Eventi archiviati">
        <header class="card-head">
            <h2>Archiviati (<?= count($eventi_archived) ?>)</h2>
            <p class="muted">Non visibili al pubblico. Restano nel database per storico.</p>
        </header>

        <?php if (!$eventi_archived): ?>
            <p class="muted" style="margin-top:12px;">Nessun evento archiviato.</p>
        <?php else: ?>
            <div class="list">
                <?php foreach ($eventi_archived as $ev) render_event_row($ev, 'archived'); ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>