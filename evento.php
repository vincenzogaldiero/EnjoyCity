<?php
// =========================================================
// FILE: evento.php
// Dettaglio evento + prenotazione
// Scelte didattiche:
// - Eventi visibili solo se: approvati + attivi + non archiviati
// - Prenotazione solo se:
//   - utente loggato e non bloccato
//   - evento non informativo (posti_totali NOT NULL e > 0)
//   - posti disponibili sufficienti
// - Transazione + FOR UPDATE per evitare race condition (prenotazioni simultanee)
// - PRG: flash in sessione
// =========================================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$conn = db_connect();

$logged  = isset($_SESSION['logged']) && $_SESSION['logged'] === true;
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

// ---------------------------------------------------------
// Flash PRG
// ---------------------------------------------------------
$flash_err = $_SESSION['flash_error'] ?? '';
$flash_ok  = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

/* =========================================================
   1) ID EVENTO (GET)
========================================================= */
$id = $_GET['id'] ?? '';
if (!ctype_digit((string)$id)) {
  db_close($conn);
  header("Location: eventi.php");
  exit;
}
$evento_id = (int)$id;

/* =========================================================
   2) PRENOTAZIONE (POST)
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // 2.1 Login richiesto
  if (!$logged || $user_id === null) {
    $_SESSION['flash_error'] = "Devi accedere per prenotare.";
    db_close($conn);
    header("Location: " . base_url("login.php"));
    exit;
  }

  // 2.2 Utente bloccato?
  $block = user_is_blocked($conn, $user_id);
  if ($block['blocked']) {
    $_SESSION['flash_error'] = !empty($block['until'])
      ? ("Account bloccato fino al " . date('d/m/Y H:i', strtotime($block['until'])) . ".")
      : "Account bloccato. Non puoi prenotare.";
    db_close($conn);
    header("Location: " . base_url("evento.php?id=" . urlencode((string)$evento_id)));
    exit;
  }

  // 2.3 Validazione quantità
  $quantita = $_POST['quantita'] ?? '';
  if (!ctype_digit((string)$quantita)) {
    $_SESSION['flash_error'] = "Quantità non valida.";
    db_close($conn);
    header("Location: " . base_url("evento.php?id=" . urlencode((string)$evento_id)));
    exit;
  }

  $quantita = (int)$quantita;
  if ($quantita < 1 || $quantita > 10) {
    $_SESSION['flash_error'] = "Puoi prenotare da 1 a 10 posti.";
    db_close($conn);
    header("Location: " . base_url("evento.php?id=" . urlencode((string)$evento_id)));
    exit;
  }

  // 2.4 Transazione + lock riga evento
  pg_query($conn, "BEGIN");

  // Lock riga evento, MA solo se visibile/prenotabile a livello di lifecycle
  $sqlEv = "
    SELECT id, prezzo, posti_totali, prenotazione_obbligatoria, data_evento
    FROM eventi
    WHERE id = $1
      AND stato = 'approvato'
      AND archiviato = FALSE
      AND stato_evento = 'attivo'
      AND data_evento >= NOW()
    FOR UPDATE;
  ";
  $resEv = pg_query_params($conn, $sqlEv, [$evento_id]);
  $ev    = $resEv ? pg_fetch_assoc($resEv) : null;

  if (!$ev) {
    pg_query($conn, "ROLLBACK");
    $_SESSION['flash_error'] = "Evento non disponibile (non trovato, annullato, archiviato o già concluso).";
    db_close($conn);
    header("Location: " . base_url("eventi.php"));
    exit;
  }

  // 2.5 Regole DB pulito:
  // informativo = posti_totali NULL → niente prenotazioni
  if ($ev['posti_totali'] === null || $ev['posti_totali'] === '') {
    pg_query($conn, "ROLLBACK");
    $_SESSION['flash_error'] = "Evento informativo: prenotazione non disponibile.";
    db_close($conn);
    header("Location: " . base_url("evento.php?id=" . urlencode((string)$evento_id)));
    exit;
  }

  $posti_totali = (int)$ev['posti_totali'];
  if ($posti_totali <= 0) {
    pg_query($conn, "ROLLBACK");
    $_SESSION['flash_error'] = "Prenotazione non disponibile per questo evento.";
    db_close($conn);
    header("Location: " . base_url("evento.php?id=" . urlencode((string)$evento_id)));
    exit;
  }

  // (facoltativo) Se vuoi permettere prenotazioni anche per eventi gratis, togli questo check.
  // Io lo lascio coerente con la tua logica originale: prenotazioni solo se evento "a biglietto".
  $prezzo = (float)$ev['prezzo'];
  if ($prezzo <= 0) {
    pg_query($conn, "ROLLBACK");
    $_SESSION['flash_error'] = "Evento gratuito: prenotazione non disponibile.";
    db_close($conn);
    header("Location: " . base_url("evento.php?id=" . urlencode((string)$evento_id)));
    exit;
  }

  // 2.6 Doppia prenotazione (ora c'è anche vincolo UNIQUE consigliato)
  $sqlGia = "SELECT 1 FROM prenotazioni WHERE utente_id = $1 AND evento_id = $2 LIMIT 1;";
  $resGia = pg_query_params($conn, $sqlGia, [$user_id, $evento_id]);
  if ($resGia && pg_num_rows($resGia) > 0) {
    pg_query($conn, "ROLLBACK");
    $_SESSION['flash_error'] = "Hai già prenotato questo evento.";
    db_close($conn);
    header("Location: " . base_url("evento.php?id=" . urlencode((string)$evento_id)));
    exit;
  }

  // 2.7 Calcolo posti prenotati reali (somma prenotazioni)
  $sqlSum = "SELECT COALESCE(SUM(quantita), 0) AS prenotati FROM prenotazioni WHERE evento_id = $1;";
  $resSum = pg_query_params($conn, $sqlSum, [$evento_id]);
  $sumRow = $resSum ? pg_fetch_assoc($resSum) : ['prenotati' => 0];
  $prenotati = (int)($sumRow['prenotati'] ?? 0);

  $disponibili = $posti_totali - $prenotati;
  if ($quantita > $disponibili) {
    pg_query($conn, "ROLLBACK");
    $_SESSION['flash_error'] = "Posti insufficienti. Disponibili: {$disponibili}.";
    db_close($conn);
    header("Location: " . base_url("evento.php?id=" . urlencode((string)$evento_id)));
    exit;
  }

  // 2.8 Inserimento prenotazione
  $sqlIns = "INSERT INTO prenotazioni (utente_id, evento_id, quantita) VALUES ($1, $2, $3);";
  $okIns  = pg_query_params($conn, $sqlIns, [$user_id, $evento_id, $quantita]);

  if (!$okIns) {
    pg_query($conn, "ROLLBACK");
    $_SESSION['flash_error'] = "Errore durante la prenotazione.";
    db_close($conn);
    header("Location: " . base_url("evento.php?id=" . urlencode((string)$evento_id)));
    exit;
  }

  // 2.9 aggiorno campo di comodo posti_prenotati
  $sqlUpd = "
    UPDATE eventi
    SET posti_prenotati = (SELECT COALESCE(SUM(quantita), 0) FROM prenotazioni WHERE evento_id = $1)
    WHERE id = $1;
  ";
  pg_query_params($conn, $sqlUpd, [$evento_id]);

  pg_query($conn, "COMMIT");

  $_SESSION['flash_success'] = "Prenotazione effettuata!";
  db_close($conn);
  header("Location: " . base_url("evento.php?id=" . urlencode((string)$evento_id)));
  exit;
}

/* =========================================================
   3) DETTAGLI EVENTO (GET)
   - pubblico vede descrizione breve
   - loggato vede descrizione lunga + (se prenotabile) box prenota
========================================================= */
$sql = "
  SELECT e.*, c.nome AS categoria
  FROM eventi e
  LEFT JOIN categorie c ON c.id = e.categoria_id
  WHERE e.id = $1
    AND e.stato = 'approvato'
    AND e.archiviato = FALSE
    AND e.stato_evento = 'attivo'
    AND e.data_evento >= NOW()
  LIMIT 1;
";
$res = pg_query_params($conn, $sql, [$evento_id]);
$evento = $res ? pg_fetch_assoc($res) : null;

if (!$evento) {
  db_close($conn);
  header("Location: " . base_url("eventi.php"));
  exit;
}

$page_title = "Evento - " . (string)$evento['titolo'];

/* =========================================================
   4) CALCOLI UI
========================================================= */
// informativo = posti_totali NULL
$isInfo = ($evento['posti_totali'] === null || $evento['posti_totali'] === '');
$postiTot = $isInfo ? null : (int)$evento['posti_totali'];

$posti_residui = null;
if (!$isInfo && $postiTot !== null && $postiTot > 0) {
  $posti_residui = $postiTot - (int)($evento['posti_prenotati'] ?? 0);
  if ($posti_residui < 0) $posti_residui = 0;
}

// prenotazione obbligatoria tag
$pren_obbl = (
  ($evento['prenotazione_obbligatoria'] === 't') ||
  ($evento['prenotazione_obbligatoria'] === true) ||
  ($evento['prenotazione_obbligatoria'] === '1')
);

// controllo blocco per UI (solo loggato)
$user_blocked = false;
$block_msg = '';
if ($logged && $user_id !== null) {
  $block = user_is_blocked($conn, $user_id);
  if ($block['blocked']) {
    $user_blocked = true;
    $block_msg = !empty($block['until'])
      ? "Il tuo account è bloccato fino al " . date('d/m/Y H:i', strtotime($block['until'])) . "."
      : "Il tuo account è stato bloccato dall’amministratore.";
  }
}

// regola UI prenota: non informativo + posti >0 + prezzo >0
$prenotabile = (!$isInfo && $postiTot !== null && $postiTot > 0 && (float)$evento['prezzo'] > 0);

// qui chiudo: per render non serve DB
db_close($conn);

require_once __DIR__ . '/includes/header.php';
?>

<main class="container">

  <nav class="breadcrumb" aria-label="Percorso">
    <a href="<?= base_url('eventi.php') ?>">← Torna agli eventi</a>
  </nav>

  <?php if ($flash_err): ?>
    <div class="alert alert-error" role="alert"><?= e($flash_err) ?></div>
  <?php endif; ?>

  <?php if ($flash_ok): ?>
    <div class="alert alert-success" role="status"><?= e($flash_ok) ?></div>
  <?php endif; ?>

  <!-- HERO EVENTO -->
  <section class="event-hero card">
    <div class="event-hero-media">
      <?php if ($logged && !empty($evento['immagine'])): ?>
        <div class="card-img">
          <div class="img-tags">
            <span class="tag-overlay hot"><?= e(($evento['categoria'] ?? 'Evento')) ?></span>
          </div>
          <img
            src="<?= e((string)$evento['immagine']) ?>"
            alt="Immagine evento: <?= e((string)$evento['titolo']) ?>">
        </div>
      <?php else: ?>
        <div class="card-img" aria-hidden="true">
          <span class="muted">Immagine disponibile dopo l’accesso</span>
        </div>
      <?php endif; ?>
    </div>

    <div class="event-hero-body card-body">
      <div class="tag-row">
        <span class="tag cardtag"><?= e(($evento['categoria'] ?? 'Evento')) ?></span>

        <?php if ((float)$evento['prezzo'] <= 0): ?>
          <span class="tag cardtag free">Gratis</span>
        <?php else: ?>
          <span class="tag cardtag book">€<?= e(number_format((float)$evento['prezzo'], 2, ',', '.')) ?></span>
        <?php endif; ?>

        <?php if ($pren_obbl && !$isInfo): ?>
          <span class="tag cardtag hot">Prenotazione</span>
        <?php endif; ?>

        <?php if ($isInfo): ?>
          <span class="tag cardtag">Info</span>
        <?php endif; ?>
      </div>

      <h1><?= e((string)$evento['titolo']) ?></h1>

      <p class="meta">
        <span class="pill"><?= e(date('d/m/Y H:i', strtotime((string)$evento['data_evento']))) ?></span>
        <span class="pill"><?= e((string)$evento['luogo']) ?></span>

        <?php if ($posti_residui !== null): ?>
          <span class="pill">Disponibili: <strong><?= (int)$posti_residui ?></strong> / <?= (int)$postiTot ?></span>
        <?php endif; ?>
      </p>

      <p class="desc"><?= e((string)$evento['descrizione_breve']) ?></p>

      <?php if (!$logged): ?>
        <div class="empty">
          <strong>Per vedere i dettagli completi accedi.</strong><br>
          Foto, descrizione completa e prenotazione sono disponibili per gli utenti registrati.
        </div>
        <a class="cta-login" href="<?= base_url('login.php') ?>">Accedi <small>per saperne di più</small></a>
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
          <p class="desc"><?= nl2br(e((string)$evento['descrizione_lunga'])) ?></p>
        <?php endif; ?>
      </div>
    </article>

    <!-- PRENOTAZIONE -->
    <aside class="card" aria-label="Box prenotazione">
      <div class="card-body">
        <h2>Prenota</h2>

        <?php if (!$logged): ?>
          <p class="muted">Accedi per prenotare.</p>
          <a class="cta-login" href="<?= base_url('login.php') ?>">Accedi <small>per prenotare</small></a>

        <?php else: ?>

          <?php if ($user_blocked): ?>
            <div class="alert alert-error" role="alert"><?= e($block_msg) ?></div>
            <p class="muted">Non puoi effettuare prenotazioni finché il blocco è attivo.</p>

          <?php elseif ($isInfo): ?>
            <p class="muted">Evento informativo: prenotazione non disponibile.</p>

          <?php elseif (!$prenotabile): ?>
            <p class="muted">Prenotazione non disponibile per questo evento.</p>
            <p class="desc">La prenotazione è prevista solo per eventi con posti limitati e costo.</p>

          <?php elseif ($posti_residui !== null && $posti_residui <= 0): ?>
            <div class="alert alert-error" role="alert">Evento sold-out.</div>

          <?php else: ?>
            <form id="bookingForm" class="auth-form" method="post" action="" novalidate>
              <div class="field">
                <label for="quantita">Numero biglietti (1–10)</label>
                <input id="quantita" name="quantita" type="number" min="1" max="10" required>
                <small class="hint" id="quantitaHint"></small>
              </div>

              <button type="submit" class="btn-primary w-100">
                Prenota • €<?= e(number_format((float)$evento['prezzo'], 2, ',', '.')) ?>
              </button>

              <?php if ($posti_residui !== null): ?>
                <p class="muted mt-8">Disponibili: <?= (int)$posti_residui ?></p>
              <?php endif; ?>
            </form>
          <?php endif; ?>

        <?php endif; ?>

      </div>
    </aside>

  </section>

</main>

<script src="<?= base_url('assets/js/evento.js') ?>"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>