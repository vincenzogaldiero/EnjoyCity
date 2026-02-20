<?php
// Caricamento configurazione e metadati della pagina
require_once __DIR__ . '/includes/config.php';

$page_title = "Chi siamo - EnjoyCity";
$page_desc  = "Il progetto EnjoyCity: missione, valori e territorio.";

// Inclusione dell'header del sito
require_once __DIR__ . '/includes/header.php';

// Stato utente (per CTA dinamiche)
$logged = isset($_SESSION['logged']) && $_SESSION['logged'] === true;
$ruolo  = $_SESSION['ruolo'] ?? null;
?>

<!-- ===============================
     Intestazione pagina "Chi siamo"
================================ -->
<section class="page-head">
    <h1>Chi siamo</h1>
    <p class="muted">
        EnjoyCity è un progetto pensato per valorizzare gli eventi locali e rendere semplice scoprire che
        cosa succede in città e sul territorio.
    </p>
</section>

<!-- ========================================
     Sezione introduttiva (hero) del progetto
========================================= -->
<section class="hero-split" aria-label="Presentazione progetto">
    <div class="hero-text">
        <h2>Turista nella tua città</h2>
        <p>
            Dalla cultura alle iniziative sociali, dagli eventi informativi alle esperienze sul territorio:
            EnjoyCity raccoglie e presenta eventi in modo chiaro, ordinato e accessibile.
        </p>

        <div class="cta-row">
            <a class="btn primary" href="<?= base_url('eventi.php') ?>">Scopri gli eventi</a>
            <?php if ($logged && ($ruolo ?? '') !== 'admin'): ?>
                <a class="btn" href="<?= base_url('proponi_evento.php') ?>">Proponi evento</a>
            <?php else: ?>
                <a class="btn" href="<?= base_url('contatti.php') ?>">Contattaci</a>
            <?php endif; ?>
        </div>

    </div>

    <figure class="hero-media">
        <img src="<?= base_url('assets/img/avellino.jpg') ?>" alt="Panorama di Avellino" loading="lazy">
        <figcaption class="muted">Avellino: il cuore del progetto.</figcaption>
    </figure>
</section>

<!-- ============================================
     Missione, valori e punti chiave del progetto
============================================= -->
<section class="card-grid" aria-label="Missione e valori">
    <article class="card">
        <h2>Missione</h2>
        <p>Promuovere il territorio rendendo gli eventi facili da trovare, consultare e condividere.</p>
    </article>

    <article class="card">
        <h2>Qualità</h2>
        <p>Informazioni ordinate, pagine leggere e interfaccia semplice, con attenzione alla fruibilità.</p>
    </article>

    <article class="card">
        <h2>Community</h2>
        <p>Gli utenti possono partecipare al progetto proponendo eventi e condividendo recensioni.</p>
    </article>
</section>

<!-- ==========================================
     Galleria di foto di presentazione del sito
=========================================== -->
<section class="gallery" aria-label="Galleria immagini">
    <figure class="gallery-item">
        <img src="<?= base_url('assets/img/borghi.jpg') ?>" alt="Paesaggio dell’Irpinia" loading="lazy">
        <figcaption class="muted">Irpinia: natura, borghi, esperienze.</figcaption>
    </figure>

    <figure class="gallery-item">
        <img src="<?= base_url('assets/img/logo.png') ?>" alt="Logo EnjoyCity" loading="lazy">
        <figcaption class="muted"> Logo </figcaption>
    </figure>

    <figure class="gallery-item">
        <img src="<?= base_url('assets/img/torre.jpg') ?>" alt="Scorcio urbano di Avellino" loading="lazy">
        <figcaption class="muted">Scopri la città con occhi nuovi.</figcaption>
    </figure>
</section>

<?php 
// Inclusione del footer del sito
require_once __DIR__ . '/includes/footer.php'; 
?>