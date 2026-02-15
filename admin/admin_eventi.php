<?php
// FILE: admin/admin_eventi.php
// Scopo: lista e gestione eventi in area admin
// - Filtri server-side: stato + query (titolo/luogo)
// - Azioni disponibili: apri evento (vista pubblica), modifica evento (admin)
// - UX: ricerca live client-side con admin.js

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

$page_title = "Eventi - Area Admin";
$conn = db_connect();

/* =========================================================
   2) Flash messages (PRG)
========================================================= */
$flash_ok    = $_SESSION['flash_ok'] ?? '';
$flash_error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

/* =========================================================
   3) Filtri server-side (GET)
   - stato: approvato | in_attesa | rifiutato
   - q: ricerca in titolo/luogo
========================================================= */
$stato = trim((string)($_GET['stato'] ?? ''));
$q     = trim((string)($_GET['q'] ?? ''));

$where  = [];
$params = [];
$i = 1;

if ($stato !== '') {
    $where[]  = "e.stato = $" . $i;
    $params[] = $stato;
    $i++;
}

if ($q !== '') {
    $where[]  = "(LOWER(e.titolo) LIKE LOWER($" . $i . ") OR LOWER(e.luogo) LIKE LOWER($" . $i . "))";
    $params[] = '%' . $q . '%';
    $i++;
}

/* =========================================================
   4) Query eventi
========================================================= */
$sql = "
  SELECT e.id, e.titolo, e.data_evento, e.luogo,
         e.prezzo, e.prenotazione_obbligatoria,
         e.posti_totali, e.posti_prenotati,
         e.stato,
         c.nome AS categoria
  FROM eventi e
  LEFT JOIN categorie c ON c.id = e.categoria_id
";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY e.data_evento DESC;";

$res = $params ? pg_query_params($conn, $sql, $params) : pg_query($conn, $sql);
if (!$res) {
    db_close($conn);
    die("Errore query eventi: " . pg_last_error($conn));
}

$eventi = [];
while ($r = pg_fetch_assoc($res)) $eventi[] = $r;

db_close($conn);

require_once __DIR__ . '/../includes/admin_header.php';
?>

<?php /* =========================================================
        5) Flash UI
========================================================= */ ?>
<?php if ($flash_ok): ?>
    <div class="alert alert-success" role="status"><?= e($flash_ok) ?></div>
<?php endif; ?>
<?php if ($flash_error): ?>
    <div class="alert alert-error" role="alert"><?= e($flash_error) ?></div>
<?php endif; ?>

<?php /* =========================================================
        6) CTA aggiunta evento (pubblicazione diretta)
========================================================= */ ?>
<section class="card" aria-label="Aggiungi evento">
    <header class="card-head">
        <h2>Gestione Eventi</h2>
        <p class="muted">Puoi pubblicare subito un evento o modificare quelli già presenti.</p>
    </header>

    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:10px;">
        <p class="muted" style="margin:0;">Aggiungi un evento (pubblicazione diretta).</p>
        <a class="btn btn-admin" href="<?= e(base_url('admin/admin_event_add.php')) ?>">
            <span class="admin-btn-content">➕ Aggiungi evento <span class="admin-badge">LIVE</span></span>
        </a>
    </div>
</section>

<?php /* =========================================================
        7) Filtri server-side (GET)
========================================================= */ ?>
<section class="card" aria-label="Filtri eventi">
    <header class="card-head">
        <h2>Filtri</h2>
        <p class="muted">Cerca per titolo/luogo e filtra per stato (server-side).</p>
    </header>

    <form method="get" action="<?= e(base_url('admin/admin_eventi.php')) ?>"
        style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">
        <div class="field" style="min-width:260px;flex:1;">
            <label for="q">Cerca</label>
            <input id="q" name="q" type="text" value="<?= e($q) ?>" placeholder="Titolo o luogo…">
        </div>

        <div class="field" style="min-width:220px;">
            <label for="stato">Stato</label>
            <select id="stato" name="stato">
                <option value="">Tutti</option>
                <?php
                $stati = ['approvato', 'in_attesa', 'rifiutato'];
                foreach ($stati as $s) {
                    $sel = ($stato === $s) ? 'selected' : '';
                    echo '<option value="' . e($s) . '" ' . $sel . '>' . e(ucfirst(str_replace('_', ' ', $s))) . '</option>';
                }
                ?>
            </select>
        </div>

        <div style="display:flex;gap:10px;align-items:flex-end;">
            <button class="btn" type="submit">Applica</button>
            <a class="btn btn-ghost" href="<?= e(base_url('admin/admin_eventi.php')) ?>">Reset</a>
        </div>
    </form>

    <?php /* Ricerca live client-side (opzionale): filtra solo la lista già caricata */ ?>
    <div class="field" style="margin-top:12px;">
        <label for="searchEventi">Ricerca Live</label>
        <input id="searchEventi" type="search" placeholder="Filtra la lista sotto…"
            data-filter="eventi">
        <small class="hint">Non modifica il DB: filtra solo i risultati già mostrati.</small>
    </div>
</section>

<?php /* =========================================================
        8) Lista eventi
========================================================= */ ?>
<section class="card" aria-label="Elenco eventi">
    <header class="card-head">
        <h2>Eventi presenti</h2>
        <p class="muted">“Informativo” = posti totali 0 (prenotazioni disattivate).</p>
    </header>

    <?php if (!$eventi): ?>
        <p class="muted" style="margin-top:12px;">Nessun evento trovato.</p>
    <?php else: ?>

        <div class="list" data-filter-scope="eventi">
            <?php foreach ($eventi as $ev): ?>
                <?php
                $id = (int)($ev['id'] ?? 0);

                $postiTot = (int)($ev['posti_totali'] ?? 0);
                $postiPren = (int)($ev['posti_prenotati'] ?? 0);

                $isInfo = ($postiTot === 0);
                $st = (string)($ev['stato'] ?? '');

                // Classi UX per admin.js / CSS
                $rowClass = "row";
                if ($st === 'in_attesa') $rowClass .= " is-pending";
                if ($st === 'approvato') $rowClass .= " is-approved";
                if ($st === 'rifiutato') $rowClass .= " is-rejected";
                if ($isInfo) $rowClass .= " is-info";

                $prenObbl = (($ev['prenotazione_obbligatoria'] ?? 'f') === 't' || $ev['prenotazione_obbligatoria'] === true || $ev['prenotazione_obbligatoria'] === '1');
                ?>
                <article class="<?= e($rowClass) ?>" data-filter-row>
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
                                <strong>Tipo:</strong> Informativo (no prenotazioni)
                            <?php else: ?>
                                <strong>Posti:</strong> <?= $postiPren ?>/<?= $postiTot ?>
                                • <strong>Prenotazione:</strong> <?= $prenObbl ? 'Obbligatoria' : 'Non obbligatoria' ?>
                            <?php endif; ?>

                            • <strong>Prezzo:</strong> €<?= e(number_format((float)($ev['prezzo'] ?? 0), 2, '.', '')) ?>
                            • <strong>Stato:</strong> <?= e($st) ?>
                        </p>
                    </div>

                    <div class="row-actions">
                        <a class="btn btn-ghost" href="<?= e(base_url('evento.php?id=' . $id)) ?>">Apri</a>
                        <a class="btn btn-admin" href="<?= e(base_url('admin/admin_event_edit.php?id=' . $id)) ?>">Modifica</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>