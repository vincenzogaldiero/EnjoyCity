<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

$conn = db_connect();

// ---------- Categorie (per select) ----------
$cats = [];
$resCats = pg_query($conn, "SELECT id, nome FROM categorie ORDER BY nome ASC;");
if ($resCats) {
    while ($row = pg_fetch_assoc($resCats)) $cats[] = $row;
}

// ---------- Filtri ricerca (GET) ----------
$q         = trim($_GET['q'] ?? '');
$quando    = trim($_GET['quando'] ?? '');   // yyyy-mm-dd
$dove      = trim($_GET['dove'] ?? '');
$categoria = trim($_GET['categoria'] ?? ''); // id categoria

$where = ["e.stato = 'approvato'"];
$params = [];
$i = 1;

if ($q !== '') {
    $where[] = "(e.titolo ILIKE $" . $i . " OR e.descrizione_breve ILIKE $" . $i . ")";
    $params[] = "%$q%";
    $i++;
}
if ($dove !== '') {
    $where[] = "(e.luogo ILIKE $" . $i . ")";
    $params[] = "%$dove%";
    $i++;
}
if ($categoria !== '' && ctype_digit($categoria)) {
    $where[] = "e.categoria_id = $" . $i;
    $params[] = (int)$categoria;
    $i++;
}
if ($quando !== '') {
    // prendo tutti gli eventi di quel giorno (00:00 - 23:59)
    $where[] = "(e.data_evento >= $" . $i . " AND e.data_evento < $" . ($i + 1) . ")";
    $params[] = $quando . " 00:00:00";
    $params[] = $quando . " 23:59:59";
    $i += 2;
}

$whereSql = implode(" AND ", $where);

// ---------- Prossimi eventi (ticker) ----------
$sqlNext = "
SELECT e.id, e.titolo, e.data_evento, e.luogo, e.immagine,
       e.prezzo, e.posti_prenotati, e.prenotazione_obbligatoria,
       c.nome AS categoria
FROM eventi e
LEFT JOIN categorie c ON c.id = e.categoria_id
WHERE e.stato = 'approvato'
  AND e.data_evento >= NOW()
ORDER BY e.data_evento ASC
LIMIT 10;
";

$resNext = pg_query($conn, $sqlNext);
$nextEvents = [];
if ($resNext) {
    while ($row = pg_fetch_assoc($resNext)) $nextEvents[] = $row;
}

// ---------- Eventi in evidenza (più prenotati) ----------
$sqlHot = "
SELECT e.id, e.titolo, e.descrizione_breve, e.immagine, e.data_evento, e.luogo,
       e.prezzo, e.posti_prenotati, e.prenotazione_obbligatoria,
       c.nome AS categoria
FROM eventi e
LEFT JOIN categorie c ON c.id = e.categoria_id
WHERE $whereSql
ORDER BY e.posti_prenotati DESC, e.data_evento ASC
LIMIT 9;
";
$resHot = pg_query_params($conn, $sqlHot, $params);
$hotEvents = [];
if ($resHot) {
    while ($row = pg_fetch_assoc($resHot)) $hotEvents[] = $row;
}

db_close($conn);

?>
<!doctype html>
<html lang="it">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>EnjoyCity - Turista nella tua provincia</title>
    <link rel="stylesheet" href="assets/css/style.css?v=2">
</head>

<body>

    <header class="topbar">
        <div class="container topbar-inner">
            <div class="brand">
                <!-- Metti il logo qui: assets/img/logo.png -->
                <img src="assets/img/logo.png" alt="EnjoyCity logo" class="logo">
                <div class="brand-title">
                    <strong>EnjoyCity</strong>
                    <span>Turista nella tua provincia</span>
                </div>
            </div>

            <nav class="nav">
                <a class="btn" href="index.php">Home</a>
                <a class="btn" href="index.php#eventi">Eventi</a>

                <div class="user">
                    <div class="user-icon" aria-label="Account">
                        <!-- omino semplice in SVG -->
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                            <path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Z" stroke="currentColor" stroke-width="2" />
                            <path d="M4 20a8 8 0 0 1 16 0" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                        </svg>
                    </div>

                    <div class="user-menu">
                        <a class="login" href="login.php">Accedi</a>
                        <a class="register" href="registrazione.php">Registrati</a>
                    </div>
                </div>

                <div class="dropdown">
                    <a class="btn dropdown-btn" href="#" aria-haspopup="true">Il progetto ▾</a>
                    <div class="dropdown-menu">
                        <a href="chi_siamo.php">Chi siamo</a>
                        <a href="contatti.php">Contatti</a>
                        <a href="faq.php">FAQ</a>
                        <a href="dicono_di_noi.php">Dicono di noi</a>
                    </div>
                </div>

            </nav>
        </div>
    </header>

    <main class="container">

        <!-- TICKER prossimi eventi -->
        <section class="ticker-card">
            <div class="ticker-head">
                <div class="badge">📅 Prossimi eventi</div>
                <div style="color:var(--muted); font-size:13px;">in ordine di data</div>
            </div>

            <div class="banner">
                <div class="banner-track">
                    <?php foreach ($nextEvents as $ev):
                        $img = trim($ev['immagine'] ?? '');
                        // se nel DB salvi "uploads/eventi/x.jpg", allora qui basta così:
                        $imgSrc = ($img !== '') ? e($img) : 'assets/img/event-placeholder.jpg';
                    ?>
                        <a class="banner-slide" href="evento.php?id=<?= (int)$ev['id'] ?>">
                            <img class="banner-img" src="<?= $imgSrc ?>" alt="<?= e($ev['titolo']) ?>"
                                onerror="this.src='assets/img/event-placeholder.jpg'">
                            <div class="banner-overlay">
                                <div class="banner-title"><?= e($ev['titolo']) ?></div>
                                <div class="banner-meta">
                                    <div class="tag-row">
                                        <?php if ((float)$ev['prezzo'] <= 0.0): ?>
                                            <span class="tag free">GRATIS</span>
                                        <?php endif; ?>

                                        <?php if ($ev['prenotazione_obbligatoria'] === 't' || $ev['prenotazione_obbligatoria'] === true): ?>
                                            <span class="tag book">PRENOTA</span>
                                        <?php endif; ?>

                                        <?php if ((int)$ev['posti_prenotati'] >= 50): ?>
                                            <span class="tag hot">IN TENDENZA</span>
                                        <?php endif; ?>
                                    </div>

                                    <?= e(fmt_datetime($ev['data_evento'])) ?> • <?= e($ev['luogo']) ?>
                                    <?php if (!empty($ev['categoria'])): ?> • <?= e($ev['categoria']) ?><?php endif; ?>
                                </div>

                                <div class="banner-cta">
                                    Accedi per visualizzare tutti i dettagli →
                                </div>

                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

        </section>

        <!-- SEARCH + FILTRI -->
        <section class="search-card" id="eventi">
            <form method="GET" action="index.php">
                <div class="search-grid">
                    <input class="input wide" type="text" name="q" placeholder="Cerca un evento (titolo o descrizione)…"
                        value="<?= e($q) ?>" />

                    <input class="input" type="date" name="quando" value="<?= e($quando) ?>" title="Quando" />

                    <input class="input" type="text" name="dove" placeholder="Dove (es. Avellino, Montella…)"
                        value="<?= e($dove) ?>" />

                    <select name="categoria" title="Categoria">
                        <option value="">Categoria (tutte)</option>
                        <?php foreach ($cats as $c): ?>
                            <option value="<?= e($c['id']) ?>" <?= ($categoria !== '' && (int)$categoria === (int)$c['id']) ? 'selected' : '' ?>>
                                <?= e($c['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button class="btn-search" type="submit">Cerca</button>
                </div>
            </form>
        </section>

        <!-- EVENTI IN EVIDENZA -->
        <div class="section-title">
            <h2>Eventi in evidenza</h2>
            <p>Selezionati in base a popolarità (più prenotati)</p>
        </div>

        <?php if (count($hotEvents) === 0): ?>
            <div class="empty">Nessun evento trovato con i filtri selezionati.</div>
        <?php else: ?>
            <section class="grid">
                <?php foreach ($hotEvents as $ev): ?>
                    <article class="card">
                        <?php
                        $img = trim($ev['immagine'] ?? '');
                        $imgSrc = ($img !== '') ? e($img) : 'assets/img/event-placeholder.jpg';
                        ?>
                        <div class="card-img">
                            <img src="<?= $imgSrc ?>" alt="<?= e($ev['titolo']) ?>"
                                onerror="this.src='assets/img/event-placeholder.jpg'">

                            <div class="img-tags">
                                <?php if ((float)$ev['prezzo'] <= 0.0): ?>
                                    <span class="tag-overlay free">GRATIS</span>
                                <?php endif; ?>

                                <?php if ($ev['prenotazione_obbligatoria'] === 't' || $ev['prenotazione_obbligatoria'] === true): ?>
                                    <span class="tag-overlay book">PRENOTA</span>
                                <?php endif; ?>

                                <?php if ((int)$ev['posti_prenotati'] >= 50): ?>
                                    <span class="tag-overlay hot">HOT</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="card-body">
                            <h3><?= e($ev['titolo']) ?></h3>

                            <div class="meta">
                                <span class="pill"><?= e(fmt_datetime($ev['data_evento'])) ?></span>
                                <span class="pill"><?= e($ev['luogo']) ?></span>
                                <span class="pill hot">🔥 <?= (int)$ev['posti_prenotati'] ?> prenotati</span>
                                <span class="pill">€ <?= e(number_format((float)$ev['prezzo'], 2, ',', '.')) ?></span>
                            </div>

                            <div class="desc"><?= e($ev['descrizione_breve']) ?></div>

                            <!-- CTA per non loggato -->
                            <a class="cta-login" href="login.php">
                                Accedi per saperne di più <small>→</small>
                            </a>
                        </div>
                    </article>

                <?php endforeach; ?>
            </section>
        <?php endif; ?>

    </main>

    <script src="assets/js/script.js"></script>
</body>

</html>