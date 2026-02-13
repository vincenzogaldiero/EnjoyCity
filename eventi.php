<?php
// eventi.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$logged = isset($_SESSION['logged']) && $_SESSION['logged'] === true;

$conn = db_connect();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// -------------------------
// FILTRI GET
// -------------------------
$q = trim((string)($_GET['q'] ?? ''));
$categoria = (string)($_GET['categoria'] ?? ''); // id categoria

// Validazione categoria
$categoria_id = null;
if ($categoria !== '' && ctype_digit($categoria)) {
    $categoria_id = (int)$categoria;
}

// Carico categorie per select
$categorie = [];
$resCat = pg_query($conn, "SELECT id, nome FROM categorie ORDER BY nome;");
if ($resCat) {
    while ($row = pg_fetch_assoc($resCat)) $categorie[] = $row;
}

// -------------------------
// QUERY EVENTI: SOLO FUTURI
// -------------------------
$where = [];
$params = [];
$idx = 1;

// Solo approvati e futuri (NO passati)
$where[] = "e.stato = 'approvato'";
$where[] = "e.data_evento >= NOW()";

// filtro testo (titolo/luogo/descrizione_breve)
if ($q !== '') {
    $where[] = "(e.titolo ILIKE $" . $idx . " OR e.luogo ILIKE $" . $idx . " OR e.descrizione_breve ILIKE $" . $idx . ")";
    $params[] = '%' . $q . '%';
    $idx++;
}

// filtro categoria
if ($categoria_id !== null) {
    $where[] = "e.categoria_id = $" . $idx;
    $params[] = $categoria_id;
    $idx++;
}

$sql = "
  SELECT
    e.id, e.titolo, e.descrizione_breve, e.data_evento, e.luogo,
    e.immagine, e.prezzo, e.posti_totali, e.prenotazione_obbligatoria,
    c.nome AS categoria
  FROM eventi e
  LEFT JOIN categorie c ON c.id = e.categoria_id
  WHERE " . implode(" AND ", $where) . "
  ORDER BY e.data_evento ASC
  LIMIT 60;
";

$res = pg_query_params($conn, $sql, $params);
if (!$res) {
    die("Errore query eventi: " . pg_last_error($conn));
}

$page_title = "Eventi - EnjoyCity";
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($page_title) ?></title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<?php require_once __DIR__ . '/includes/header.php'; ?>

<main>
  <div class="container">

    <header class="section-title eventi-head">
      <div>
        <h2>Eventi</h2>
        <p class="muted">
          <?= $logged ? "Solo eventi futuri. Apri un evento per vedere i dettagli completi." : "Solo eventi futuri. Accedi per vedere dettagli e prenotare." ?>
        </p>
      </div>

      <a class="btn" href="<?= $logged ? 'dashboard.php' : 'login.php' ?>">
        <?= $logged ? "Vai alla dashboard" : "Accedi" ?>
      </a>
    </header>

    <!-- FILTRI -->
    <section class="search-card" aria-label="Filtra eventi">
      <form method="get" action="eventi.php" class="search-grid">
        <input
          class="input wide"
          type="text"
          name="q"
          placeholder="Cerca per titolo, luogo, descrizione…"
          value="<?= h($q) ?>"
          aria-label="Cerca eventi">

        <select name="categoria" aria-label="Categoria">
          <option value="">Tutte le categorie</option>
          <?php foreach ($categorie as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= ($categoria_id === (int)$c['id']) ? 'selected' : '' ?>>
              <?= h($c['nome']) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <button class="btn-search" type="submit">Filtra</button>

        <?php if ($q !== '' || $categoria_id !== null): ?>
          <a class="btn-search"
             href="eventi.php"
             style="text-align:center;background:#fff;color:var(--green);border:1px solid rgba(15,118,110,0.25);">
            Reset
          </a>
        <?php endif; ?>
      </form>
    </section>

    <?php if (pg_num_rows($res) === 0): ?>
      <div class="empty" style="margin-top:14px;">
        Nessun evento futuro trovato con questi filtri.
      </div>
    <?php else: ?>
      <section class="grid" aria-label="Elenco eventi futuri">
        <?php while ($e = pg_fetch_assoc($res)) : ?>
          <?php
            $id = (int)$e['id'];

            $isFree = ((float)$e['prezzo'] <= 0);
            $needBooking = ($e['prenotazione_obbligatoria'] === 't' || $e['prenotazione_obbligatoria'] === true || $e['prenotazione_obbligatoria'] === '1');
            $hasLimitedSeats = ($e['posti_totali'] !== null && $e['posti_totali'] !== '');

            // BADGE TEMPO: SOLO PER LOGGATI (e senza funzione)
            $badge = '';
            if ($logged) {
                $now = new DateTime('now');
                $dt  = new DateTime((string)$e['data_evento']);

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
                        if ($days === 1) $badge = 'Domani';
                        else $badge = "Tra {$days} giorni";
                    }
                }
            }
          ?>

          <article class="card" aria-label="Evento <?= h($e['titolo']) ?>">

            <div class="card-img">
              <?php if (!empty($e['immagine'])): ?>
                <img src="<?= h($e['immagine']) ?>" alt="Immagine evento: <?= h($e['titolo']) ?>">
              <?php else: ?>
                <span aria-hidden="true"><?= h($e['categoria'] ?? 'Evento') ?></span>
              <?php endif; ?>

              <div class="img-tags" aria-hidden="true">
                <?php if ($isFree): ?>
                  <span class="tag-overlay free">Gratis</span>
                <?php else: ?>
                  <span class="tag-overlay book">€<?= h(number_format((float)$e['prezzo'], 2, ',', '.')) ?></span>
                <?php endif; ?>

                <?php if ($needBooking && $hasLimitedSeats): ?>
                  <span class="tag-overlay hot">Prenotazione</span>
                <?php endif; ?>

                <?php if ($badge !== ''): ?>
                  <span class="tag-overlay"><?= h($badge) ?></span>
                <?php endif; ?>
              </div>
            </div>

            <div class="card-body">
              <div class="tag-row">
                <span class="tag cardtag"><?= h($e['categoria'] ?? 'Evento') ?></span>
                <span class="tag cardtag"><?= h(date("d/m/Y H:i", strtotime((string)$e['data_evento']))) ?></span>
              </div>

              <h3><?= h($e['titolo']) ?></h3>

              <p class="meta">
                <span><?= h($e['luogo']) ?></span>
              </p>

              <p class="desc"><?= h($e['descrizione_breve']) ?></p>

              <?php if ($logged): ?>
                <a class="cta-login" href="evento.php?id=<?= urlencode((string)$id) ?>">
                  Maggiori dettagli <small>e prenota</small>
                </a>
              <?php else: ?>
                <a class="cta-login" href="login.php">
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
</body>
</html>
<?php db_close($conn); ?>
