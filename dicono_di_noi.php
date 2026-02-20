<?php
// FILE: dicono_di_noi.php

// Caricamento configurazione e avvio sessione (se non già attiva)
require_once __DIR__ . '/includes/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Metadati della pagina
$page_title = "Dicono di noi - EnjoyCity";
$page_desc  = "Recensioni degli utenti pubblicate dopo approvazione.";

// Connessione al database e recupero delle recensioni approvate
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

// Chiusura della connessione al database
db_close($conn);

// Inclusione dell'header del sito
require_once __DIR__ . '/includes/header.php';
?>

<!-- ==========================================
     Intestazione della pagina delle recensioni
=========================================== -->
<section class="page-head">
    <h1>Dicono di noi</h1>
    <p class="muted">Qui trovi le recensioni dei nostri utenti registrati.</p>
</section>

<!-- =============================================
     Sezione con elenco delle recensioni approvate
============================================== -->
<section class="card">
    <h2>Recensioni</h2>

    <?php if (!$recensioni): ?>
        <p class="muted">Non ci sono ancora recensioni pubblicate.</p>
    <?php else: ?>
        <div class="reviews" aria-label="Elenco recensioni approvate">
            <?php foreach ($recensioni as $r): ?>
                <?php
                // Preparazione dei dati per la recensione
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

<?php 
// Inclusione del footer del sito
require_once __DIR__ . '/includes/footer.php'; 
?>