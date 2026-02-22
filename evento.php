<?php
// ==============================================================================
// FILE: evento.php
// Dettaglio evento + prenotazione
//
// Scelte didattiche principali da evidenziare:
// - Logica di visibilità eventi:
//   • pubblicamente visibili se: approvati + non archiviati + futuri;
//   • se stato_evento = 'annullato', il dettaglio è comunque visibile.
// - Gestione eventi annullati:
//   • evento NON prenotabile;
//   • banner informativo "Evento annullato";
//   • se l'utente aveva prenotato, vede un messaggio dedicato.
// ==============================================================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Caricamento configurazione (connessione DB, base_url, ecc.) e avvio sessione (se non già attiva)
require_once __DIR__ . '/includes/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Connessione al database PostgreSQL tramite funzione helper
$conn = db_connect();

// Stato utente: uso variabili comode per sapere se è loggato e chi è
$logged  = isset($_SESSION['logged']) && $_SESSION['logged'] === true;
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

// Flash messages
// Vengono letti e poi rimossi dalla sessione per essere mostrati una sola volta
$flash_err = $_SESSION['flash_error']  ?? '';
$flash_ok  = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

/* =====================================================
   1) id evento (GET)
   - Recupero e validazione dell'ID passato via query string (?id=)
   - Se l'id non è numerico, reindirizzo alla lista eventi
     per evitare accessi non validi.
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
   - Gestione invio form di prenotazione (metodo POST).
   - Controlli in ordine:
     • login (se non loggato, redirect a login);
     • blocco utente (user_is_blocked);
     • quantità (valore numerico intero tra 1 e 10);
     • transazione con SELECT ... FOR UPDATE per lock riga evento;
     • controlli su stato evento e disponibilità posti;
     • inserimento prenotazione e aggiornamento posti_prenotati.
========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // Utente non loggato -> Redirect al login
  if (!$logged || $user_id === null) {
    $_SESSION['flash_error'] = "Devi accedere per prenotare.";
    db_close($conn);
    header("Location: " . base_url("login.php"));
    exit;
  }

  // Controllo blocco utente: se bloccato non può prenotare
  $block = user_is_blocked($conn, $user_id);
  if ($block['blocked']) {
    $_SESSION['flash_error'] = !empty($block['until'])
      ? ("Account bloccato fino al " . date('d/m/Y H:i', strtotime($block['until'])) . ".")
      : "Account bloccato. Non puoi prenotare.";
    db_close($conn);
    header("Location: " . base_url("evento.php?id=" . urlencode((string)$evento_id)));
    exit;
  }

  // Validazione quantità (deve essere un intero positivo)
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
  // Importante per evitare problemi di concorrenza su posti residui
  pg_query($conn, "BEGIN");

  // SELECT ... FOR UPDATE blocca la riga finché la transazione non termina
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
    // Se l'evento non rispetta i criteri, annullo transazione
    pg_query($conn, "ROLLBACK");
    $_SESSION['flash_error'] = "Evento non disponibile (non trovato, annullato, archiviato o già concluso).";
    db_close($conn);
    header("Location: " . base_url("eventi.php"));
    exit;
  }

  // Evento informativo -> Niente prenotazione (posti_totali non valorizzato)
  if ($ev['posti_totali'] === null || $ev['posti_totali'] === '') {
    pg_query($conn, "ROLLBACK");
    $_SESSION['flash_error'] = "Evento informativo: prenotazione non disponibile.";
    db_close($conn);
    header("Location: " . base_url("evento.php?id=" . urlencode((string)$evento_id)));
    exit;
  }

  $posti_totali = (int)$ev['posti_totali'];
  if ($posti_totali <= 0) {
    // Coerenza con il modello: se <= 0, niente prenotazioni
    pg_query($conn, "ROLLBACK");
    $_SESSION['flash_error'] = "Prenotazione non disponibile per questo evento.";
    db_close($conn);
    header("Location: " . base_url("evento.php?id=" . urlencode((string)$evento_id)));
    exit;
  }

  // Evento gratuito: in questo modello progettuale
  // la prenotazione è prevista solo per eventi a pagamento
  $prezzo = (float)$ev['prezzo'];
  if ($prezzo <= 0) {
    pg_query($conn, "ROLLBACK");
    $_SESSION['flash_error'] = "Evento gratuito: prenotazione non disponibile.";
    db_close($conn);
    header("Location: " . base_url("evento.php?id=" . urlencode((string)$evento_id)));
    exit;
  }

  // Controllo prenotazione già esistente per lo stesso utente/evento
  $sqlGia = "SELECT 1 FROM prenotazioni WHERE utente_id = $1 AND evento_id = $2 LIMIT 1;";
  $resGia = pg_query_params($conn, $sqlGia, [$user_id, $evento_id]);
  if ($resGia && pg_num_rows($resGia) > 0) {
    pg_query($conn, "ROLLBACK");
    $_SESSION['flash_error'] = "Hai già prenotato questo evento.";
    db_close($conn);
    header("Location: " . base_url("evento.php?id=" . urlencode((string)$evento_id)));
    exit;
  }

  // Totalizzazione delle prenotazioni esistenti (somma delle quantità)
  $sqlSum = "SELECT COALESCE(SUM(quantita), 0) AS prenotati FROM prenotazioni WHERE evento_id = $1;";
  $resSum = pg_query_params($conn, $sqlSum, [$evento_id]);
  $sumRow = $resSum ? pg_fetch_assoc($resSum) : ['prenotati' => 0];
  $prenotati = (int)($sumRow['prenotati'] ?? 0);

  // Calcolo dei posti ancora disponibili
  $disponibili = $posti_totali - $prenotati;
  if ($quantita > $disponibili) {
    pg_query($conn, "ROLLBACK");
    $_SESSION['flash_error'] = "Posti insufficienti. Disponibili: {$disponibili}.";
    db_close($conn);
    header("Location: " . base_url("evento.php?id=" . urlencode((string)$evento_id)));
    exit;
  }

  // Inserimento prenotazione (utente_id, evento_id, quantita)
  $sqlIns = "INSERT INTO prenotazioni (utente_id, evento_id, quantita) VALUES ($1, $2, $3);";
  $okIns  = pg_query_params($conn, $sqlIns, [$user_id, $evento_id, $quantita]);

  if (!$okIns) {
    // In caso di errore SQL, annullo la transazione
    pg_query($conn, "ROLLBACK");
    $_SESSION['flash_error'] = "Errore durante la prenotazione.";
    db_close($conn);
    header("Location: " . base_url("evento.php?id=" . urlencode((string)$evento_id)));
    exit;
  }

  // Chiusura transazione di prenotazione
  // Aggiorno il campo posti_prenotati in base alle prenotazioni presenti
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

  // COMMIT finale: rende effettive le operazioni
  pg_query($conn, "COMMIT");

  // Messaggio di successo per l'utente
  $_SESSION['flash_success'] = "Prenotazione effettuata!";
  db_close($conn);
  header("Location: " . base_url("evento.php?id=" . urlencode((string)$evento_id)));
  exit;
}

/* ==================================================
   3) Dettagli evento (GET)
   --------------------------------------------------
   - Recupero completo dei dati dell'evento, con join categoria.
   - Regole di visibilità:
     • pubblico se approvato / non archiviato / futuro;
     • altrimenti visibile solo all'utente che aveva una prenotazione.
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

// Se l'evento non esiste, torno alla lista eventi
if (!$evento) {
  db_close($conn);
  header("Location: " . base_url("eventi.php"));
  exit;
}

// Lettura degli stati principali per la logica di visibilità
$statoModerazione = (string)($evento['stato'] ?? 'in_attesa');   // in_attesa / approvato / rifiutato
$statoEvento      = (string)($evento['stato_evento'] ?? 'attivo'); // attivo / annullato
$archiviatoRaw    = $evento['archiviato'] ?? 'f';
$archiviato       = ($archiviatoRaw === 't' || $archiviatoRaw === true || $archiviatoRaw === '1');

// Controllo se la data dell'evento è futura o passata
$tsEvento = strtotime((string)($evento['data_evento'] ?? ''));
$nowTs    = time();
$isFuturo = ($tsEvento !== false && $tsEvento >= $nowTs);

// Evento pubblico se:
// - moderazione = approvato
// - non archiviato
// - data futura
$visibilePubblicamente =
  ($statoModerazione === 'approvato' &&
    !$archiviato &&
    $isFuturo);

// Flag per capire se l'utente corrente ha una prenotazione
$utentePrenotato = false;

// Se l'evento non è pubblico, verifico se l'utente loggato aveva prenotato
if (!$visibilePubblicamente) {
  if ($logged && $user_id !== null) {
    $sqlHas = "SELECT 1 FROM prenotazioni WHERE utente_id = $1 AND evento_id = $2 LIMIT 1;";
    $resHas = pg_query_params($conn, $sqlHas, [$user_id, $evento_id]);
    if ($resHas && pg_num_rows($resHas) === 1) {
      $utentePrenotato = true;
    }
  }

  // Se evento non pubblico e utente NON ha prenotato, nego l'accesso
  if (!$utentePrenotato) {
    db_close($conn);
    header("Location: " . base_url("eventi.php"));
    exit;
  }
}

// Titolo pagina dinamico basato sul titolo evento
$page_title    = "Evento - " . (string)$evento['titolo'];

// Evento informativo se posti_totali non impostato o vuoto
$isInfo        = ($evento['posti_totali'] === null || $evento['posti_totali'] === '');
$postiTot      = $isInfo ? null : (int)$evento['posti_totali'];
$posti_residui = null;

// Calcolo dei posti residui (se l'evento prevede un numero di posti)
if (!$isInfo && $postiTot !== null && $postiTot > 0) {
  $posti_residui = $postiTot - (int)($evento['posti_prenotati'] ?? 0);
  if ($posti_residui < 0) $posti_residui = 0;
}

// Prenotazione obbligatoria (campo booleano proveniente dal DB)
$pren_obbl = (
  ($evento['prenotazione_obbligatoria'] === 't') ||
  ($evento['prenotazione_obbligatoria'] === true) ||
  ($evento['prenotazione_obbligatoria'] === '1')
);

// Controllo blocco per la UI (solo per utenti loggati)
// Gestisco un messaggio ad hoc da mostrare nel box prenotazione
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

// Flag per stato annullato dell'evento (per banner e logica prenotazione)
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

// Chiusura della connessione al database (da qui in poi solo output HTML)
db_close($conn);

// Inclusione dell'header del sito (template comune)
require_once __DIR__ . '/includes/header.php';
?>

<main class="container">

  <nav class="breadcrumb" aria-label="Percorso">
    <a href="<?= base_url('eventi.php') ?>">← Torna agli eventi</a>
  </nav>

  <!-- ================================
       Messaggi flash (errori/successo)
       Mostrati se presenti in sessione
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

  <!--
      Sezione evento:
      - mostra immagine evento (se disponibile e utente loggato)
      - tag categoria, prezzo, stato (gratis, prenotazione, info, annullato)
      - titolo, data, luogo, breve descrizione
  -->
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
        <!-- Placeholder se l'utente non è loggato o non c'è immagine -->
        <div class="card-img" aria-hidden="true">
          <span class="muted">Immagine disponibile dopo l’accesso</span>
        </div>
      <?php endif; ?>
    </div>

    <div class="event-hero-body card-body">
      <div class="tag-row">
        <!-- Tag categoria -->
        <span class="tag cardtag"><?= e(($evento['categoria'] ?? 'Evento')) ?></span>

        <!-- Tag prezzo: Gratis o importo -->
        <?php if ((float)$evento['prezzo'] <= 0): ?>
          <span class="tag cardtag free">Gratis</span>
        <?php else: ?>
          <span class="tag cardtag book">€<?= e(number_format((float)$evento['prezzo'], 2, ',', '.')) ?></span>
        <?php endif; ?>

        <!-- Tag "Prenotazione" se obbligatoria, non informativo e non annullato -->
        <?php if ($pren_obbl && !$isInfo && !$isCancelled): ?>
          <span class="tag cardtag hot">Prenotazione</span>
        <?php endif; ?>

        <!-- Tag "Info" per eventi informativi -->
        <?php if ($isInfo): ?>
          <span class="tag cardtag">Info</span>
        <?php endif; ?>

        <!-- Tag "Annullato" se evento annullato -->
        <?php if ($isCancelled): ?>
          <span class="tag cardtag tag-annullato">Annullato</span>
        <?php endif; ?>
      </div>

      <!-- Titolo evento -->
      <h1><?= e((string)$evento['titolo']) ?></h1>

      <!-- Meta: data, luogo, posti disponibili -->
      <p class="meta">
        <span class="pill"><?= e(date('d/m/Y H:i', strtotime((string)$evento['data_evento']))) ?></span>
        <span class="pill"><?= e((string)$evento['luogo']) ?></span>

        <?php if ($posti_residui !== null): ?>
          <span class="pill">Disponibili: <strong><?= (int)$posti_residui ?></strong> / <?= (int)$postiTot ?></span>
        <?php endif; ?>
      </p>

      <!-- Descrizione breve -->
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

  <!--
      Layout principale:
      - colonna sinistra: descrizione estesa
      - colonna destra: box di prenotazione
  -->
  <section class="event-layout" aria-label="Dettagli e prenotazione">

    <article class="card">
      <div class="card-body">
        <h2>Dettagli</h2>

        <?php if (!$logged): ?>
          <p class="muted">Accedi per visualizzare la descrizione completa.</p>
        <?php else: ?>
          <!-- nl2br per mantenere i ritorni a capo inseriti nel testo -->
          <p class="desc"><?= nl2br(e((string)$evento['descrizione_lunga'])) ?></p>
        <?php endif; ?>
      </div>
    </article>

    <aside class="card" aria-label="Box prenotazione">
      <div class="card-body">
        <h2>Prenota</h2>

        <?php if (!$logged): ?>
          <!-- Se non loggato, invito all'accesso -->
          <p class="muted">Accedi per prenotare.</p>
          <a class="cta-login" href="<?= base_url('login.php') ?>">Accedi <small>per prenotare</small></a>

        <?php else: ?>

          <!-- Varie condizioni che possono bloccare o abilitare la prenotazione -->
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
            <!-- Caso generico: evento non prenotabile per condizioni di business -->
            <p class="muted">Prenotazione non disponibile per questo evento.</p>
            <p class="desc">La prenotazione è prevista solo per eventi con posti limitati e costo.</p>

          <?php elseif ($posti_residui !== null && $posti_residui <= 0): ?>
            <!-- Eventi sold-out -->
            <div class="alert alert-error" role="alert">Evento sold-out.</div>

            <!-- Form di prenotazione biglietti (ultimo caso: tutto ok) -->
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

<!-- Script JS dedicato alla pagina evento (validazioni, interazioni, ecc.) -->
<script src="<?= base_url('assets/js/evento.js') ?>"></script>

<?php
// Inclusione del footer del sito (layout comune)
require_once __DIR__ . '/includes/footer.php'; ?>