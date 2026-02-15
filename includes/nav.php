<?php
// ==========================================
// NAVBAR PRINCIPALE - EnjoyCity
// Gestione ruoli: guest / user / admin
// ==========================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Stato login
$logged = isset($_SESSION['logged']) && $_SESSION['logged'] === true;
$ruolo  = $_SESSION['ruolo'] ?? null;

$nomeUtente = $_SESSION['nome_utente'] ?? 'Utente';

// Home dinamica:
// - admin nel sito pubblico → index
// - user → dashboard
$homeHref = 'index.php';
if ($logged && $ruolo !== 'admin') {
    $homeHref = 'dashboard.php';
}
?>

<header class="topbar">
    <div class="container topbar-inner">

        <!-- =========================================
             BRAND / LOGO
        ========================================== -->
        <div class="brand">
            <a href="<?= base_url('index.php') ?>" class="brand-home" aria-label="Vai alla home">
                <img
                    src="<?= base_url('assets/img/logo.png') ?>"
                    alt="EnjoyCity logo"
                    class="logo"
                    onerror="this.style.display='none'">
            </a>

            <div class="brand-title">
                <strong>EnjoyCity</strong>
                <span>Turista nella tua città</span>
            </div>
        </div>

        <!-- =========================================
             NAVIGAZIONE
        ========================================== -->
        <nav class="nav" aria-label="Navigazione principale">

            <!-- Sempre visibili -->
            <a class="btn" href="<?= base_url($homeHref) ?>">Home</a>
            <a class="btn" href="<?= base_url('eventi.php') ?>">Eventi</a>

            <?php if (!$logged): ?>

                <!-- =========================
                     UTENTE NON LOGGATO
                ========================== -->

                <div class="user">
                    <button class="user-icon" type="button" aria-label="Account">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                            <path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Z" stroke="currentColor" stroke-width="2" />
                            <path d="M4 20a8 8 0 0 1 16 0" stroke="currentColor" stroke-width="2" />
                        </svg>
                    </button>

                    <div class="user-menu">
                        <a href="<?= base_url('login.php') ?>">Accedi</a>
                        <a href="<?= base_url('registrazione.php') ?>">Registrati</a>
                    </div>
                </div>

                <!-- Dropdown Progetto -->
                <div class="dropdown">
                    <a class="btn dropdown-btn" href="#" onclick="return false;">Il progetto ▾</a>
                    <div class="dropdown-menu">
                        <a href="<?= base_url('chi_siamo.php') ?>">Chi siamo</a>
                        <a href="<?= base_url('contatti.php') ?>">Contatti</a>
                        <a href="<?= base_url('faq.php') ?>">FAQ</a>
                        <a href="<?= base_url('dicono_di_noi.php') ?>">Dicono di noi</a>
                    </div>
                </div>

            <?php else: ?>

                <!-- =========================
                     UTENTE LOGGATO
                ========================== -->

                <?php if ($ruolo === 'admin'): ?>

                    <!-- ======= ADMIN BUTTON PREMIUM ======= -->
                    <a class="btn btn-admin"
                        href="<?= base_url('admin/admin_dashboard.php') ?>"
                        aria-label="Area Admin">

                        <span class="admin-btn-content">

                            <!-- Shield icon -->
                            <svg class="admin-icon" viewBox="0 0 24 24" fill="none">
                                <path
                                    d="M12 3l7 4v5c0 5-3.5 8-7 9-3.5-1-7-4-7-9V7l7-4z"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linejoin="round" />
                            </svg>

                            <!-- Nome completo -->
                            <span class="admin-name"><?= e($nomeUtente) ?></span>


                            <!-- Badge -->
                            <span class="admin-badge">ADMIN</span>

                        </span>
                    </a>

                <?php else: ?>

                    <!-- ======= USER STANDARD ======= -->
                    <a class="btn" href="<?= base_url('proponi_evento.php') ?>">
                        Proponi evento
                    </a>

                    <a class="btn" href="<?= base_url('area_personale.php') ?>">
                        <span style="display:inline-flex;align-items:center;gap:8px;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                                <path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Z" stroke="currentColor" stroke-width="2" />
                                <path d="M4 20a8 8 0 0 1 16 0" stroke="currentColor" stroke-width="2" />
                            </svg>
                            <span><?= e($nomeCompleto) ?></span>
                        </span>
                    </a>

                <?php endif; ?>

                <!-- Dropdown Progetto -->
                <div class="dropdown">
                    <a class="btn dropdown-btn" href="#" onclick="return false;">Il progetto ▾</a>
                    <div class="dropdown-menu">
                        <a href="<?= base_url('chi_siamo.php') ?>">Chi siamo</a>
                        <a href="<?= base_url('contatti.php') ?>">Contatti</a>
                        <a href="<?= base_url('faq.php') ?>">FAQ</a>
                        <a href="<?= base_url('dicono_di_noi.php') ?>">Dicono di noi</a>
                    </div>
                </div>

                <!-- Logout -->
                <a class="btn logout-link"
                    href="<?= base_url('logout.php') ?>"
                    data-confirm="Sei sicuro di voler uscire?">
                    Logout
                </a>

            <?php endif; ?>

        </nav>

    </div>
</header>