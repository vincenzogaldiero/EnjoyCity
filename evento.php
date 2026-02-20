<?php
// ==============================================================================
// FILE: evento.php
// Dettaglio evento + prenotazione
// Scelte didattiche:
// - Eventi pubblicamente visibili se: approvati + non archiviati + futuri
//   (anche se stato_evento = 'annullato', l'utente vede il dettaglio)
// - Se l'evento è annullato:
//   • è SEMPRE non prenotabile;
//   • viene mostrato un banner "Evento annullato";
//   • se l'utente aveva una prenotazione, visualizzerà un messaggio di notifica.
// ==============================================================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Caricamento configurazione e avvio sessione (se non già attiva)
require_once __DIR__ . '/includes/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Connessione al database
$conn = db_connect();

// Stato utente
$logged  = isset($_SESSION['logged']) && $_SESSION['logged'] === true;
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

// Flash messages (PRG)
$flash_err = $_SESSION['flash_error']  ?? '';
$flash_ok  = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

/* =====================================================
   1) id evento (GET)
   -  Validazione dell'ID passato via query string
   -  Se id non valido, si è rediretti alla lista eventi  
======================================================*/
$id = $_GET['id'] ?? '';
if (!ctype_digit((string)$id)) {
  db_close($conn);
  header("Location: eventi.php");
  exit;
}
$evento_id = (int)$id;

/* ==========================================================
   2) prenotazione (POST)
   - Gestione invio form di prenotazione
   - Controlli: login, blocco utente, quantità, disponibilità
   - Lock ottimistico tramite SELECT...FOR UPDATE
========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  
  // Utente non loggato -> Redirect al login
  if (!$logged || $user_id === null) {
    $_SESSION['flash_error'] = "Devi accedere per prenotare.";
    db_close($conn);
    header("Location: " . base_url("login.php"));
    exit;
  }
  
  // Controllo blocco utente
  $block = user_is_blocked($conn, $user_id);
  if ($block['blocked']) {
    $_SESSION['flash_error'] = !empty($block['until'])
      ? ("Account bloccato fino al " . date('d/m/Y H:i', strtotime($block['until'])) . ".")
      : "Account bloccato. Non puoi prenotare.";
    db_close($conn);
    header("Location: " . base_url("evento.php?id=" . urlencode((string)$evento_id)));
    exit;
  }
  
  // Validazione quantità
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
  
  // Inizio transazione di prenotazione
  pg_query($conn, "BEGIN");

  // Lock riga evento, SOLO se ancora prenotabile a livello lifecycle
  $sqlEv = "
      SELECT id, prezzo, posti_totali, prenotazione_obbligatoria,
             data_evento, stato, archiviato, stato_evento
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
  
  // Evento informativo -> Niente prenotazione
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
  
  // Evento gratuito, non serve la prenotazione
  $prezzo = (float)$ev['prezzo'];
  if ($prezzo <= 0) {
    pg_query($conn, "ROLLBACK");
    $_SESSION['flash_error'] = "Evento gratuito: prenotazione non disponibile.";
    db_close($conn);
    header("Location: " . base_url("evento.php?id=" . urlencode((string)$evento_id)));
    exit;
  }
  
  // Controllo prenotazione già esistente
  $sqlGia = "SELECT 1 FROM prenotazioni WHERE utente_id = $1 AND evento_id = $2 LIMIT 1;";
  $resGia = pg_query_params($conn, $sqlGia, [$user_id, $evento_id]);
  if ($resGia && pg_num_rows($resGia) > 0) {
    pg_query($conn, "ROLLBACK");
    $_SESSION['flash_error'] = "Hai già prenotato questo evento.";
    db_close($conn);
    header("Location: " . base_url("evento.php?id=" . urlencode((string)$evento_id)));
    exit;
  }
  
  // Totalizzazione delle prenotazioni esistenti
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
  
  // Inserimento prenotazione
  $sqlIns = "INSERT INTO prenotazioni (utente_id, evento_id, quantita) VALUES ($1, $2, $3);";
  $okIns  = pg_query_params($conn, $sqlIns, [$user_id, $evento_id, $quantita]);

  if (!$okIns) {
    pg_query($conn, "ROLLBACK");
    $_SESSION['flash_error'] = "Errore durante la prenotazione.";
    db_close($conn);
    header("Location: " . base_url("evento.php?id=" . urlencode((string)$evento_id)));
    exit;
  }
  
  // Chiusura transazione di prenotazione
  $sqlUpd = "
      UPDATE eventi
      SET posti_prenotati = (
          SELECT COALESCE(SUM(quantita), 0)
          FROM prenotazioni
          WHERE evento_id = $1
      )
      WHERE id = $1;
    ";
  pg_query_params($conn, $sqlUpd, [$evento_id]);

  pg_query($conn, "COMMIT");

  $_SESSION['flash_success'] = "Prenotazione effettuata!";
  db_close($conn);
  header("Location: " . base_url("evento.php?id=" . urlencode((string)$evento_id)));
  exit;
}

/* ==================================================
   3) Dettagli evento (GET)
   - pubblico se approvato / non archiviato / futuro;
   - se archiviato / non approvato / passato:
     • visibile solo all' utente che aveva prenotato
================================================== */
$sql = "
  SELECT e.*, c.nome AS categoria
  FROM eventi e
  LEFT JOIN categorie c ON c.id = e.categoria_id
  WHERE e.id = $1
  LIMIT 1;
";
$res    = pg_query_params($conn, $sql, [$evento_id]);
$evento = $res ? pg_fetch_assoc($res) : null;

if (!$evento) {
  db_close($conn);
  header("Location: " . base_url("eventi.php"));
  exit;
}

$statoModerazione = (string)($evento['stato'] ?? 'in_attesa');
$statoEvento      = (string)($evento['stato_evento'] ?? 'attivo');
$archiviatoRaw    = $evento['archiviato'] ?? 'f';
$archiviato       = ($archiviatoRaw === 't' || $archiviatoRaw === true || $archiviatoRaw === '1');

$tsEvento = strtotime((string)($evento['data_evento'] ?? ''));
$nowTs    = time();
$isFuturo = ($tsEvento !== false && $tsEvento >= $nowTs);

// Evento pubblico se approvato, non archiviato e futuro
$visibilePubblicamente =
  ($statoModerazione === 'approvato' &&
    !$archiviato &&
    $isFuturo);

$utentePrenotato = false;

if (!$visibilePubblicamente) {
  if ($logged && $user_id !== null) {
    $sqlHas = "SELECT 1 FROM prenotazioni WHERE utente_id = $1 AND evento_id = $2 LIMIT 1;";
    $resHas = pg_query_params($conn, $sqlHas, [$user_id, $evento_id]);
    if ($resHas && pg_num_rows($resHas) === 1) {
      $utentePrenotato = true;
    }
  }

  if (!$utentePrenotato) {
    db_close($conn);
    header("Location: " . base_url("eventi.php"));
    exit;
  }
}

$page_title    = "Evento - " . (string)$evento['titolo'];
$isInfo        = ($evento['posti_totali'] === null || $evento['posti_totali'] === '');
$postiTot      = $isInfo ? null : (int)$evento['posti_totali'];
$posti_residui = null;

if (!$isInfo && $postiTot !== null && $postiTot > 0) {
  $posti_residui = $postiTot - (int)($evento['posti_prenotati'] ?? 0);
  if ($posti_residui < 0) $posti_residui = 0;
}

$pren_obbl = (
  ($evento['prenotazione_obbligatoria'] === 't') ||
  ($evento['prenotazione_obbligatoria'] === true) ||
  ($evento['prenotazione_obbligatoria'] === '1')
);

// Controllo blocco per UI (solo loggato)
$user_blocked = false;
$block_msg    = '';
if ($logged && $user_id !== null) {
  $block = user_is_blocked($conn, $user_id);
  if ($block['blocked']) {
    $user_blocked = true;
    $block_msg = !empty($block['until'])
      ? "Il tuo account è bloccato fino al " . date('d/m/Y H:i', strtotime($block['until'])) . "."
      : "Il tuo account è stato bloccato dall’amministratore.";
  }
}

$isCancelled = ($statoEvento === 'annullato');

// prenotazione consentita solo se:
// - evento NON informativo
// - posti > 0
// - prezzo > 0
// - evento NON annullato
// - evento ancora pubblico (approvato + non archiviato + futuro)
$prenotabile = (
  !$isInfo &&
  $postiTot !== null &&
  $postiTot > 0 &&
  (float)$evento['prezzo'] > 0 &&
  !$isCancelled &&
  $visibilePubblicamente
);

// Chiusura della connessione al database
db_close($conn);

// Inclusione dell'header del sito
require_once __DIR__ . '/includes/header.php';
?>

<main class="container">

  <nav class="breadcrumb" aria-label="Percorso">
    <a href="<?= base_url('eventi.php') ?>">← Torna agli eventi</a>
  </nav>
  
  <!-- ================================
       Messaggi flash (errori/successo)
  ================================= -->
  <?php if ($flash_err): ?>
    <div class="alert alert-error" role="alert"><?= e($flash_err) ?></div>
  <?php endif; ?>

  <?php if ($flash_ok): ?>
    <div class="alert alert-success" role="status"><?= e($flash_ok) ?></div>
  <?php endif; ?>
  
  <!-- Banner evento annullato -->
  <?php if ($isCancelled): ?>
    <div class="alert alert-error" role="alert">
      Questo evento è stato <strong>annullato</strong> dall'organizzazione.
      <?php if ($utentePrenotato): ?>
        Se avevi effettuato una prenotazione, considera il tuo biglietto non più valido.
      <?php endif; ?>
    </div>
  <?php endif; ?>
  
  <!-- Sezione HERO: immagine evento + tag + informazioni principali -->
  <section class="event-hero card">
    <div class="event-hero-media">
      <?php if ($logged && !empty($evento['immagine'])): ?>
        <div class="card-img">
          <div class="img-tags">
            <span class="tag-overlay hot"><?= e(($evento['categoria'] ?? 'Evento')) ?></span>
            <?php if ($isCancelled): ?>
              <span class="tag-overlay tag-annullato">Annullato</span>
            <?php endif; ?>
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

        <?php if ($pren_obbl && !$isInfo && !$isCancelled): ?>
          <span class="tag cardtag hot">Prenotazione</span>
        <?php endif; ?>

        <?php if ($isInfo): ?>
          <span class="tag cardtag">Info</span>
        <?php endif; ?>

        <?php if ($isCancelled): ?>
          <span class="tag cardtag tag-annullato">Annullato</span>
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
      
      <!-- Avviso per utenti non autenticati -->
      <?php if (!$logged): ?>
        <div class="empty">
          <strong>Per vedere i dettagli completi accedi.</strong><br>
          Foto, descrizione completa e prenotazione sono disponibili per gli utenti registrati.
        </div>
        <a class="cta-login" href="<?= base_url('login.php') ?>">Accedi <small>per saperne di più</small></a>
      <?php endif; ?>
    </div>
  </section>
  
  <!-- Layout principale: descrizione estesa + box prenotazione -->
  <section class="event-layout" aria-label="Dettagli e prenotazione">

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

    <aside class="card" aria-label="Box prenotazione">
      <div class="card-body">
        <h2>Prenota</h2>

        <?php if (!$logged): ?>
          <p class="muted">Accedi per prenotare.</p>
          <a class="cta-login" href="<?= base_url('login.php') ?>">Accedi <small>per prenotare</small></a>

        <?php else: ?>
          
          <!-- Logica di accesso alla prenotazione (blocco, annullato, info, sold-out, ecc.) -->
          <?php if ($user_blocked): ?>
            <div class="alert alert-error" role="alert"><?= e($block_msg) ?></div>
            <p class="muted">Non puoi effettuare prenotazioni finché il blocco è attivo.</p>

          <?php elseif ($isCancelled): ?>
            <p class="muted">
              Questo evento è stato annullato e non è più prenotabile.
            </p>

          <?php elseif ($isInfo): ?>
            <p class="muted">Evento informativo: prenotazione non disponibile.</p>

          <?php elseif (!$prenotabile): ?>
            <p class="muted">Prenotazione non disponibile per questo evento.</p>
            <p class="desc">La prenotazione è prevista solo per eventi con posti limitati e costo.</p>

          <?php elseif ($posti_residui !== null && $posti_residui <= 0): ?>
            <div class="alert alert-error" role="alert">Evento sold-out.</div>
          
          <!-- Form di prenotazione biglietti -->
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

<!-- Script dedicato alla pagina evento -->
<script src="<?= base_url('assets/js/evento.js') ?>"></script>

<?php 
// Inclusione del footer del sito
require_once __DIR__ . '/includes/footer.php'; ?>