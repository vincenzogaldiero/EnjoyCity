<?php
// FILE: dicono_di_noi.php

require_once __DIR__ . '/includes/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$page_title = "Dicono di noi - EnjoyCity";
$page_desc  = "Recensioni degli utenti pubblicate dopo approvazione.";

$conn = db_connect();

$recensioni = [];
$sql = "
    SELECT r.id,
           r.testo,
           r.voto,
           r.data_recensione,
           u.nome,
           u.cognome
    FROM recensioni r
    LEFT JOIN utenti u ON u.id = r.utente_id
    WHERE LOWER(r.stato) = 'approvato'
    ORDER BY r.data_recensione DESC
    LIMIT 50;
";

$res = pg_query($conn, $sql);
if ($res) {
    while ($row = pg_fetch_assoc($res)) $recensioni[] = $row;
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

    <?php if (!$recensioni): ?>
        <p class="muted">Non ci sono ancora recensioni pubblicate.</p>
    <?php else: ?>
        <div class="reviews" aria-label="Elenco recensioni approvate">
            <?php foreach ($recensioni as $r): ?>
                <?php
                $voto = (int)($r['voto'] ?? 0);
                $nome = trim((string)($r['nome'] ?? ''));
                $cognome = trim((string)($r['cognome'] ?? ''));
                $autore = trim($nome . ' ' . $cognome);
                if ($autore === '') $autore = 'Utente';

                $testo = (string)($r['testo'] ?? '');
                $ts = !empty($r['data_recensione']) ? strtotime((string)$r['data_recensione']) : false;
                $data = $ts ? date('d/m/Y', $ts) : '';
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

                    <p><?= nl2br(e($testo)) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>