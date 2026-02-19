<?php
// =========================================================
// FILE: index.php (PUBBLICO / NON LOGGATO)
// Scopo didattico:
// - Homepage pubblica con:
//   1) Banner "Prossimi eventi" (ticker)
//   2) Ricerca eventi (filtri GET)
//   3) "Eventi in evidenza" (popolarità = posti_prenotati)
// Regola professionale:
// - Gli eventi passati RESTANO nel DB (storico/audit),
//   ma nel pubblico mostro SOLO eventi "attivi".
// Coerenza DB pulito:
// - Moderazione: e.stato = 'approvato' (admin)
// - Lifecycle:   e.archiviato=false + e.stato_evento='attivo'
// - Tempo:       e.data_evento >= NOW()
// =========================================================

require_once __DIR__ . '/includes/config.php';

$page_title = "EnjoyCity - Turista nella tua provincia";

// Connessione PostgreSQL (pg_* via config.php)
$conn = db_connect();

// =========================================================
// 1) CATEGORIE (per select) - sempre utili lato UI
// =========================================================
$cats = [];
$resCats = pg_query($conn, "SELECT id, nome FROM categorie ORDER BY nome ASC;");
if ($resCats) {
    while ($row = pg_fetch_assoc($resCats)) $cats[] = $row;
}

// =========================================================
// 2) FILTRI RICERCA (GET) - validazione "soft"
// - q: testo libero (titolo/descrizione breve)
// - quando: data yyyy-mm-dd (filtro per giornata)
// - dove: luogo
// - categoria: id categoria
// =========================================================
$q         = trim((string)($_GET['q'] ?? ''));
$quando    = trim((string)($_GET['quando'] ?? ''));     // yyyy-mm-dd
$dove      = trim((string)($_GET['dove'] ?? ''));
$categoria = trim((string)($_GET['categoria'] ?? ''));

// =========================================================
// 3) BASE WHERE pubblico (regola: SOLO eventi in vigore)
// Nota per la prof:
// - Questo è il "cuore" della coerenza: lo stesso filtro va riusato
//   anche in eventi.php e in altre liste pubbliche.
// =========================================================
$where = [
    "e.stato = 'approvato'",       // moderazione ok
    "e.archiviato = FALSE",        // non nascosto (soft delete)
    "e.stato_evento = 'attivo'",   // non annullato
    "e.data_evento >= NOW()"       // futuro
];

$params = [];
$i = 1;

// 3.1 Ricerca testo (titolo o descrizione breve)
if ($q !== '') {
    $where[]  = "(e.titolo ILIKE $" . $i . " OR e.descrizione_breve ILIKE $" . $i . ")";
    $params[] = "%{$q}%";
    $i++;
}

// 3.2 Filtro luogo
if ($dove !== '') {
    $where[]  = "(e.luogo ILIKE $" . $i . ")";
    $params[] = "%{$dove}%";
    $i++;
}

// 3.3 Filtro categoria (solo se numerico)
if ($categoria !== '' && ctype_digit($categoria)) {
    $where[]  = "e.categoria_id = $" . $i;
    $params[] = (int)$categoria;
    $i++;
}

// 3.4 Filtro giorno (se selezionato)
// Nota: ridondante con NOW(), ma utile quando selezioni un giorno specifico futuro.
if ($quando !== '') {
    // Approccio semplice: range [00:00:00, 23:59:59]
    $where[]  = "(e.data_evento >= $" . $i . " AND e.data_evento <= $" . ($i + 1) . ")";
    $params[] = $quando . " 00:00:00";
    $params[] = $quando . " 23:59:59";
    $i += 2;
}

$whereSql = implode(" AND ", $where);

// =========================================================
// 4) PROSSIMI EVENTI (BANNER/TICKER)
// Scelta UX:
// - ticker "globale" non filtrato dalla ricerca (mostra sempre i prossimi 10)
// - stesso filtro "in vigore" del pubblico
// Sicurezza:
// - query statica, ma resta coerente col pattern
// =========================================================
$sqlNext = "
    SELECT
        e.id, e.titolo, e.data_evento, e.luogo, e.immagine,
        e.prezzo, e.posti_prenotati, e.prenotazione_obbligatoria,
        c.nome AS categoria
    FROM eventi e
    LEFT JOIN categorie c ON c.id = e.categoria_id
    WHERE e.stato = 'approvato'
      AND e.archiviato = FALSE
      AND e.stato_evento = 'attivo'
      AND e.data_evento >= NOW()
    ORDER BY e.data_evento ASC
    LIMIT 10;
";

$nextEvents = [];
$resNext = pg_query($conn, $sqlNext);
if ($resNext) {
    while ($row = pg_fetch_assoc($resNext)) $nextEvents[] = $row;
}

// =========================================================
// 5) EVENTI IN EVIDENZA (HOT)
// - Filtri applicati (ricerca + categoria + luogo + giorno)
// - Ordinamento: più prenotati, poi più imminenti
// - Query parametrizzata per sicurezza
// =========================================================
$sqlHot = "
    SELECT
        e.id, e.titolo, e.descrizione_breve, e.immagine, e.data_evento, e.luogo,
        e.prezzo, e.posti_prenotati, e.prenotazione_obbligatoria,
        c.nome AS categoria
    FROM eventi e
    LEFT JOIN categorie c ON c.id = e.categoria_id
    WHERE $whereSql
    ORDER BY e.posti_prenotati DESC, e.data_evento ASC
    LIMIT 9;
";

$hotEvents = [];
$resHot = pg_query_params($conn, $sqlHot, $params);
if ($resHot) {
    while ($row = pg_fetch_assoc($resHot)) $hotEvents[] = $row;
}

// Chiudiamo la connessione: sotto renderizziamo solo HTML
db_close($conn);

// Header comune (nav/topbar) - pagina pubblica
require_once __DIR__ . '/includes/header.php';

// Path placeholder immagine (coerente)
$placeholder = base_url('assets/img/event-placeholder.jpg');
?>

<main class="container" id="content">

    <!-- =====================================================
         1) TICKER: PROSSIMI EVENTI
         Nota per la prof:
         - lista pubblica: SOLO eventi attivi (futuri/attivi/non archiviati/approvati)
         - click -> login (non loggato)
    ====================================================== -->
    <section class="ticker-card" aria-labelledby="prossimi-title">
        <div class="ticker-head">
            <div class="badge" id="prossimi-title">📅 Prossimi eventi</div>
            <div style="color:var(--muted); font-size:13px;">scorri e passa il mouse per fermare</div>
        </div>

        <div class="banner" aria-label="Scorri i prossimi eventi">
            <div class="banner-track">

                <?php if (count($nextEvents) === 0): ?>
                    <a class="banner-slide" href="#" aria-label="Nessun evento in arrivo">
                        <div class="banner-overlay">
                            <div class="banner-title">Nessun evento in arrivo</div>
                            <div class="banner-meta">Torna più tardi 🙂</div>
                        </div>
                    </a>
                <?php else: ?>

                    <?php foreach ($nextEvents as $ev): ?>
                        <?php
                        $img = trim((string)($ev['immagine'] ?? ''));
                        $imgSrc = ($img !== '') ? e(base_url($img)) : e($placeholder);

                        $isFree = ((float)($ev['prezzo'] ?? 0) <= 0.0);
                        $needBooking = (($ev['prenotazione_obbligatoria'] ?? 'f') === 't' || $ev['prenotazione_obbligatoria'] === true || $ev['prenotazione_obbligatoria'] === '1');
                        $isHot = ((int)($ev['posti_prenotati'] ?? 0) >= 50);
                        ?>

                        <!-- Non loggato: clic porta al login -->
                        <a class="banner-slide" href="<?= e(base_url('login.php')) ?>" aria-label="Accedi per dettagli evento">
                            <figure style="margin:0; width:100%; height:100%;">
                                <img class="banner-img"
                                    src="<?= $imgSrc ?>"
                                    alt="<?= e($ev['titolo'] ?? 'Evento') ?>"
                                    onerror="this.src='<?= e($placeholder) ?>'">

                                <figcaption style="position:absolute; left:-9999px;">
                                    <?= e($ev['titolo'] ?? '') ?> – <?= e($ev['luogo'] ?? '') ?>
                                </figcaption>
                            </figure>

                            <div class="banner-overlay">
                                <div class="banner-title"><?= e($ev['titolo'] ?? '') ?></div>

                                <div class="banner-meta">
                                    <?= e(fmt_datetime($ev['data_evento'] ?? '')) ?> • <?= e($ev['luogo'] ?? '') ?>
                                    <?php if (!empty($ev['categoria'])): ?> • <?= e($ev['categoria']) ?><?php endif; ?>
                                </div>

                                <div class="tag-row" aria-hidden="true">
                                    <?php if ($isFree): ?>
                                        <span class="tag free">GRATIS</span>
                                    <?php endif; ?>

                                    <?php if ($needBooking): ?>
                                        <span class="tag book">PRENOTA</span>
                                    <?php endif; ?>

                                    <?php if ($isHot): ?>
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


    <!-- =====================================================
         2) RICERCA EVENTI (GET)
         Nota per la prof:
         - filtri lato server (sicuri, coerenti col DB)
         - i risultati compaiono in "Eventi in evidenza"
    ====================================================== -->
    <section class="search-card" aria-labelledby="search-title" id="eventi">
        <h2 id="search-title" style="margin:0 0 10px 0; font-size:16px;">Cerca eventi</h2>

        <form method="GET" action="<?= e(base_url('index.php')) ?>">
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
                        <?php $cid = (int)($c['id'] ?? 0); ?>
                        <option value="<?= $cid ?>" <?= ($categoria !== '' && (int)$categoria === $cid) ? 'selected' : '' ?>>
                            <?= e($c['nome'] ?? '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button class="btn-search" type="submit">Cerca</button>
            </div>
        </form>
    </section>


    <!-- =====================================================
         3) EVENTI IN EVIDENZA (HOT)
         Nota per la prof:
         - Selezionati con ORDER BY posti_prenotati DESC
         - Sempre e solo "attivi" grazie a $whereSql
    ====================================================== -->
    <section aria-labelledby="evidenza-title">
        <div class="section-title">
            <h2 id="evidenza-title">Eventi in evidenza</h2>
            <p>Selezionati in base a popolarità</p>
        </div>

        <?php if (count($hotEvents) === 0): ?>
            <div class="empty">Nessun evento trovato con i filtri selezionati.</div>
        <?php else: ?>
            <div class="evidenza-row">
                <?php foreach ($hotEvents as $ev): ?>
                    <?php
                    $img = trim((string)($ev['immagine'] ?? ''));
                    $imgSrc = ($img !== '') ? e(base_url($img)) : e($placeholder);

                    $isFree = ((float)($ev['prezzo'] ?? 0) <= 0.0);
                    $needBooking = (($ev['prenotazione_obbligatoria'] ?? 'f') === 't' || $ev['prenotazione_obbligatoria'] === true || $ev['prenotazione_obbligatoria'] === '1');
                    $isHot = ((int)($ev['posti_prenotati'] ?? 0) >= 50);
                    ?>

                    <article class="card">
                        <figure class="card-img">
                            <img src="<?= $imgSrc ?>" alt="<?= e($ev['titolo'] ?? 'Evento') ?>"
                                onerror="this.src='<?= e($placeholder) ?>'">

                            <figcaption style="position:absolute; left:-9999px;">
                                <?= e($ev['titolo'] ?? '') ?> – <?= e($ev['luogo'] ?? '') ?>
                            </figcaption>

                            <div class="img-tags" aria-hidden="true">
                                <?php if ($isFree): ?>
                                    <span class="tag-overlay free">GRATIS</span>
                                <?php endif; ?>

                                <?php if ($needBooking): ?>
                                    <span class="tag-overlay book">PRENOTA</span>
                                <?php endif; ?>

                                <?php if ($isHot): ?>
                                    <span class="tag-overlay hot">HOT</span>
                                <?php endif; ?>
                            </div>
                        </figure>

                        <div class="card-body">
                            <h3><?= e($ev['titolo'] ?? '') ?></h3>

                            <div class="meta">
                                <span class="pill"><?= e(fmt_datetime($ev['data_evento'] ?? '')) ?></span>
                                <span class="pill"><?= e($ev['luogo'] ?? '') ?></span>
                                <span class="pill hot">🔥 <?= (int)($ev['posti_prenotati'] ?? 0) ?> prenotati</span>
                                <span class="pill">€ <?= e(number_format((float)($ev['prezzo'] ?? 0), 2, ',', '.')) ?></span>
                            </div>

                            <div class="desc"><?= e($ev['descrizione_breve'] ?? '') ?></div>

                            <!-- Non loggato: CTA al login -->
                            <a class="cta-login" href="<?= e(base_url('login.php')) ?>">
                                Accedi per saperne di più <small>→</small>
                            </a>
                        </div>
                    </article>

                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>