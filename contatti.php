<?php
require_once __DIR__ . '/includes/config.php';

$page_title = "Contatti - EnjoyCity";
$page_desc  = "Contatti e informazioni utili del progetto EnjoyCity.";

require_once __DIR__ . '/includes/header.php';
?>

<section class="page-head">
    <h1>Contatti</h1>
    <p class="muted">Per informazioni o supporto puoi contattarci qui.</p>
</section>

<section class="card">
    <h2>Riferimenti</h2>
    <ul class="clean-list">
        <li><strong>Email:</strong> <a href="mailto:turista@enjoycity.it">turista@enjoycity.it</a></li>
        <li><strong>Telefono:</strong> <a href="tel:+390000000000">+39 333 810 8441</a></li>
        <li><strong>Orari:</strong> Lun–Ven, 09:00–18:00</li>
    </ul>
</section>

<section class="card">
    <h2>Hai un dubbio?</h2>
    <p class="muted">
        Consulta le domande frequenti oppure guarda le recensioni della community.
    </p>
    <div class="cta-row">
        <a class="btn" href="<?= base_url('faq.php') ?>">Vai alle FAQ</a>
        <a class="btn primary" href="<?= base_url('dicono_di_noi.php') ?>">Dicono di noi</a>
    </div>

</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>