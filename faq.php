<?php
// FILE: faq.php

// Caricamento configurazione
require_once __DIR__ . '/includes/config.php';

// Metadati della pagina
$page_title = "FAQ - EnjoyCity";
$page_desc  = "Domande frequenti su EnjoyCity.";

// Inclusione dell'header del sito
require_once __DIR__ . '/includes/header.php';
?>

<!-- =======================
     Intestazione pagina FAQ
======================== -->
<section class="page-head">
    <h1>FAQ</h1>
    <p class="muted">Qui trovi le risposte alle domande più comuni su EnjoyCity.</p>
</section>

<!-- ====================================================
     Sezione con domande e risposte utili (accordion FAQ)
===================================================== -->
<section class="card">
    <div class="accordion" data-accordion>

        <?php
        // Possibilità di scrivere manualmente una FAQ, aggiungendo una riga
        $faqs = [
            ["Che cos’è EnjoyCity?", "È una piattaforma che promuove eventi locali per farti vivere la città come se fossi un turista."],
            ["Devo registrarmi per vedere gli eventi?", "No, gli eventi sono visibili a tutti. Registrandoti puoi proporne di nuovi e interagire di più con il nostro team."],
            ["Chi può proporre un evento?", "I soli utenti registrati possono proporre eventi dalla pagina dedicata; l'amministratore valuterà se pubblicarli."],
            ["Perché alcuni eventi non hanno posti disponibili?", "Perché esistono anche eventi informativi o ad accesso libero: in quel caso non sono previste prenotazioni."],
            ["Come vengono approvati gli eventi?", "Ogni evento proposto viene controllato dall’amministratore per garantire qualità e coerenza delle informazioni."],
            ["Posso modificare un evento che ho proposto?", "No, non puoi modificare un evento già proposto, ma sarà l'amministratore a fare i dovuti controlli e ad apportare le modifiche necessarie."],
            ["Le recensioni sono tutte pubbliche?", "No, devono prima essere approvate dall'amministratore."],
            ["Come posso contattarvi?", "Vai nella sezione Contatti: trovi email, telefono e informazioni di contatto."],
            ["EnjoyCity è un progetto ufficiale del Comune?", "No, è una startup: promuove eventi e territorio con finalità informative."],
        ];

        $i = 1;
        foreach ($faqs as [$q, $a]):
            $btnId = "faq-btn-$i";
            $panelId = "faq-panel-$i";
        ?>
            <article class="accordion-item">
                <h2 class="accordion-title">
                    <button class="accordion-btn" type="button"
                        aria-expanded="false"
                        aria-controls="<?= $panelId ?>"
                        id="<?= $btnId ?>">
                        <span><?= e($q) ?></span>
                        <span class="accordion-icon" aria-hidden="true">+</span>
                    </button>
                </h2>

                <div class="accordion-panel" id="<?= $panelId ?>" role="region" aria-labelledby="<?= $btnId ?>" hidden>
                    <p><?= e($a) ?></p>
                </div>
            </article>
        <?php
            $i++;
        endforeach;
        ?>

    </div>
</section>

<?php 
// Inclusione del footer del sito
require_once __DIR__ . '/includes/footer.php'; ?>