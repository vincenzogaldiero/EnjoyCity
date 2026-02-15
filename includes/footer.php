<?php
// =====================================================
// FILE: includes/footer.php   (o chiusura pagina)
// -----------------------------------------------------
// Scopo:
// - Chiudere il <main> aperto in header.php
// - Stampare il footer globale del sito
// - Inserire link informativi (pagine statiche)
// - Caricare lo script JS principale
// - Chiudere correttamente body e html
// =====================================================
?>

</main> <!-- /main.container: fine contenuto pagina -->

<!-- =======================================================
     FOOTER GLOBALE - EnjoyCity
     - Coerente con stile card (sfondo bianco + bordo)
     - Include copyright dinamico e link utili
======================================================= -->
<footer class="site-footer">
    <div class="container footer-inner">

        <!-- Copyright dinamico (anno corrente) -->
        <div class="footer-copy">
            © <?= date('Y') ?> EnjoyCity
        </div>

        <!-- Link informativi (pagine statiche del progetto) -->
        <nav class="footer-links" aria-label="Link informativi">
            <a href="<?= base_url('chi_siamo.php') ?>">Chi siamo</a>
            <a href="<?= base_url('contatti.php') ?>">Contatti</a>
            <a href="<?= base_url('faq.php') ?>">FAQ</a>
            <a href="<?= base_url('dicono_di_noi.php') ?>">Dicono di noi</a>
        </nav>

    </div>
</footer>

<!-- =======================================================
     JS PRINCIPALE
     - Contiene logiche client-side (es: dropdown, conferme, UX)
     - Caricato a fine pagina per performance (render first)
======================================================= -->
<script src="<?= base_url('assets/js/script.js') ?>"></script>

</body>

</html>