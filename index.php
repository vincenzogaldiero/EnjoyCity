<?php
// index.php

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

$page_title = "EnjoyCity - Turista nella tua provincia";

$conn = db_connect();

/* -----------------------------
   CATEGORIE (per select)
----------------------------- */
$cats = [];
$resCats = pg_query($conn, "SELECT id, nome FROM categorie ORDER BY nome ASC;");
if ($resCats) {
    while ($row = pg_fetch_assoc($resCats)) $cats[] = $row;
}

/* -----------------------------
   FILTRI RICERCA (GET)
----------------------------- */
$q         = trim($_GET['q'] ?? '');
$quando    = trim($_GET['quando'] ?? '');      // yyyy-mm-dd
$dove      = trim($_GET['dove'] ?? '');
$categoria = trim($_GET['categoria'] ?? '');   // id categoria

$where  = ["e.stato = 'approvato'"];
$params = [];
$i = 1;

if ($q !== '') {
    $where[]  = "(e.titolo ILIKE $" . $i . " OR e.descrizione_breve ILIKE $" . $i . ")";
    $params[] = "%$q%";
    $i++;
}
if ($dove !== '') {
    $where[]  = "(e.luogo ILIKE $" . $i . ")";
    $params[] = "%$dove%";
    $i++;
}
if ($categoria !== '' && ctype_digit($categoria)) {
    $where[]  = "e.categoria_id = $" . $i;
    $params[] = (int)$categoria;
    $i++;
}
if ($quando !== '') {
    $where[]  = "(e.data_evento >= $" . $i . " AND e.data_evento <= $" . ($i + 1) . ")";
    $params[] = $quando . " 00:00:00";
    $params[] = $quando . " 23:59:59";
    $i += 2;
}

$whereSql = implode(" AND ", $where);

/* -----------------------------
   PROSSIMI EVENTI (BANNER)
----------------------------- */
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

/* -----------------------------
   EVENTI IN EVIDENZA (HOT)
----------------------------- */
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

/* -----------------------------
   HEADER (include)
----------------------------- */
require_once __DIR__ . '/includes/header.php';
?>

<!-- =========================
     PROSSIMI EVENTI (BANNER)
========================= -->
<section class="ticker-card" aria-labelledby="prossimi-title">
    <div class="ticker-head">
        <div class="badge" id="prossimi-title">📅 Prossimi eventi</div>
        <div style="color:var(--muted); font-size:13px;">scorri e passa il mouse per fermare</div>
    </div>

    <div class="banner">
        <div class="banner-track">
            <?php if (count($nextEvents) === 0): ?>
                <a class="banner-slide" href="#">
                    <div class="banner-overlay">
                        <div class="banner-title">Nessun evento in arrivo</div>
                        <div class="banner-meta">Torna più tardi 🙂</div>
                    </div>
                </a>
            <?php else: ?>
                <?php foreach ($nextEvents as $ev):
                    $img = trim($ev['immagine'] ?? '');
                    $imgSrc = ($img !== '') ? e($img) : 'assets/img/event-placeholder.jpg';
                ?>
                    <!-- non loggato: clic porta al login -->
                    <a class="banner-slide" href="login.php" aria-label="Accedi per dettagli evento">
                        <figure style="margin:0; width:100%; height:100%;">
                            <img class="banner-img" src="<?= $imgSrc ?>" alt="<?= e($ev['titolo']) ?>"
                                onerror="this.src='assets/img/event-placeholder.jpg'">
                            <figcaption style="position:absolute; left:-9999px;">
                                <?= e($ev['titolo']) ?> – <?= e($ev['luogo']) ?>
                            </figcaption>
                        </figure>

                        <div class="banner-overlay">
                            <div class="banner-title"><?= e($ev['titolo']) ?></div>
                            <div class="banner-meta">
                                <?= e(fmt_datetime($ev['data_evento'])) ?> • <?= e($ev['luogo']) ?>
                                <?php if (!empty($ev['categoria'])): ?> • <?= e($ev['categoria']) ?><?php endif; ?>
                            </div>

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

                            <div class="banner-cta">Accedi per visualizzare tutti i dettagli →</div>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- =========================
     RICERCA
========================= -->
<section class="search-card" aria-labelledby="search-title" id="eventi">
    <h2 id="search-title" style="margin:0 0 10px 0; font-size:16px;">Cerca eventi</h2>

    <form method="GET" action="index.php">
        <div class="search-grid">
            <input class="input wide" type="text" name="q"
                placeholder="Cerca un evento (titolo o descrizione)…"
                value="<?= e($q) ?>">

            <input class="input" type="date" name="quando" value="<?= e($quando) ?>" title="Quando">

            <input class="input" type="text" name="dove"
                placeholder="Dove (es. Avellino, Montella…)"
                value="<?= e($dove) ?>">

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

<!-- =========================
     EVENTI IN EVIDENZA
========================= -->
<section aria-labelledby="evidenza-title">
    <div class="section-title">
        <h2 id="evidenza-title">Eventi in evidenza</h2>
        <p>Selezionati in base a popolarità</p>
    </div>

    <?php if (count($hotEvents) === 0): ?>
        <div class="empty">Nessun evento trovato con i filtri selezionati.</div>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($hotEvents as $ev):
                $img = trim($ev['immagine'] ?? '');
                $imgSrc = ($img !== '') ? e($img) : 'assets/img/event-placeholder.jpg';
            ?>
                <article class="card">
                    <figure class="card-img">
                        <img src="<?= $imgSrc ?>" alt="<?= e($ev['titolo']) ?>"
                            onerror="this.src='assets/img/event-placeholder.jpg'">
                        <figcaption style="position:absolute; left:-9999px;">
                            <?= e($ev['titolo']) ?> – <?= e($ev['luogo']) ?>
                        </figcaption>

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
                    </figure>

                    <div class="card-body">
                        <h3><?= e($ev['titolo']) ?></h3>

                        <div class="meta">
                            <span class="pill"><?= e(fmt_datetime($ev['data_evento'])) ?></span>
                            <span class="pill"><?= e($ev['luogo']) ?></span>
                            <span class="pill hot">🔥 <?= (int)$ev['posti_prenotati'] ?> prenotati</span>
                            <span class="pill">€ <?= e(number_format((float)$ev['prezzo'], 2, ',', '.')) ?></span>
                        </div>

                        <div class="desc"><?= e($ev['descrizione_breve']) ?></div>

                        <!-- Non loggato: CTA al login -->
                        <a class="cta-login" href="login.php">Accedi per saperne di più <small>→</small></a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php
// FOOTER (include)
require_once __DIR__ . '/includes/footer.php';
