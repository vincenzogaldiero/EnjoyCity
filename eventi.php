<?php
// =========================================================
// FILE: eventi.php
// Scopo didattico:
// - Lista eventi futuri (pagina pubblica, ma con funzionalità extra per loggati)
// - Regola professionale:
//   Il pubblico non vede eventi passati / archiviati / annullati.
// - Ordinamento:
//   - sempre per data per non loggati (anti-manomissione URL)
//   - per loggati: data / prezzo / vicino a me (se geolocalizzazione valida)
// - Preferenze: categorie preferite in cima (pref_sort)
// - Sicurezza: query parametrizzate (pg_query_params)
// =========================================================

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

$logged  = isset($_SESSION['logged']) && $_SESSION['logged'] === true;
$user_id = ($logged && isset($_SESSION['user_id'])) ? (int)$_SESSION['user_id'] : 0;

$conn = db_connect();

// ---------------------------------------------------------
// FILTRI GET
// ---------------------------------------------------------
$q = trim((string)($_GET['q'] ?? ''));

$categoria = (string)($_GET['categoria'] ?? '');
$categoria_id = null;
if ($categoria !== '' && ctype_digit($categoria)) {
  $categoria_id = (int)$categoria;
}

// ordinamento (solo loggati)
$ordine = (string)($_GET['ordine'] ?? 'data'); // data | prezzo | vicino
$ordine_valido = in_array($ordine, ['data', 'prezzo', 'vicino'], true) ? $ordine : 'data';

// se non loggato, forza data (anti-manipolazione url)
if (!$logged) {
  $ordine_valido = 'data';
}

// geo (solo loggati)
$lat = isset($_GET['lat']) && $_GET['lat'] !== '' ? (float)$_GET['lat'] : null;
$lon = isset($_GET['lon']) && $_GET['lon'] !== '' ? (float)$_GET['lon'] : null;

$geo_ok = (
  $logged &&
  $ordine_valido === 'vicino' &&
  $lat !== null && $lon !== null &&
  $lat >= -90 && $lat <= 90 &&
  $lon >= -180 && $lon <= 180
);

// ---------------------------------------------------------
// CATEGORIE per select
// ---------------------------------------------------------
$categorie = [];
$resCat = pg_query($conn, "SELECT id, nome FROM categorie ORDER BY nome;");
if ($resCat) {
  while ($row = pg_fetch_assoc($resCat)) $categorie[] = $row;
}

// ---------------------------------------------------------
// PREFERENZE UTENTE (solo loggati)
// preferenze_utente: utente_id, categoria_id, ordine
// ---------------------------------------------------------
$prefMap = []; // categoria_id => ordine
$prefIds = []; // lista categoria_id in ordine
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
      $prefIds[] = $cid;
    }
  }
}

// ---------------------------------------------------------
// QUERY EVENTI (PUBBLICO): FUTURI + APPROVATI + ATTIVI + NON ARCHIVIATI
// ---------------------------------------------------------
$where  = [];
$params = [];
$idx    = 1;

// base: visibilità pubblica
$where[] = "e.stato = 'approvato'";
$where[] = "e.archiviato = FALSE";
$where[] = "e.stato_evento = 'attivo'";
$where[] = "e.data_evento >= NOW()";

if ($q !== '') {
  $where[] = "(e.titolo ILIKE $" . $idx . " OR e.luogo ILIKE $" . $idx . " OR e.descrizione_breve ILIKE $" . $idx . ")";
  $params[] = '%' . $q . '%';
  $idx++;
}

if ($categoria_id !== null) {
  $where[] = "e.categoria_id = $" . $idx;
  $params[] = $categoria_id;
  $idx++;
}

// ---------------------------------------------------------
// pref_sort: preferiti prima (1,2,3...), altrimenti 9999
// ---------------------------------------------------------
$prefSortSql = "9999 AS pref_sort";
if ($logged && count($prefIds) > 0) {
  $case = "CASE";
  foreach ($prefIds as $cid) {
    $case .= " WHEN e.categoria_id = $" . $idx . " THEN " . (int)$prefMap[$cid];
    $params[] = $cid;
    $idx++;
  }
  $case .= " ELSE 9999 END";
  $prefSortSql = "$case AS pref_sort";
}

// ---------------------------------------------------------
// distanza_km se geo_ok (solo eventi con coordinate)
// ---------------------------------------------------------
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
            cos(radians($latParam)) * cos(radians(e.latitudine)) * cos(radians(e.longitudine) - radians($lonParam)) +
            sin(radians($latParam)) * sin(radians(e.latitudine))
          )
        )
      END
    ) AS distanza_km";
}

// ---------------------------------------------------------
// ordine scelto (dopo preferenze)
// ---------------------------------------------------------
$orderSql = "e.data_evento ASC";
if ($logged && $ordine_valido === 'prezzo') {
  $orderSql = "e.prezzo ASC NULLS LAST, e.data_evento ASC";
} elseif ($geo_ok) {
  $orderSql = "distanza_km ASC NULLS LAST, e.data_evento ASC";
}

$sql = "
  SELECT
    e.id, e.titolo, e.descrizione_breve, e.data_evento, e.luogo,
    e.immagine, e.prezzo, e.posti_totali, e.posti_prenotati,
    e.prenotazione_obbligatoria,
    e.categoria_id,
    c.nome AS categoria,
    $prefSortSql,
    $distanceSelect
  FROM eventi e
  LEFT JOIN categorie c ON c.id = e.categoria_id
  WHERE " . implode(" AND ", $where) . "
  ORDER BY pref_sort ASC, $orderSql, e.id ASC
  LIMIT 60;
";

$res = pg_query_params($conn, $sql, $params);
if (!$res) {
  db_close($conn);
  die("Errore query eventi: " . pg_last_error($conn));
}

$page_title = "Eventi - EnjoyCity";
require_once __DIR__ . '/includes/header.php';
?>

<main>
  <div class="container">

    <header class="section-title eventi-head">
      <div>
        <h2>Eventi</h2>
        <p class="muted">
          <?= $logged
            ? "Eventi futuri. Le tue categorie preferite sono in evidenza e compaiono per prime."
            : "Eventi futuri. Accedi per vedere dettagli e prenotare." ?>
        </p>
      </div>

      <a class="btn" href="<?= $logged ? base_url('dashboard.php') : base_url('login.php') ?>">
        <?= $logged ? "Vai alla dashboard" : "Accedi" ?>
      </a>
    </header>

    <!-- FILTRI -->
    <section class="search-card" aria-label="Filtra eventi">
      <form method="get" action="<?= base_url('eventi.php') ?>" class="search-grid" id="eventiFilterForm">

        <input
          class="input wide"
          type="text"
          name="q"
          placeholder="Cerca per titolo, luogo, descrizione…"
          value="<?= e($q) ?>"
          aria-label="Cerca eventi">

        <select name="categoria" aria-label="Categoria">
          <option value="">Tutte le categorie</option>
          <?php foreach ($categorie as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= ($categoria_id === (int)$c['id']) ? 'selected' : '' ?>>
              <?= e($c['nome']) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <?php if ($logged): ?>
          <select name="ordine" aria-label="Ordina" id="ordineSelect">
            <option value="data" <?= $ordine_valido === 'data' ? 'selected' : '' ?>>Più imminenti</option>
            <option value="prezzo" <?= $ordine_valido === 'prezzo' ? 'selected' : '' ?>>Prezzo (crescente)</option>
            <option value="vicino" <?= $ordine_valido === 'vicino' ? 'selected' : '' ?>>Vicino a me</option>
          </select>

          <input type="hidden" name="lat" id="geo-lat" value="<?= $lat !== null ? e((string)$lat) : '' ?>">
          <input type="hidden" name="lon" id="geo-lon" value="<?= $lon !== null ? e((string)$lon) : '' ?>">

          <button class="btn-search btn-geo" type="button" id="btn-vicino">Vicino a me</button>
        <?php endif; ?>

        <button class="btn-search" type="submit">Filtra</button>

        <?php if ($q !== '' || $categoria_id !== null || ($logged && $ordine_valido !== 'data')): ?>
          <a class="btn-search btn-reset" href="<?= base_url('eventi.php') ?>">Reset</a>
        <?php endif; ?>

      </form>
    </section>

    <?php if (pg_num_rows($res) === 0): ?>
      <div class="empty" style="margin-top:14px;">
        Nessun evento futuro trovato con questi filtri.
      </div>
    <?php else: ?>
      <section class="grid" aria-label="Elenco eventi futuri">
        <?php while ($ev = pg_fetch_assoc($res)) : ?>
          <?php
          $id = (int)$ev['id'];

          $isFree = ((float)$ev['prezzo'] <= 0);
          $needBooking = ($ev['prenotazione_obbligatoria'] === 't' || $ev['prenotazione_obbligatoria'] === true || $ev['prenotazione_obbligatoria'] === '1');

          // DB pulito: informativo = posti_totali NULL
          $isInfo = ($ev['posti_totali'] === null || $ev['posti_totali'] === '');
          $hasLimitedSeats = (!$isInfo && (int)$ev['posti_totali'] > 0);

          // preferito?
          $isPreferred = ($logged && isset($prefMap[(int)$ev['categoria_id']]));

          // badge tempo (solo loggati)
          $badge = '';
          if ($logged) {
            $now = new DateTime('now');
            $dt  = new DateTime((string)$ev['data_evento']);

            if ($dt > $now) {
              if ($dt->format('Y-m-d') === $now->format('Y-m-d')) {
                $diff  = $now->diff($dt);
                $hours = (int)$diff->h;
                $mins  = (int)$diff->i;

                if ($hours === 0) $badge = ($mins <= 1) ? 'Tra 1 minuto' : "Tra {$mins} minuti";
                elseif ($mins === 0) $badge = ($hours === 1) ? 'Tra 1 ora' : "Tra {$hours} ore";
                else $badge = "Tra {$hours}h {$mins}m";
              } else {
                $days = (int)$now->diff($dt)->days;
                $badge = ($days === 1) ? 'Domani' : "Tra {$days} giorni";
              }
            }
          }

          // distanza (solo se geo_ok e valorizzata)
          $distLabel = '';
          if ($logged && $geo_ok && $ev['distanza_km'] !== null && $ev['distanza_km'] !== '') {
            $dist = (float)$ev['distanza_km'];
            if ($dist >= 0) $distLabel = number_format($dist, 1, ',', '.') . " km";
          }
          ?>

          <article class="card <?= $isPreferred ? 'card-preferred' : '' ?>" aria-label="Evento <?= e($ev['titolo']) ?>">

            <div class="card-img">
              <?php if (!empty($ev['immagine'])): ?>
                <img src="<?= e($ev['immagine']) ?>" alt="Immagine evento: <?= e($ev['titolo']) ?>">
              <?php else: ?>
                <span aria-hidden="true"><?= e($ev['categoria'] ?? 'Evento') ?></span>
              <?php endif; ?>

              <div class="img-tags" aria-hidden="true">
                <?php if ($isPreferred): ?>
                  <span class="tag-overlay pref">Per te</span>
                <?php endif; ?>

                <?php if ($isFree): ?>
                  <span class="tag-overlay free">Gratis</span>
                <?php else: ?>
                  <span class="tag-overlay book">€<?= e(number_format((float)$ev['prezzo'], 2, ',', '.')) ?></span>
                <?php endif; ?>

                <?php if ($needBooking && !$isInfo): ?>
                  <span class="tag-overlay hot">Prenotazione</span>
                <?php endif; ?>

                <?php if ($distLabel !== ''): ?>
                  <span class="tag-overlay"><?= e($distLabel) ?></span>
                <?php endif; ?>

                <?php if ($badge !== ''): ?>
                  <span class="tag-overlay"><?= e($badge) ?></span>
                <?php endif; ?>
              </div>
            </div>

            <div class="card-body">
              <div class="tag-row">
                <span class="tag cardtag"><?= e($ev['categoria'] ?? 'Evento') ?></span>
                <span class="tag cardtag"><?= e(date("d/m/Y H:i", strtotime((string)$ev['data_evento']))) ?></span>

                <?php if ($hasLimitedSeats): ?>
                  <span class="tag cardtag">Posti: <?= (int)$ev['posti_totali'] ?></span>
                <?php else: ?>
                  <span class="tag cardtag">Info</span>
                <?php endif; ?>
              </div>

              <h3><?= e($ev['titolo']) ?></h3>

              <p class="meta">
                <span><?= e($ev['luogo']) ?></span>
              </p>

              <p class="desc"><?= e($ev['descrizione_breve']) ?></p>

              <?php if ($logged): ?>
                <a class="cta-login" href="<?= base_url('evento.php?id=' . urlencode((string)$id)) ?>">
                  Maggiori dettagli <small><?= $isInfo ? '' : 'e prenota' ?></small>
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
    <?php endif; ?>

  </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php if ($logged): ?>
  <script>
    document.addEventListener("DOMContentLoaded", function() {
      const btn = document.getElementById("btn-vicino");
      const form = document.getElementById("eventiFilterForm");
      const ordineSelect = document.getElementById("ordineSelect");

      if (!btn || !form || !ordineSelect) return;

      function requestGeoAndSubmit() {
        if (!navigator.geolocation) {
          alert("Geolocalizzazione non supportata dal browser.");
          return;
        }
        navigator.geolocation.getCurrentPosition(function(pos) {
          document.getElementById("geo-lat").value = pos.coords.latitude;
          document.getElementById("geo-lon").value = pos.coords.longitude;
          ordineSelect.value = "vicino";
          form.submit();
        }, function() {
          alert("Permesso posizione negato o non disponibile.");
        }, {
          enableHighAccuracy: true,
          timeout: 8000
        });
      }

      btn.addEventListener("click", requestGeoAndSubmit);

      // se scelgo "vicino" dal select senza coordinate: chiedo geo al submit
      form.addEventListener("submit", function(e) {
        if (ordineSelect.value !== "vicino") return;
        const lat = document.getElementById("geo-lat").value;
        const lon = document.getElementById("geo-lon").value;
        if (lat && lon) return;
        e.preventDefault();
        requestGeoAndSubmit();
      });
    });
  </script>
<?php endif; ?>

<?php db_close($conn); ?>