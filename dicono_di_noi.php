<?php
require_once __DIR__ . '/includes/config.php';

$page_title = "Dicono di noi - EnjoyCity";
$page_desc  = "Recensioni degli utenti pubblicate dopo approvazione.";

$conn = db_connect();

function col_exists($conn, $table, $col): bool
{
    $sql = "SELECT 1 FROM information_schema.columns
          WHERE table_schema = 'public' AND table_name = $1 AND column_name = $2
          LIMIT 1;";
    $res = pg_query_params($conn, $sql, [$table, $col]);
    return ($res && pg_num_rows($res) > 0);
}

/* Scegli le colonne corrette in base al tuo schema */
$nameCol = col_exists($conn, 'recensioni', 'nome_visibile') ? 'nome_visibile'
    : (col_exists($conn, 'recensioni', 'nome') ? 'nome'
        : (col_exists($conn, 'recensioni', 'username') ? 'username'
            : (col_exists($conn, 'recensioni', 'email') ? 'email' : null)));

$dateCol = col_exists($conn, 'recensioni', 'data_creazione') ? 'data_creazione'
    : (col_exists($conn, 'recensioni', 'created_at') ? 'created_at'
        : (col_exists($conn, 'recensioni', 'data') ? 'data' : null));

/* campi che di solito esistono */
$votoCol   = col_exists($conn, 'recensioni', 'voto') ? 'voto' : null;
$titoloCol = col_exists($conn, 'recensioni', 'titolo') ? 'titolo' : null;
$testoCol  = col_exists($conn, 'recensioni', 'testo') ? 'testo' : (col_exists($conn, 'recensioni', 'recensione') ? 'recensione' : null);
$statoCol  = col_exists($conn, 'recensioni', 'stato') ? 'stato' : null;

$recensioni = [];

/* Se manca qualcosa di fondamentale, mostriamo un messaggio chiaro */
if (!$votoCol || !$testoCol || !$statoCol) {
    $schema_error = "La tabella recensioni non contiene alcune colonne attese (voto/testo/stato).";
} else {
    $selectName = $nameCol ? $nameCol : "NULL";
    $selectDate = $dateCol ? $dateCol : "NULL";

    $sql = "SELECT $selectName AS autore,
                 $votoCol   AS voto,
                 " . ($titoloCol ? "$titoloCol AS titolo" : "NULL AS titolo") . ",
                 $testoCol  AS testo,
                 $selectDate AS data_creazione
          FROM recensioni
          WHERE $statoCol = 'APPROVATA'
          ORDER BY " . ($dateCol ? "$dateCol DESC" : "1") . "
          LIMIT 50;";

    $res = pg_query($conn, $sql);
    if ($res) {
        while ($r = pg_fetch_assoc($res)) $recensioni[] = $r;
    }
}

db_close($conn);

require_once __DIR__ . '/includes/header.php';
?>

<section class="page-head">
    <h1>Dicono di noi</h1>
    <p class="muted">Qui trovi solo recensioni pubblicate dopo approvazione.</p>
</section>

<section class="card">
    <h2>Recensioni</h2>

    <?php if (!empty($schema_error)): ?>
        <p class="muted"><?= e($schema_error) ?></p>
        <p class="muted">Domani, con l’admin, sistemiamo lo schema in modo definitivo.</p>

    <?php elseif (!$recensioni): ?>
        <p class="muted">Non ci sono ancora recensioni pubblicate.</p>

    <?php else: ?>
        <div class="reviews" aria-label="Elenco recensioni approvate">
            <?php foreach ($recensioni as $r): ?>
                <?php
                $voto = (int)($r['voto'] ?? 0);
                $autore = trim((string)($r['autore'] ?? '')) ?: 'Utente';
                $titolo = trim((string)($r['titolo'] ?? ''));
                $testo  = (string)($r['testo'] ?? '');
                $ts     = $r['data_creazione'] ? strtotime((string)$r['data_creazione']) : false;
                $data   = $ts ? date('d/m/Y', $ts) : '';
                ?>
                <article class="review">
                    <header class="review-head">
                        <div class="review-meta">
                            <strong><?= e($autore) ?></strong>
                            <span class="muted"><?= e($data) ?></span>
                        </div>

                        <div class="review-stars" aria-label="Voto: <?= $voto ?> su 5">
                            <?= str_repeat('★', max(0, min(5, $voto))) . str_repeat('☆', 5 - max(0, min(5, $voto))) ?>
                        </div>
                    </header>

                    <?php if ($titolo !== ''): ?>
                        <h3><?= e($titolo) ?></h3>
                    <?php endif; ?>

                    <p><?= nl2br(e($testo)) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>