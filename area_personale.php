<?php
// =========================================================
// FILE: area_personale.php
// Scopo didattico:
// - Area riservata per utente loggato con ruolo "user" (non admin)
// - Gestione preferenze categorie (drag & drop + salvataggio ordine)
// - Visualizzazione prossimi eventi prenotati (mini riepilogo + countdown)
// - Countdown basato SOLO sul primo evento ATTIVO (non annullato)
// - Gestione eventi annullati: visibili, senza countdown, con badge "ANNULLATO"
// - Gestione cookie di preferenza vista (cards/list) per pannello eventi
// =========================================================

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/config.php'; // include già session_start()

// =========================================================
// GUARD: Solo user loggato (non admin)
// ---------------------------------------------------------
// - Se non loggato → redirect a login
// - Se ruolo diverso da 'user' → redirect a login
//   (separazione ruoli: l'admin non usa questa pagina)
// =========================================================
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

// Connessione al database per tutta la pagina
$conn = db_connect();

$flash_ok  = '';
$flash_err = '';

/* =========================================
   COOKIE PREFERENZA VISTA EVENTI (utente loggato)
   -----------------------------------------
   - ec_viewmode_<user_id> = 'cards' | 'list'
   - Questo cookie controlla solo l'aspetto grafico
     del pannello "I tuoi prossimi eventi".
   ========================================= */
$viewCookieName = 'ec_viewmode_' . $user_id;

// Lettura cookie (default "cards")
$view_mode = $_COOKIE[$viewCookieName] ?? 'cards';

// =========================================
// POST: Cambio sola vista (cards/list)
// -----------------------------------------
// Se arriva un POST con view_mode, aggiorno il cookie
// e faccio redirect (PRG) per evitare il resubmit.
// =========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['view_mode'])) {
  // Normalizzo il valore: solo 'list' o 'cards'
  $mode = ($_POST['view_mode'] === 'list') ? 'list' : 'cards'; // fallback 'cards'
  $expire = time() + (60 * 60 * 24 * 30); // 30 giorni

  // Cookie valido per tutto il sito
  setcookie($viewCookieName, $mode, $expire, '/');

  // PRG: evito il resubmit del form ricaricando la pagina
  header("Location: " . base_url('area_personale.php'));
  exit;
}

/* =========================================
   POST: Salvataggio preferenze (lista DESTRA)
   -----------------------------------------
   - ordine_preferite = "3,1,5"
   - Rappresenta la sequenza di categorie trascinate
     nella colonna "Preferite" e il loro ordine.
   ========================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ordine_preferite'])) {

  $ordine_raw = trim((string)($_POST['ordine_preferite'] ?? ''));

  // Può essere vuoto: significa nessuna preferenza salvata
  $ids = [];
  if ($ordine_raw !== '') {
    // Es: "3, 1, 5" → [ "3", "1", "5" ]
    $ids = array_values(array_filter(array_map('trim', explode(',', $ordine_raw))));
  }

  // Validazione: deve contenere solo numeri interi e nessun duplicato
  $seen  = [];
  $valid = true;

  foreach ($ids as $x) {
    if (!ctype_digit($x)) {
      $valid = false;
      break;
    }
    if (isset($seen[$x])) {
      // Evito doppioni nella lista preferite
      $valid = false;
      break;
    }
    $seen[$x] = true;
  }

  if (!$valid) {
    $flash_err = "Preferenze non valide.";
  } else {
    // Se ci sono ID, verifico che le categorie esistano nel DB
    if (count($ids) > 0) {
      $placeholders = [];
      $params       = [];

      foreach ($ids as $i => $catId) {
        $placeholders[] = '$' . ($i + 1);
        $params[]       = (int)$catId;
      }

      $in = implode(',', $placeholders);

      $resCheck = pg_query_params(
        $conn,
        "SELECT id FROM categorie WHERE id IN ($in);",
        $params
      );

      $found = [];
      if ($resCheck) {
        while ($r = pg_fetch_assoc($resCheck)) {
          $found[(int)$r['id']] = true;
        }
      }

      // Tutti gli ID passati devono esistere in categorie
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
      // Transazione: prima svuoto le preferenze dell'utente,
      // poi reinserisco quelle nuove con l'ordine aggiornato.
      pg_query($conn, "BEGIN");

      $okDel = pg_query_params(
        $conn,
        "DELETE FROM preferenze_utente WHERE utente_id = $1;",
        [$user_id]
      );

      $okIns = true;
      $sqlIns = "INSERT INTO preferenze_utente (utente_id, categoria_id, ordine)
                 VALUES ($1, $2, $3);";

      foreach ($ids as $index => $catId) {
        $resIns = pg_query_params($conn, $sqlIns, [
          $user_id,
          (int)$catId,
          $index + 1
        ]);
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
   -----------------------------------------
   - Recupero tutte le categorie
   - Se esiste una preferenza per l'utente (ordine non NULL),
     finisce nell'elenco "preferite", altrimenti in "disponibili".
   ========================================= */
$preferite   = [];
$disponibili = [];

// Query unica con LEFT JOIN su preferenze_utente
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
      // categorie già in lista preferite
      $tmpPref[] = $row;
    } else {
      // categorie ancora disponibili
      $disponibili[] = $row;
    }
  }
  // Ordino le preferite secondo il campo "ordine"
  usort($tmpPref, function ($a, $b) {
    return (int)$a['ordine'] <=> (int)$b['ordine'];
  });
  $preferite = $tmpPref;
} else {
  $flash_err = "Errore caricamento categorie: " . pg_last_error($conn);
}

/* =========================================
   GET: Miei eventi futuri prenotati
   -----------------------------------------
   - Piccolo riepilogo dei prossimi eventi
     per dare contesto all'utente.
   - Ordine cronologico crescente.
   - Includo anche eventi annullati, ma:
     • niente countdown
     • badge "ANNULLATO" per chiarezza UX
   ========================================= */
$miei_eventi = [];

$sqlMy = "
  SELECT
    e.id           AS evento_id,
    e.titolo       AS titolo,
    e.luogo        AS luogo,
    e.data_evento,
    e.stato_evento,
    e.archiviato,
    p.quantita
  FROM prenotazioni p
  JOIN eventi e ON e.id = p.evento_id
  WHERE p.utente_id = $1
    AND e.stato = 'approvato'
    AND e.archiviato = FALSE
    AND e.data_evento >= NOW()
  ORDER BY e.data_evento ASC
  LIMIT 20;
";
$resMy = pg_query_params($conn, $sqlMy, [$user_id]);
if ($resMy) {
  while ($row = pg_fetch_assoc($resMy)) {
    $miei_eventi[] = $row;
  }
}

// Ho finito le query, posso chiudere la connessione
db_close($conn);

$page_title = "Area personale - EnjoyCity";
?>

<?php require_once __DIR__ . '/includes/header.php'; ?>

<main>
  <div class="container">

    <!-- Intestazione area personale -->
    <header class="section-title">
      <div>
        <h2>Area personale</h2>
        <p class="muted">
          Gestisci prenotazioni e preferenze (trascina a destra ciò che ti interessa).
        </p>
      </div>

      <div class="section-actions">
        <a class="btn" href="<?= base_url('eventi.php') ?>">Esplora eventi</a>
      </div>
    </header>

    <!-- Messaggi di feedback (successo/errore) -->
    <?php if ($flash_ok !== ''): ?>
      <div class="alert alert-success" role="status"><?= e($flash_ok) ?></div>
    <?php endif; ?>

    <?php if ($flash_err !== ''): ?>
      <div class="alert alert-error" role="alert"><?= e($flash_err) ?></div>
    <?php endif; ?>

    <section class="area-grid" aria-label="Pannelli area personale">

      <!-- =====================================================
           PANNELLO EVENTI
           -----------------------------------------------------
           area-events-<?= e($view_mode) ?> permette di cambiare layout
           (cards / list) solo con il CSS, in base al cookie.
           ===================================================== -->
      <article class="card area-events area-events-<?= e($view_mode) ?>">
        <div class="card-body">
          <h3>🎫 I tuoi prossimi eventi</h3>

          <?php if (count($miei_eventi) === 0): ?>
            <p class="muted">Non hai prenotazioni attive.</p>
            <a class="cta-login" href="<?= base_url('eventi.php') ?>">
              Cerca eventi <small>e prenota</small>
            </a>

          <?php else: ?>

            <?php
            // -------------------------------------------------
            // Seleziono il PRIMO evento ATTIVO per il countdown
            // (se tutti sono annullati, non mostro il countdown)
            // -------------------------------------------------
            $firstActive = null;
            foreach ($miei_eventi as $ev) {
              $statoEv = (string)($ev['stato_evento'] ?? 'attivo');
              if ($statoEv === 'attivo') {
                $firstActive = $ev;
                break;
              }
            }
            ?>

            <?php if ($firstActive !== null): ?>
              <?php
              $firstISO   = date('c', strtotime((string)$firstActive['data_evento']));
              $statoFirst = (string)($firstActive['stato_evento'] ?? 'attivo');
              ?>
              <div class="countdown-box">
                <div class="tag-row">
                  <span class="tag cardtag hot">Countdown</span>
                  <span class="tag cardtag">
                    <?= e(fmt_datetime($firstActive['data_evento'])) ?>
                  </span>
                </div>
                <strong><?= e($firstActive['titolo']) ?></strong>
                <p class="muted"><?= e($firstActive['luogo']) ?></p>

                <!-- Data in formato ISO per il countdown JS (area_personale.js) -->
                <p class="countdown" data-countdown="<?= e($firstISO) ?>">Calcolo…</p>
              </div>
            <?php else: ?>
              <!-- Nessun evento attivo: alcuni potrebbero essere annullati -->
              <div class="countdown-box">
                <div class="tag-row">
                  <span class="tag cardtag hot">Nessun evento attivo</span>
                </div>
                <p class="muted">
                  Al momento non ci sono eventi attivi con countdown.
                  Controlla comunque la lista delle tue prenotazioni qui sotto.
                </p>
              </div>
            <?php endif; ?>

            <!-- Lista di TUTTI gli eventi prenotati futuri (attivi + annullati) -->
            <ul class="my-list" aria-label="Lista prenotazioni">
              <?php foreach ($miei_eventi as $ev): ?>
                <?php
                $evId    = (int)$ev['evento_id'];
                $statoEv = (string)($ev['stato_evento'] ?? 'attivo');
                ?>
                <li class="my-item">
                  <div>
                    <strong><?= e($ev['titolo']) ?></strong>
                    <div class="meta">
                      <span class="pill"><?= e(fmt_datetime($ev['data_evento'])) ?></span>
                      <span class="pill"><?= e($ev['luogo']) ?></span>
                      <span class="pill">Biglietti: <?= (int)$ev['quantita'] ?></span>

                      <?php if ($statoEv === 'annullato'): ?>
                        <!-- Stesso stile badge usato in dashboard (.pill.hot) -->
                        <span class="pill hot">ANNULLATO</span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <a class="btn" href="<?= base_url('evento.php?id=' . $evId) ?>">Apri</a>
                </li>
              <?php endforeach; ?>
            </ul>

          <?php endif; ?>
        </div>
      </article>

      <!-- =====================================================
           PANNELLO PREFERENZE (2 colonne: disponibili / preferite)
           -------------------------------------------------------
           - Drag & drop gestito da assets/js/area_personale.js
           - Il form invia ordine_preferite come stringa "id1,id2,id3".
           ===================================================== -->
      <article class="card">
        <div class="card-body">
          <h3>❤️ Le tue preferenze</h3>
          <p class="muted">
            Trascina le categorie a destra per selezionarle.
            Riordina le preferite trascinando in alto/basso.
          </p>

          <form method="post" id="pref-form"
            action="<?= base_url('area_personale.php') ?>" novalidate>

            <div class="pref-columns" aria-label="Seleziona categorie preferite">

              <!-- Sinistra: categorie disponibili -->
              <div class="pref-col">
                <h4 class="pref-title">Disponibili</h4>
                <ul id="list-disponibili" class="dropzone" aria-label="Categorie disponibili">
                  <?php if (count($disponibili) === 0): ?>
                    <li class="drop-empty">Nessuna categoria disponibile.</li>
                  <?php else: ?>
                    <?php foreach ($disponibili as $cat): ?>
                      <li class="sortable-item" draggable="true"
                        data-id="<?= (int)$cat['id'] ?>">
                        <span><?= e($cat['nome']) ?></span>
                        <span aria-hidden="true">⇄</span>
                      </li>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </ul>
              </div>

              <!-- Destra: categorie preferite -->
              <div class="pref-col">
                <h4 class="pref-title">Preferite</h4>
                <ul id="list-preferite" class="dropzone preferite"
                  aria-label="Categorie preferite">
                  <?php if (count($preferite) === 0): ?>
                    <li class="drop-empty">
                      Trascina qui le categorie che ti interessano.
                    </li>
                  <?php else: ?>
                    <?php foreach ($preferite as $cat): ?>
                      <li class="sortable-item" draggable="true"
                        data-id="<?= (int)$cat['id'] ?>">
                        <span><?= e($cat['nome']) ?></span>
                        <span aria-hidden="true">☰</span>
                      </li>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </ul>
              </div>

            </div>

            <!-- Hidden: conterrà l'ordine degli ID preferiti (es. "3,1,5") -->
            <input type="hidden" name="ordine_preferite"
              id="ordine_input" value="">

            <button type="submit"
              class="btn-search pref-save"
              id="savePrefsBtn">
              Salva preferenze
            </button>
          </form>

          <p class="muted pref-hint">
            Suggerimento: metti 3–5 categorie per avere consigli più precisi.
          </p>
        </div>
      </article>

    </section>

  </div>
</main>

<!-- JS dedicato all'area personale:
     - drag & drop categorie
     - aggiornamento hidden ordine_preferite
     - gestione countdown evento più vicino (solo eventi attivi) -->
<script src="<?= base_url('assets/js/area_personale.js') ?>"></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>