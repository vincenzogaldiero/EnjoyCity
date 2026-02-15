<?php
// FILE: admin/admin_eventi.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// SOLO ADMIN
if (!isset($_SESSION['logged']) || $_SESSION['logged'] !== true || ($_SESSION['ruolo'] ?? '') !== 'admin') {
    $_SESSION['flash_error'] = "Accesso non autorizzato.";
    header("Location: " . base_url("admin/admin_eventi.php"));
    exit;
}

$page_title = "Eventi - Area Admin";

$conn = db_connect();

// flash
$flash_ok    = $_SESSION['flash_ok'] ?? '';
$flash_error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

// filtri (opzionali ma utili)
$stato = trim((string)($_GET['stato'] ?? ''));
$q     = trim((string)($_GET['q'] ?? ''));

$where = [];
$params = [];
$i = 1;

if ($stato !== '') {
    $where[] = "e.stato = $" . $i;
    $params[] = $stato;
    $i++;
}
if ($q !== '') {
    $where[] = "(LOWER(e.titolo) LIKE LOWER($" . $i . ") OR LOWER(e.luogo) LIKE LOWER($" . $i . "))";
    $params[] = '%' . $q . '%';
    $i++;
}

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
if (!$res) die("Errore query eventi: " . pg_last_error($conn));

$eventi = [];
while ($r = pg_fetch_assoc($res)) $eventi[] = $r;

db_close($conn);

require_once __DIR__ . '/../includes/admin_header.php';
?>

<?php if ($flash_ok): ?>
    <div class="alert alert-success" role="status"><?= e($flash_ok) ?></div>
<?php endif; ?>
<?php if ($flash_error): ?>
    <div class="alert alert-error" role="alert"><?= e($flash_error) ?></div>
<?php endif; ?>

<section class="card" aria-label="Aggiungi evento">
    <header class="card-head">
        <h2>Gestione Eventi</h2>
        <p class="muted">Puoi pubblicare subito un evento o modificare quelli già presenti.</p>
    </header>

    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:10px;">
        <p class="muted" style="margin:0;">Clicca qui per aggiungere un evento (pubblicazione diretta).</p>
        <a class="btn btn-admin" href="<?= base_url('admin/admin_event_add.php') ?>">
            <span class="admin-btn-content">➕ Aggiungi evento <span class="admin-badge">LIVE</span></span>
        </a>
    </div>
</section>

<section class="card" aria-label="Filtri eventi">
    <header class="card-head">
        <h2>Filtri</h2>
        <p class="muted">Cerca per titolo/luogo e filtra per stato.</p>
    </header>

    <form method="get" action="<?= base_url('admin/admin_eventi.php') ?>" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">
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
            <a class="btn btn-ghost" href="<?= base_url('admin/admin_eventi.php') ?>">Reset</a>
        </div>
    </form>
</section>

<section class="card" aria-label="Elenco eventi">
    <header class="card-head">
        <h2>Eventi presenti</h2>
        <p class="muted">“Informativo” = posti totali 0 (prenotazioni disattivate).</p>
    </header>

    <?php if (!$eventi): ?>
        <p class="muted" style="margin-top:12px;">Nessun evento trovato.</p>
    <?php else: ?>
        <div class="list">
            <?php foreach ($eventi as $ev): ?>
                <?php
                $postiTot = (int)($ev['posti_totali'] ?? 0);
                $isInfo = ($postiTot === 0);
                $st = (string)($ev['stato'] ?? '');
                ?>
                <article class="row">
                    <div class="row-main">
                        <h3 class="row-title"><?= e($ev['titolo']) ?></h3>
                        <p class="row-meta">
                            <?= e(fmt_datetime($ev['data_evento'])) ?> • <?= e($ev['luogo']) ?> • ID #<?= (int)$ev['id'] ?>
                            <?php if (!empty($ev['categoria'])): ?> • <?= e($ev['categoria']) ?><?php endif; ?>
                        </p>

                        <p class="row-meta" style="margin-top:6px;">
                            <?php if ($isInfo): ?>
                                <strong>Tipo:</strong> Informativo (no prenotazioni)
                            <?php else: ?>
                                <strong>Posti:</strong> <?= (int)$ev['posti_prenotati'] ?>/<?= $postiTot ?>
                                • <strong>Prenotazione:</strong> <?= ($ev['prenotazione_obbligatoria'] === 't') ? 'Obbligatoria' : 'Non obbligatoria' ?>
                            <?php endif; ?>
                            • <strong>Prezzo:</strong> €<?= e(number_format((float)$ev['prezzo'], 2, '.', '')) ?>
                            • <strong>Stato:</strong> <?= e($st) ?>
                        </p>
                    </div>

                    <div class="row-actions">
                        <a class="btn btn-ghost" href="<?= base_url('evento.php?id=' . (int)$ev['id']) ?>">Apri</a>
                        <a class="btn btn-admin" href="<?= base_url('admin/admin_event_edit.php?id=' . (int)$ev['id']) ?>">Modifica</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>