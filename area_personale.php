<?php
// area_personale.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/config.php'; // include già session_start()

// Solo user loggato
if (!isset($_SESSION['logged']) || $_SESSION['logged'] !== true) {
  header("Location: " . base_url('login.php'));
  exit;
}
if (!isset($_SESSION['ruolo']) || $_SESSION['ruolo'] !== 'user') {
  header("Location: " . base_url('login.php'));
  exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
if ($user_id <= 0) {
  header("Location: " . base_url('login.php'));
  exit;
}

$conn = db_connect();

$flash_ok  = '';
$flash_err = '';

/* =========================================
   POST: Salvataggio preferenze (lista DESTRA)
   ordine_preferite = "3,1,5"
   ========================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $ordine_raw = trim((string)($_POST['ordine_preferite'] ?? ''));

  // può essere vuoto: significa nessuna preferenza
  $ids = [];
  if ($ordine_raw !== '') {
    $ids = array_values(array_filter(array_map('trim', explode(',', $ordine_raw))));
  }

  // validazione: numeri e no duplicati
  $seen = [];
  $valid = true;
  foreach ($ids as $x) {
    if (!ctype_digit($x)) {
      $valid = false;
      break;
    }
    if (isset($seen[$x])) {
      $valid = false;
      break;
    }
    $seen[$x] = true;
  }

  if (!$valid) {
    $flash_err = "Preferenze non valide.";
  } else {
    // check esistenza categorie (solo se ids non vuoto)
    if (count($ids) > 0) {
      $placeholders = [];
      $params = [];
      foreach ($ids as $i => $catId) {
        $placeholders[] = '$' . ($i + 1);
        $params[] = (int)$catId;
      }
      $in = implode(',', $placeholders);

      $resCheck = pg_query_params($conn, "SELECT id FROM categorie WHERE id IN ($in);", $params);
      $found = [];
      if ($resCheck) {
        while ($r = pg_fetch_assoc($resCheck)) $found[(int)$r['id']] = true;
      }

      foreach ($ids as $catId) {
        if (!isset($found[(int)$catId])) {
          $valid = false;
          break;
        }
      }

      if (!$valid) {
        $flash_err = "Una o più categorie non sono valide.";
      }
    }

    if ($flash_err === '') {
      // transazione: reset + insert preferite
      pg_query($conn, "BEGIN");

      $okDel = pg_query_params($conn, "DELETE FROM preferenze_utente WHERE utente_id = $1;", [$user_id]);

      $okIns = true;
      $sqlIns = "INSERT INTO preferenze_utente (utente_id, categoria_id, ordine) VALUES ($1, $2, $3);";
      foreach ($ids as $index => $catId) {
        $resIns = pg_query_params($conn, $sqlIns, [$user_id, (int)$catId, $index + 1]);
        if (!$resIns) {
          $okIns = false;
          break;
        }
      }

      if ($okDel && $okIns) {
        pg_query($conn, "COMMIT");
        $flash_ok = "Preferenze aggiornate!";
      } else {
        pg_query($conn, "ROLLBACK");
        $flash_err = "Errore durante il salvataggio delle preferenze.";
      }
    }
  }
}

/* =========================================
   GET: categorie preferite + disponibili
   ========================================= */
$preferite = [];
$disponibili = [];

// prendiamo tutte le categorie, con eventuale ordine preferenza
$sqlCats = "
  SELECT c.id, c.nome, pu.ordine
  FROM categorie c
  LEFT JOIN preferenze_utente pu
    ON pu.categoria_id = c.id AND pu.utente_id = $1
  ORDER BY c.nome ASC;
";
$resCats = pg_query_params($conn, $sqlCats, [$user_id]);

if ($resCats) {
  $tmpPref = [];
  while ($row = pg_fetch_assoc($resCats)) {
    if ($row['ordine'] !== null && $row['ordine'] !== '') {
      $tmpPref[] = $row;
    } else {
      $disponibili[] = $row;
    }
  }
  // preferite ordinate per "ordine"
  usort($tmpPref, function ($a, $b) {
    return (int)$a['ordine'] <=> (int)$b['ordine'];
  });
  $preferite = $tmpPref;
} else {
  $flash_err = "Errore caricamento categorie: " . pg_last_error($conn);
}

/* =========================================
   GET: Miei eventi futuri prenotati
   ========================================= */
$miei_eventi = [];
$sqlMy = "
  SELECT
    e.id AS evento_id,
    e.titolo,
    e.luogo,
    e.data_evento,
    p.quantita
  FROM prenotazioni p
  JOIN eventi e ON e.id = p.evento_id
  WHERE p.utente_id = $1
    AND e.stato = 'approvato'
    AND e.data_evento >= NOW()
  ORDER BY e.data_evento ASC
  LIMIT 20;
";
$resMy = pg_query_params($conn, $sqlMy, [$user_id]);
if ($resMy) {
  while ($row = pg_fetch_assoc($resMy)) $miei_eventi[] = $row;
}

db_close($conn);

$page_title = "Area personale - EnjoyCity";
?>

<?php require_once __DIR__ . '/includes/header.php'; ?>

<main>
  <div class="container">

    <header class="section-title">
      <div>
        <h2>Area personale</h2>
        <p class="muted">Gestisci prenotazioni e preferenze (trascina a destra ciò che ti interessa).</p>
      </div>
      <a class="btn" href="<?= base_url('eventi.php') ?>">Esplora eventi</a>
    </header>

    <?php if ($flash_ok !== ''): ?>
      <div class="alert alert-success" role="status"><?= e($flash_ok) ?></div>
    <?php endif; ?>

    <?php if ($flash_err !== ''): ?>
      <div class="alert alert-error" role="alert"><?= e($flash_err) ?></div>
    <?php endif; ?>

    <section class="area-grid" aria-label="Pannelli area personale">

      <!-- PANNELLO EVENTI -->
      <article class="card">
        <div class="card-body">
          <h3>🎫 I tuoi prossimi eventi</h3>

          <?php if (count($miei_eventi) === 0): ?>
            <p class="muted">Non hai prenotazioni attive.</p>
            <a class="cta-login" href="<?= base_url('eventi.php') ?>">Cerca eventi <small>e prenota</small></a>
          <?php else: ?>
            <?php
            $first = $miei_eventi[0];
            $firstISO = date('c', strtotime((string)$first['data_evento']));
            ?>
            <div class="countdown-box">
              <div class="tag-row">
                <span class="tag cardtag hot">Countdown</span>
                <span class="tag cardtag"><?= e(fmt_datetime($first['data_evento'])) ?></span>
              </div>
              <strong><?= e($first['titolo']) ?></strong>
              <p class="muted"><?= e($first['luogo']) ?></p>
              <p class="countdown" data-countdown="<?= e($firstISO) ?>">Calcolo…</p>
            </div>

            <ul class="my-list" aria-label="Lista prenotazioni">
              <?php foreach ($miei_eventi as $ev): ?>
                <?php $evId = (int)$ev['evento_id']; ?>
                <li class="my-item">
                  <div>
                    <strong><?= e($ev['titolo']) ?></strong>
                    <div class="meta">
                      <span class="pill"><?= e(fmt_datetime($ev['data_evento'])) ?></span>
                      <span class="pill"><?= e($ev['luogo']) ?></span>
                      <span class="pill">Biglietti: <?= (int)$ev['quantita'] ?></span>
                    </div>
                  </div>
                  <a class="btn" href="<?= base_url('evento.php?id=' . $evId) ?>">Apri</a>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </article>

      <!-- PANNELLO PREFERENZE (2 colonne) -->
      <article class="card">
        <div class="card-body">
          <h3>❤️ Le tue preferenze</h3>
          <p class="muted">Trascina le categorie a destra per selezionarle. Riordina le preferite trascinando in alto/basso.</p>

          <form method="post" id="pref-form" action="<?= base_url('area_personale.php') ?>" novalidate>

            <div class="pref-columns" aria-label="Seleziona categorie preferite">

              <!-- Sinistra: disponibili -->
              <div class="pref-col">
                <h4 class="pref-title">Disponibili</h4>
                <ul id="list-disponibili" class="dropzone" aria-label="Categorie disponibili">
                  <?php if (count($disponibili) === 0): ?>
                    <li class="drop-empty">Nessuna categoria disponibile.</li>
                  <?php else: ?>
                    <?php foreach ($disponibili as $cat): ?>
                      <li class="sortable-item" draggable="true" data-id="<?= (int)$cat['id'] ?>">
                        <span><?= e($cat['nome']) ?></span>
                        <span aria-hidden="true">⇄</span>
                      </li>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </ul>
              </div>

              <!-- Destra: preferite -->
              <div class="pref-col">
                <h4 class="pref-title">Preferite</h4>
                <ul id="list-preferite" class="dropzone preferite" aria-label="Categorie preferite">
                  <?php if (count($preferite) === 0): ?>
                    <li class="drop-empty">Trascina qui le categorie che ti interessano.</li>
                  <?php else: ?>
                    <?php foreach ($preferite as $cat): ?>
                      <li class="sortable-item" draggable="true" data-id="<?= (int)$cat['id'] ?>">
                        <span><?= e($cat['nome']) ?></span>
                        <span aria-hidden="true">☰</span>
                      </li>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </ul>
              </div>

            </div>

            <input type="hidden" name="ordine_preferite" id="ordine_input" value="">
            <button type="submit" class="btn-search pref-save" id="savePrefsBtn">Salva preferenze</button>
          </form>

          <p class="muted pref-hint">Suggerimento: metti 3–5 categorie per avere consigli più precisi.</p>
        </div>
      </article>

    </section>

  </div>
</main>

<script src="<?= base_url('assets/js/area_personale.js') ?>"></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>