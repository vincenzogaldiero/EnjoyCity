</main>
</div>

<footer class="admin-footer">
    <div class="container">
        © <?= date('Y') ?> EnjoyCity — Area Admin
    </div>
</footer>

<!-- JS generali del sito -->
<script defer src="<?= e(base_url('assets/js/script.js')) ?>"></script>

<!-- JS admin (confirm submit, live filter su utenti, micro UX) -->
<script defer src="<?= e(base_url('assets/js/admin.js?v=1')) ?>"></script>

</body>

</html>