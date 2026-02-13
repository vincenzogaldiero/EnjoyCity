<?php
// includes/nav.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$logged = isset($_SESSION['logged']) && $_SESSION['logged'] === true;
$ruolo  = $_SESSION['ruolo'] ?? null;

// nome utente da mostrare vicino all'omino
$nomeUtente = $_SESSION['nome_utente'] ?? $_SESSION['nome'] ?? 'Utente';
?>

<header class="topbar">
    <div class="container topbar-inner">

        <!-- Brand -->
        <div class="brand">
            <a href="<?= base_url('index.php') ?>" class="brand-home">
                <img src="<?= base_url('assets/img/logo.png') ?>" alt="EnjoyCity logo" class="logo" onerror="this.style.display='none'">
            </a>

            <div class="brand-title">
                <strong>EnjoyCity</strong>
                <span>Turista nella tua città</span>
            </div>
        </div>

        <!-- Navigation -->
        <nav class="nav" aria-label="Navigazione principale">

            <a class="btn" href="<?= base_url($logged ? 'dashboard.php' : 'index.php') ?>">Home</a>
            <a class="btn" href="<?= base_url('eventi.php') ?>">Eventi</a>

            <?php if (!$logged): ?>

                <!-- NON LOGGATO -->
                <div class="user">
                    <button
                        class="user-icon"
                        type="button"
                        aria-label="Account"
                        aria-haspopup="true"
                        aria-expanded="false">

                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                            <path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Z" stroke="currentColor" stroke-width="2" />
                            <path d="M4 20a8 8 0 0 1 16 0" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                        </svg>
                    </button>

                    <div class="user-menu">
                        <a class="login" href="<?= base_url('login.php') ?>">Accedi</a>
                        <a class="register" href="<?= base_url('registrazione.php') ?>">Registrati</a>
                    </div>
                </div>

                <!-- Dropdown progetto (per non loggato rimane uguale, ma lo lasciamo sempre visibile) -->
                <div class="dropdown">
                    <a class="btn dropdown-btn" href="#" onclick="return false;" tabindex="0">Il progetto ▾</a>
                    <div class="dropdown-menu">
                        <a href="<?= base_url('chi_siamo.php') ?>">Chi siamo</a>
                        <a href="<?= base_url('contatti.php') ?>">Contatti</a>
                        <a href="<?= base_url('faq.php') ?>">FAQ</a>
                        <a href="<?= base_url('dicono_di_noi.php') ?>">Dicono di noi</a>
                    </div>
                </div>

            <?php else: ?>

                <?php if ($ruolo === 'admin'): ?>

                    <!-- ADMIN -->
                    <a class="btn" href="<?= base_url('admin_dashboard.php') ?>">Admin</a>

                    <!-- Dropdown progetto -->
                    <div class="dropdown">
                        <a class="btn dropdown-btn" href="#" onclick="return false;" tabindex="0">Il progetto ▾</a>
                        <div class="dropdown-menu">
                            <a href="<?= base_url('chi_siamo.php') ?>">Chi siamo</a>
                            <a href="<?= base_url('contatti.php') ?>">Contatti</a>
                            <a href="<?= base_url('faq.php') ?>">FAQ</a>
                            <a href="<?= base_url('dicono_di_noi.php') ?>">Dicono di noi</a>
                        </div>
                    </div>

                    <a
                        class="btn logout-link"
                        href="<?= base_url('logout.php') ?>"
                        data-confirm="Sei sicuro di voler uscire?">
                        Logout
                    </a>

                <?php else: ?>

                    <!-- UTENTE LOGGATO -->
                    <a class="btn" href="<?= base_url('proponi_evento.php') ?>">Proponi evento</a>

                    <!-- Profilo con omino + nome: porta all'area personale -->
                    <a class="btn" href="<?= base_url('area_personale.php') ?>" aria-label="Area personale">
                        <span aria-hidden="true" style="display:inline-flex;align-items:center;gap:8px;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                                <path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Z" stroke="currentColor" stroke-width="2" />
                                <path d="M4 20a8 8 0 0 1 16 0" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                            </svg>
                            <span><?= htmlspecialchars($nomeUtente) ?></span>
                        </span>
                    </a>

                    <!-- Dropdown progetto -->
                    <div class="dropdown">
                        <a class="btn dropdown-btn" href="#" onclick="return false;" tabindex="0">Il progetto ▾</a>
                        <div class="dropdown-menu">
                            <a href="<?= base_url('chi_siamo.php') ?>">Chi siamo</a>
                            <a href="<?= base_url('contatti.php') ?>">Contatti</a>
                            <a href="<?= base_url('faq.php') ?>">FAQ</a>
                            <a href="<?= base_url('dicono_di_noi.php') ?>">Dicono di noi</a>
                        </div>
                    </div>

                    <a
                    class="btn logout-link"
                    href="<?= base_url('logout.php') ?>"
                    data-confirm="Sei sicuro di voler uscire?">
                    Logout
                    </a>

                <?php endif; ?>

            <?php endif; ?>

        </nav>

    </div>
</header>