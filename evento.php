<?php
require_once __DIR__ . '/includes/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$conn = db_connect();

$logged   = isset($_SESSION['logged']) && $_SESSION['logged'] === true;
$user_id  = $_SESSION['user_id'] ?? null;

$id = $_GET['id'] ?? '';
if (!ctype_digit($id)) {
  header("Location: eventi.php"); exit;
}
$evento_id = (int)$id;

/* -----------------------------
   PRENOTAZIONE (POST)
----------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  if (!$logged || $user_id === null) {
    $_SESSION['flash_error'] = "Devi accedere per prenotare.";
    header("Location: login.php");
    exit;
  }

  $quantita = $_POST['quantita'] ?? '';
  if (!ctype_digit((string)$quantita)) {
    $_SESSION['flash_error'] = "Quantità non valida.";
    header("Location: evento.php?id=" . urlencode((string)$evento_id));
    exit;
  }

  $quantita = (int)$quantita;
  if ($quantita < 1 || $quantita > 10) {
    $_SESSION['flash_error'] = "Puoi prenotare da 1 a 10 posti.";
    header("Location: evento.php?id=" . urlencode((string)$evento_id));
    exit;
  }

  // Transazione per evitare race sui posti
  pg_query($conn, "BEGIN");

  // Lock evento
  $sqlEv = "SELECT id, posti_totali, prenotazione_obbligatoria
            FROM eventi
            WHERE id = $1 AND stato = 'approvato'
            FOR UPDATE;";
  $resEv = pg_query_params($conn, $sqlEv, [$evento_id]);
  $ev    = $resEv ? pg_fetch_assoc($resEv) : null;

  if (!$ev) {
    pg_query($conn, "ROLLBACK");
    $_SESSION['flash_error'] = "Evento non trovato.";
    header("Location: eventi.php"); exit;
  }

  // Se non è obbligatoria e non ci sono posti limitati: prenotazione non necessaria
  // (se tu vuoi comunque permettere prenotazione anche quando non obbligatoria, dimmelo e la abilito)
  $posti_totali = $ev['posti_totali']; // può essere NULL
  $pren_obbl    = ($ev['prenotazione_obbligatoria'] === 't' || $ev['prenotazione_obbligatoria'] === true || $ev['prenotazione_obbligatoria'] === '1');

  if (($posti_totali === null || $posti_totali === '') && !$pren_obbl) {
    pg_query($conn, "ROLLBACK");
    $_SESSION['flash_error'] = "Evento informativo: prenotazione non necessaria.";
    header("Location: evento.php?id=" . urlencode((string)$evento_id));
    exit;
  }

  // Blocca doppia prenotazione (stesso utente stesso evento)
  $sqlGia = "SELECT 1 FROM prenotazioni WHERE utente_id = $1 AND evento_id = $2 LIMIT 1;";
  $resGia = pg_query_params($conn, $sqlGia, [(int)$user_id, $evento_id]);
  if ($resGia && pg_num_rows($resGia) > 0) {
    pg_query($conn, "ROLLBACK");
    $_SESSION['flash_error'] = "Hai già prenotato questo evento.";
    header("Location: evento.php?id=" . urlencode((string)$evento_id));
    exit;
  }

  // Se ci sono posti limitati, controlla disponibilità
  if ($posti_totali !== null && $posti_totali !== '') {
    $posti_totali = (int)$posti_totali;

    $sqlSum = "SELECT COALESCE(SUM(quantita), 0) AS prenotati
               FROM prenotazioni
               WHERE evento_id = $1;";
    $resSum = pg_query_params($conn, $sqlSum, [$evento_id]);
    $sumRow = $resSum ? pg_fetch_assoc($resSum) : ['prenotati' => 0];
    $prenotati = (int)($sumRow['prenotati'] ?? 0);

    $disponibili = $posti_totali - $prenotati;
    if ($quantita > $disponibili) {
      pg_query($conn, "ROLLBACK");
      $_SESSION['flash_error'] = "Posti insufficienti. Disponibili: {$disponibili}.";
      header("Location: evento.php?id=" . urlencode((string)$evento_id));
      exit;
    }
  }

  // Inserisci prenotazione
  $sqlIns = "INSERT INTO prenotazioni (utente_id, evento_id, quantita)
             VALUES ($1, $2, $3);";
  $okIns = pg_query_params($conn, $sqlIns, [(int)$user_id, $evento_id, $quantita]);

  if (!$okIns) {
    pg_query($conn, "ROLLBACK");
    $_SESSION['flash_error'] = "Errore durante la prenotazione.";
    header("Location: evento.php?id=" . urlencode((string)$evento_id));
    exit;
  }

  // Aggiorna posti_prenotati (campo di comodo)
  $sqlUpd = "
    UPDATE eventi
    SET posti_prenotati = (
      SELECT COALESCE(SUM(quantita), 0) FROM prenotazioni WHERE evento_id = $1
    )
    WHERE id = $1;
  ";
  pg_query_params($conn, $sqlUpd, [$evento_id]);

  pg_query($conn, "COMMIT");

  $_SESSION['flash_success'] = "Prenotazione effettuata!";
  header("Location: evento.php?id=" . urlencode((string)$evento_id));
  exit;
}

/* -----------------------------
   DETTAGLI EVENTO (GET)
----------------------------- */
$sql = "
  SELECT e.*, c.nome AS categoria
  FROM eventi e
  LEFT JOIN categorie c ON c.id = e.categoria_id
  WHERE e.id = $1 AND e.stato = 'approvato'
  LIMIT 1;
";
$res = pg_query_params($conn, $sql, [$evento_id]);
$evento = $res ? pg_fetch_assoc($res) : null;

if (!$evento) {
  db_close($conn);
  header("Location: eventi.php"); exit;
}

$page_title = "Evento - " . $evento['titolo'];

// calcolo posti residui se limitati
$posti_residui = null;
if ($evento['posti_totali'] !== null && $evento['posti_totali'] !== '') {
  $posti_residui = (int)$evento['posti_totali'] - (int)($evento['posti_prenotati'] ?? 0);
  if ($posti_residui < 0) $posti_residui = 0;
}

// prenotazione obbligatoria?
$pren_obbl = ($evento['prenotazione_obbligatoria'] === 't' || $evento['prenotazione_obbligatoria'] === true || $evento['prenotazione_obbligatoria'] === '1');
?>?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<?php require_once __DIR__ . '/includes/header.php'; ?>

<main class="container">

  <nav class="breadcrumb" aria-label="Percorso">
    <a href="eventi.php">← Torna agli eventi</a>
  </nav>

  <?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert alert-error" role="alert">
      <?= htmlspecialchars((string)$_SESSION['flash_error'], ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php unset($_SESSION['flash_error']); ?>
  <?php endif; ?>

  <?php if (!empty($_SESSION['flash_success'])): ?>
    <div class="alert alert-success" role="status">
      <?= htmlspecialchars((string)$_SESSION['flash_success'], ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php unset($_SESSION['flash_success']); ?>
  <?php endif; ?>

  <!-- HERO EVENTO -->
  <section class="event-hero card">
    <div class="event-hero-media">
      <?php if ($logged && !empty($evento['immagine'])): ?>
        <div class="card-img">
          <div class="img-tags">
            <span class="tag-overlay hot"><?= htmlspecialchars(($evento['categoria'] ?? 'Evento'), ENT_QUOTES, 'UTF-8') ?></span>
          </div>
          <img
            src="<?= htmlspecialchars((string)$evento['immagine'], ENT_QUOTES, 'UTF-8') ?>"
            alt="Immagine evento: <?= htmlspecialchars((string)$evento['titolo'], ENT_QUOTES, 'UTF-8') ?>">
        </div>
      <?php else: ?>
        <div class="card-img" aria-hidden="true">
          <span class="muted">Immagine disponibile dopo l’accesso</span>
        </div>
      <?php endif; ?>
    </div>

    <div class="event-hero-body card-body">
      <div class="tag-row">
        <span class="tag cardtag">
          <?= htmlspecialchars(($evento['categoria'] ?? 'Evento'), ENT_QUOTES, 'UTF-8') ?>
        </span>

        <?php if ((float)$evento['prezzo'] <= 0): ?>
          <span class="tag cardtag free">Gratis</span>
        <?php else: ?>
          <span class="tag cardtag book">
            €<?= htmlspecialchars(number_format((float)$evento['prezzo'], 2, ',', '.'), ENT_QUOTES, 'UTF-8') ?>
          </span>
        <?php endif; ?>

        <?php if (!empty($pren_obbl)): ?>
          <span class="tag cardtag hot">Prenotazione</span>
        <?php endif; ?>
      </div>

      <h1><?= htmlspecialchars((string)$evento['titolo'], ENT_QUOTES, 'UTF-8') ?></h1>

      <p class="meta">
        <span class="pill">
          <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string)$evento['data_evento'])), ENT_QUOTES, 'UTF-8') ?>
        </span>
        <span class="pill"><?= htmlspecialchars((string)$evento['luogo'], ENT_QUOTES, 'UTF-8') ?></span>

        <?php if ($posti_residui !== null): ?>
          <span class="pill">
            Disponibili: <strong><?= (int)$posti_residui ?></strong> / <?= (int)$evento['posti_totali'] ?>
          </span>
        <?php endif; ?>
      </p>

      <p class="desc">
        <?= htmlspecialchars((string)$evento['descrizione_breve'], ENT_QUOTES, 'UTF-8') ?>
      </p>

      <?php if (!$logged): ?>
        <div class="empty">
          <strong>Per vedere i dettagli completi accedi.</strong><br>
          Foto, descrizione completa e prenotazione sono disponibili per gli utenti registrati.
        </div>
        <a class="cta-login" href="login.php">Accedi <small>per saperne di più</small></a>
      <?php endif; ?>
    </div>
  </section>

  <!-- LAYOUT 2 COLONNE -->
  <section class="event-layout" aria-label="Dettagli e prenotazione">
    <!-- DETTAGLI -->
    <article class="card">
      <div class="card-body">
        <h2>Dettagli</h2>

        <?php if (!$logged): ?>
          <p class="muted">Accedi per visualizzare la descrizione completa.</p>
        <?php else: ?>
          <p class="desc">
            <?= nl2br(htmlspecialchars((string)$evento['descrizione_lunga'], ENT_QUOTES, 'UTF-8')) ?>
          </p>
        <?php endif; ?>
      </div>
    </article>

    <!-- PRENOTAZIONE -->
    <aside class="card" aria-label="Box prenotazione">
      <div class="card-body">
        <h2>Prenota</h2>

        <?php if (!$logged): ?>
          <p class="muted">Accedi per prenotare.</p>
          <a class="cta-login" href="login.php">Accedi <small>per prenotare</small></a>

        <?php else: ?>
          <?php
            // Prenotazione SOLO se ci sono posti e costo > 0
            $prenotabile = (
              $evento['posti_totali'] !== null && $evento['posti_totali'] !== '' &&
              (float)$evento['prezzo'] > 0
            );
          ?>

          <?php if (!$prenotabile): ?>
            <p class="muted">Prenotazione non disponibile per questo evento.</p>
            <p class="desc">La prenotazione è prevista solo per eventi con posti limitati e costo.</p>

          <?php else: ?>
            <?php if ($posti_residui !== null && $posti_residui <= 0): ?>
              <div class="alert alert-error" role="alert">Evento sold-out.</div>
            <?php else: ?>
              <form id="bookingForm" class="auth-form" method="post" action="" novalidate>
                <div class="field">
                  <label for="quantita">Numero biglietti (1–10)</label>
                  <input id="quantita" name="quantita" type="number" min="1" max="10" required>
                  <small class="hint" id="quantitaHint"></small>
                </div>

                <button type="submit" class="btn-primary w-100">
                  Prenota • €<?= htmlspecialchars(number_format((float)$evento['prezzo'], 2, ',', '.'), ENT_QUOTES, 'UTF-8') ?>
                </button>

                <?php if ($posti_residui !== null): ?>
                  <p class="muted mt-8">Disponibili: <?= (int)$posti_residui ?></p>
                <?php endif; ?>
              </form>
            <?php endif; ?>
          <?php endif; ?>
        <?php endif; ?>

      </div>
    </aside>
  </section>

</main>

<script src="assets/js/evento.js"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

</body>
</html>
