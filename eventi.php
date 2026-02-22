<?php
// ================================================================
// FILE: eventi.php
// Lista eventi futuri (pagina pubblica, con extra per loggati)
//
// Ruolo nel progetto:
// - È la pagina indice degli eventi, visibile anche ai non loggati.
// - Implementa filtri di ricerca, categorie, ordinamento, preferenze,
//   e geolocalizzazione.
//
// Regole di visibilità in LISTA:
// - Mostra eventi FUTURI
// - stato = 'approvato'
// - archiviato = FALSE
// - stato_evento può essere 'attivo' oppure 'annullato'
//   (gli annullati sono visibili in lista ma non prenotabili dal dettaglio)
//
// ================================================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Caricamento configurazione e avvio sessione (se non già attiva)
require_once __DIR__ . '/includes/config.php';
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// Stato utente
// - $logged: true/false
// - $user_id: id dell'utente loggato, 0 se anonimo
$logged  = isset($_SESSION['logged']) && $_SESSION['logged'] === true;
$user_id = ($logged && isset($_SESSION['user_id'])) ? (int)$_SESSION['user_id'] : 0;

// Connessione al database PostgreSQL
$conn = db_connect();

// --------------------------------------------------------------
// Filtri GET: ricerca, categoria, ordinamento, geolocalizzazione
// --------------------------------------------------------------
// Ricerca testuale (titolo, luogo, descrizione breve)
$q = trim((string)($_GET['q'] ?? ''));

// Filtro per categoria (id intero)
$categoria    = (string)($_GET['categoria'] ?? '');
$categoria_id = null;
if ($categoria !== '' && ctype_digit($categoria)) {
  $categoria_id = (int)$categoria;
}

// Ordinamento (solo utenti loggati possono scegliere)
// Opzioni: data | prezzo | vicino
$ordine        = (string)($_GET['ordine'] ?? 'data'); // default = data
$ordine_valido = in_array($ordine, ['data', 'prezzo', 'vicino'], true) ? $ordine : 'data';

// Se non loggato, forziamo SEMPRE 'data' (ordinamento base cronologico)
if (!$logged) {
  $ordine_valido = 'data';
}

// Coordinate GPS (solo loggati e se ordine = vicino)
// Vengono di solito impostate via JavaScript (navigator.geolocation)
$lat = isset($_GET['lat']) && $_GET['lat'] !== '' ? (float)$_GET['lat'] : null;
$lon = isset($_GET['lon']) && $_GET['lon'] !== '' ? (float)$_GET['lon'] : null;

// Validazione geolocalizzazione
// - attiva solo se: utente loggato, ordine = 'vicino', coordinate nel range valido
$geo_ok = (
  $logged &&
  $ordine_valido === 'vicino' &&
  $lat !== null && $lon !== null &&
  $lat >= -90 && $lat <= 90 &&
  $lon >= -180 && $lon <= 180
);

// -----------------------------------
// Caricamento categorie per il filtro
// -----------------------------------
// Query semplice per popolare la <select> delle categorie
$categorie = [];
$resCat = pg_query($conn, "SELECT id, nome FROM categorie ORDER BY nome;");
if ($resCat) {
  while ($row = pg_fetch_assoc($resCat)) {
    $categorie[] = $row;
  }
}

// ----------------------------------
// Preferenze dei soli utenti loggati
// ----------------------------------
// prefMap: mappa categoria_id => ordine (1,2,3,...)
// prefIds: array dei soli id categoria ordinati per preferenza
$prefMap = []; // categoria_id => ordine
$prefIds = []; // array ordinato di categoria_id

if ($logged && $user_id > 0) {
  $resPref = pg_query_params(
    $conn,
    "SELECT categoria_id, ordine
         FROM preferenze_utente
         WHERE utente_id = $1
         ORDER BY ordine ASC;",
    [$user_id]
  );
  if ($resPref) {
    while ($r = pg_fetch_assoc($resPref)) {
      $cid = (int)$r['categoria_id'];
      $ord = (int)$r['ordine'];
      $prefMap[$cid] = $ord;
      $prefIds[]     = $cid;
    }
  }
}

// ----------------------------------------------------------------
// Costruzione WHERE base: eventi futuri, approvati, non archiviati
// (stato_evento può essere 'attivo' o 'annullato')
// ----------------------------------------------------------------
// Uso array $where + $params per costruire una query flessibile
$where  = [];
$params = [];
$idx    = 1;

$where[] = "e.stato = 'approvato'";
$where[] = "e.archiviato = FALSE";
$where[] = "e.data_evento >= NOW()";

// Filtro per ricerca testuale:
// match su titolo, luogo o descrizione breve (ILIKE = case-insensitive)
if ($q !== '') {
  $where[] = "(e.titolo ILIKE $" . $idx .
    " OR e.luogo ILIKE $" . $idx .
    " OR e.descrizione_breve ILIKE $" . $idx . ")";
  $params[] = '%' . $q . '%';
  $idx++;
}

// Filtro per categoria
if ($categoria_id !== null) {
  $where[]  = "e.categoria_id = $" . $idx;
  $params[] = $categoria_id;
  $idx++;
}

// -------------------------------------------------------------------
// pref_sort: prima le categorie preferite (ordine 1,2,3...), altrimenti 9999
// -------------------------------------------------------------------
// pref_sort serve per dare priorità agli eventi delle categorie preferite
// dell'utente (card 'Per te' mostrate per prime).
$prefSortSql = "9999 AS pref_sort";

if ($logged && count($prefIds) > 0) {
  $case = "CASE";
  // Costruisco un CASE dinamico: WHEN e.categoria_id = $n THEN <ordine>
  foreach ($prefIds as $cid) {
    $case    .= " WHEN e.categoria_id = $" . $idx . " THEN " . (int)$prefMap[$cid];
    $params[] = $cid;
    $idx++;
  }
  $case        .= " ELSE 9999 END";
  $prefSortSql  = "$case AS pref_sort";
}

// -----------------------------------------------------------
// Calcolo della distanza, solo se la geolocalizzazione è valida
// -----------------------------------------------------------
// Se geo_ok è true, aggiungo alla SELECT un campo distanza_km
// calcolato con una formula trigonometrica (approssimazione sferica).
$distanceSelect = "NULL::double precision AS distanza_km";

if ($geo_ok) {
  $latParam = "$" . $idx;
  $params[] = $lat;
  $idx++;

  $lonParam = "$" . $idx;
  $params[] = $lon;
  $idx++;

  $distanceSelect = "(
        CASE
          WHEN e.latitudine IS NULL OR e.longitudine IS NULL THEN NULL
          ELSE (
            6371 * acos(
              cos(radians($latParam)) * cos(radians(e.latitudine)) *
              cos(radians(e.longitudine) - radians($lonParam)) +
              sin(radians($latParam)) * sin(radians(e.latitudine))
            )
          )
        END
    ) AS distanza_km";
}

// ----------------------------------
// Order By (data, prezzo o distanza)
// ----------------------------------
// Ordinamento di default: per data_evento crescente
$orderSql = "e.data_evento ASC";

// Se loggato e scelto "prezzo", ordino per prezzo (null per ultimi)
if ($logged && $ordine_valido === 'prezzo') {
  $orderSql = "e.prezzo ASC NULLS LAST, e.data_evento ASC";
} elseif ($geo_ok) {
  // Se ho geolocalizzazione valida e ordine = "vicino",
  // ordino per distanza crescente e poi per data
  $orderSql = "distanza_km ASC NULLS LAST, e.data_evento ASC";
}

// ----------------------------------------------
// Query finale con filtri, preferenze e distanza
// ----------------------------------------------
// SELECT principale della lista eventi:
// - unisce eventi e categorie
// - include pref_sort (per pref. utente) e distanza_km (se geo attiva)
// - applica filtri dinamici su WHERE
// - ordina prima per pref_sort, poi per criterio scelto, infine per id
$sql = "
    SELECT
        e.id,
        e.titolo,
        e.descrizione_breve,
        e.data_evento,
        e.luogo,
        e.immagine,
        e.prezzo,
        e.posti_totali,
        e.posti_prenotati,
        e.prenotazione_obbligatoria,
        e.categoria_id,
        e.stato_evento,
        c.nome AS categoria,
        $prefSortSql,
        $distanceSelect
    FROM eventi e
    LEFT JOIN categorie c ON c.id = e.categoria_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY pref_sort ASC, $orderSql, e.id ASC
    LIMIT 60;
";

$res = pg_query_params($conn, $sql, $params);
if (!$res) {
  // In caso di errore query, chiudo connessione e mostro messaggio tecnico
  db_close($conn);
  die("Errore query eventi: " . pg_last_error($conn));
}

// Metadato per il <title> della pagina
$page_title = "Eventi - EnjoyCity";
// Inclusione dell'header comune del sito
require_once __DIR__ . '/includes/header.php';
?>

<main>
  <div class="container">

    <!-- Intestazione pagina eventi -->
    <header class="section-title eventi-head">
      <div>
        <h2>Eventi</h2>
        <p class="muted">
          <?= $logged
            ? "Eventi futuri. Le tue categorie preferite sono in evidenza e compaiono per prime."
            : "Eventi futuri. Accedi per vedere dettagli e prenotare." ?>
        </p>
      </div>

      <!-- CTA contestuale: dashboard se loggato, login se anonimo -->
      <a class="btn" href="<?= $logged ? base_url('dashboard.php') : base_url('login.php') ?>">
        <?= $logged ? "Vai alla dashboard" : "Accedi" ?>
      </a>
    </header>

    <!-- Sezione filtri di ricerca -->
    <section class="search-card" aria-label="Filtra eventi">
      <form method="get"
        action="<?= base_url('eventi.php') ?>"
        class="search-grid"
        id="eventiFilterForm">

        <!-- Ricerca testuale (q) -->
        <input
          class="input wide"
          type="text"
          name="q"
          placeholder="Cerca per titolo, luogo, descrizione…"
          value="<?= e($q) ?>"
          aria-label="Cerca eventi">

        <!-- Filtro categoria: popolato dinamicamente da tabella categorie -->
        <select name="categoria" aria-label="Categoria">
          <option value="">Tutte le categorie</option>
          <?php foreach ($categorie as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= ($categoria_id === (int)$c['id']) ? 'selected' : '' ?>>
              <?= e($c['nome']) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <!-- Filtri avanzati (solo utenti loggati) -->
        <?php if ($logged): ?>
          <select name="ordine" aria-label="Ordina" id="ordineSelect">
            <option value="data" <?= $ordine_valido === 'data'   ? 'selected' : '' ?>>Più imminenti</option>
            <option value="prezzo" <?= $ordine_valido === 'prezzo' ? 'selected' : '' ?>>Prezzo (crescente)</option>
            <option value="vicino" <?= $ordine_valido === 'vicino' ? 'selected' : '' ?>>Vicino a me</option>
          </select>

          <!-- Coordinate GPS (riempite via JS se l’utente sceglie "Vicino a me") -->
          <input type="hidden" name="lat" id="geo-lat" value="<?= $lat !== null ? e((string)$lat) : '' ?>">
          <input type="hidden" name="lon" id="geo-lon" value="<?= $lon !== null ? e((string)$lon) : '' ?>">

          <!-- Bottone per ottenere la posizione via HTML5 Geolocation API -->
          <button class="btn-search btn-geo" type="button" id="btn-vicino">Vicino a me</button>
        <?php endif; ?>

        <!-- Submit filtri -->
        <button class="btn-search" type="submit">Filtra</button>

        <!-- Reset filtri (mostrato solo se almeno un filtro è attivo) -->
        <?php if ($q !== '' || $categoria_id !== null || ($logged && $ordine_valido !== 'data')): ?>
          <a class="btn-search btn-reset" href="<?= base_url('eventi.php') ?>">Reset</a>
        <?php endif; ?>

      </form>
    </section>

    <!-- Nessun risultato trovato con i filtri -->
    <?php if (pg_num_rows($res) === 0): ?>
      <div class="empty" style="margin-top:14px;">
        Nessun evento futuro trovato con questi filtri.
      </div>
    <?php else: ?>

      <!-- Lista eventi -->
      <div class="eventi-page">
        <section class="events-list" aria-label="Elenco eventi futuri">

          <?php while ($ev = pg_fetch_assoc($res)): ?>
            <?php
            // Per ogni riga evento, preparo alcune variabili di comodo

            $id = (int)$ev['id'];

            $statoEvento  = (string)($ev['stato_evento'] ?? 'attivo');
            $isCancelled  = ($statoEvento === 'annullato');

            $isFree      = ((float)$ev['prezzo'] <= 0);
            $needBooking = (
              $ev['prenotazione_obbligatoria'] === 't' ||
              $ev['prenotazione_obbligatoria'] === true ||
              $ev['prenotazione_obbligatoria'] === '1'
            );

            // Evento informativo se posti_totali è NULL / stringa vuota
            $isInfo          = ($ev['posti_totali'] === null || $ev['posti_totali'] === '');
            $hasLimitedSeats = (!$isInfo && (int)$ev['posti_totali'] > 0);

            // Categoria preferita dall'utente (per evidenziare la card)
            $isPreferred = ($logged && isset($prefMap[(int)$ev['categoria_id']]));

            // Badge countdown tempo (solo loggati)
            // Calcola un'etichetta come "Domani", "Tra 2 giorni", "Tra 3h 15m" ecc.
            $badge = '';
            if ($logged) {
              $now = new DateTime('now');
              $dt  = new DateTime((string)$ev['data_evento']);

              if ($dt > $now) {
                if ($dt->format('Y-m-d') === $now->format('Y-m-d')) {
                  $diff  = $now->diff($dt);
                  $hours = (int)$diff->h;
                  $mins  = (int)$diff->i;

                  if ($hours === 0) {
                    $badge = ($mins <= 1) ? 'Tra 1 minuto' : "Tra {$mins} minuti";
                  } elseif ($mins === 0) {
                    $badge = ($hours === 1) ? 'Tra 1 ora' : "Tra {$hours} ore";
                  } else {
                    $badge = "Tra {$hours}h {$mins}m";
                  }
                } else {
                  $days = (int)$now->diff($dt)->days;
                  $badge = ($days === 1) ? 'Domani' : "Tra {$days} giorni";
                }
              }
            }

            // Distanza evento (solo se geo_ok e calcolata a DB)
            $distLabel = '';
            if ($logged && $geo_ok && $ev['distanza_km'] !== null && $ev['distanza_km'] !== '') {
              $dist = (float)$ev['distanza_km'];
              if ($dist >= 0) {
                $distLabel = number_format($dist, 1, ',', '.') . " km";
              }
            }
            ?>

            <!-- Card evento singolo -->
            <article class="card event-card <?= $isPreferred ? 'card-preferred' : '' ?> <?= $isCancelled ? 'card-cancelled' : '' ?>"

              aria-label="Evento <?= e($ev['titolo']) ?>">

              <!-- Immagine evento -->
              <div class="card-img">
                <?php if (!empty($ev['immagine'])): ?>
                  <img src="<?= e($ev['immagine']) ?>"
                    alt="Immagine evento: <?= e($ev['titolo']) ?>">
                <?php else: ?>
                  <!-- Fallback testuale se non è presente un'immagine -->
                  <span aria-hidden="true"><?= e($ev['categoria'] ?? 'Evento') ?></span>
                <?php endif; ?>

                <!-- Tag sovrapposti all'immagine (badge visivi) -->
                <div class="img-tags" aria-hidden="true">
                  <?php if ($isPreferred): ?>
                    <span class="tag-overlay pref">Per te</span>
                  <?php endif; ?>

                  <?php if ($isFree): ?>
                    <span class="tag-overlay free">Gratis</span>
                  <?php else: ?>
                    <span class="tag-overlay book">
                      €<?= e(number_format((float)$ev['prezzo'], 2, ',', '.')) ?>
                    </span>
                  <?php endif; ?>

                  <?php if ($needBooking && !$isInfo && !$isCancelled): ?>
                    <span class="tag-overlay hot">Prenotazione</span>
                  <?php endif; ?>

                  <?php if ($distLabel !== ''): ?>
                    <span class="tag-overlay"><?= e($distLabel) ?></span>
                  <?php endif; ?>

                  <?php if ($badge !== ''): ?>
                    <span class="tag-overlay"><?= e($badge) ?></span>
                  <?php endif; ?>

                  <?php if ($isCancelled): ?>
                    <span class="tag-overlay tag-annullato">Annullato</span>
                  <?php endif; ?>
                </div>
              </div>

              <!-- Corpo card con info principali -->
              <div class="card-body">
                <div class="tag-row">
                  <span class="tag cardtag"><?= e($ev['categoria'] ?? 'Evento') ?></span>
                  <span class="tag cardtag">
                    <?= e(date('d/m/Y H:i', strtotime((string)$ev['data_evento']))) ?>
                  </span>

                  <?php if ($hasLimitedSeats): ?>
                    <span class="tag cardtag">Posti: <?= (int)$ev['posti_totali'] ?></span>
                  <?php else: ?>
                    <span class="tag cardtag">Accesso libero</span>
                  <?php endif; ?>

                  <?php if ($isCancelled): ?>
                    <span class="tag cardtag tag-annullato">Annullato</span>
                  <?php endif; ?>
                </div>

                <h3><?= e($ev['titolo']) ?></h3>

                <p class="meta">
                  <span><?= e($ev['luogo']) ?></span>
                </p>

                <p class="desc"><?= e($ev['descrizione_breve']) ?></p>

                <!-- dettaglio evento o login -->
                <?php if ($logged): ?>
                  <a class="cta-login"
                    href="<?= base_url('evento.php?id=' . urlencode((string)$id)) ?>">
                    <?= $isCancelled
                      ? 'Vedi dettagli <small>evento annullato</small>'
                      : 'Maggiori dettagli <small>' . ($isInfo ? '' : 'e prenota') . '</small>' ?>
                  </a>
                <?php else: ?>
                  <a class="cta-login" href="<?= base_url('login.php') ?>">
                    Accedi <small>per saperne di più</small>
                  </a>
                <?php endif; ?>
              </div>

            </article>

          <?php endwhile; ?>

        </section>
      </div>

    <?php endif; ?>

  </div>
</main>

<?php if ($logged): ?>
  <script src="<?= base_url('assets/js/eventi.js') ?>"></script>
<?php endif; ?>

<!-- Inclusione del footer del sito -->
<?php require_once __DIR__ . '/includes/footer.php'; ?>

<!-- Chiusura della connessione al database (buona pratica) -->
<?php db_close($conn); ?>