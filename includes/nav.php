<?php
// =====================================================
// FILE: includes/nav.php
// -----------------------------------------------------
// Navbar principale - EnjoyCity
// Gestione ruoli:
// - guest  (non loggato)
// - user   (loggato non admin)
// - admin  (loggato admin)
// -----------------------------------------------------
// Fix inclusi:
// - Accedi/Registrati con colori diversi (classi login/register)
// - Variabile nome utente robusta (fallback sicuro)
// - Dropdown con attributi ARIA (spiegabile alla prof)
// =====================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Stato login
$logged = isset($_SESSION['logged']) && $_SESSION['logged'] === true;
$ruolo  = $_SESSION['ruolo'] ?? null;

// Nome utente (fallback sicuro)
// NB: nel tuo codice avevi anche $nomeCompleto non definito -> qui lo gestisco
$nomeUtente   = $_SESSION['nome_utente'] ?? 'Utente';
$nomeCompleto = $_SESSION['nome_completo'] ?? $nomeUtente; // fallback

// Home dinamica:
// - guest e admin nel sito pubblico -> index.php
// - user (non admin) -> dashboard.php
$homeHref = 'index.php';
if ($logged && $ruolo !== 'admin') {
    $homeHref = 'dashboard.php';
}
?>

<header class="topbar">
    <div class="container topbar-inner">

        <!-- =================================================
             BRAND / LOGO
             - Logo cliccabile verso Home pubblica
             - Titolo + slogan (coerenza comunicativa)
        ================================================== -->
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

        <!-- =================================================
             NAVIGAZIONE PRINCIPALE
             - Link sempre visibili: Home, Eventi
             - Elementi condizionati da login/ruolo
        ================================================== -->
        <nav class="nav" aria-label="Navigazione principale">

            <!-- Link base -->
            <a class="btn" href="<?= base_url($homeHref) ?>">Home</a>
            <a class="btn" href="<?= base_url('eventi.php') ?>">Eventi</a>

            <?php if (!$logged): ?>
                <!-- =============================================
                     GUEST (utente non loggato)
                     - Menu account: icona + dropdown (Accedi/Registrati)
                     - Dropdown "Il progetto": pagine statiche
                ============================================== -->

                <!-- Menu account (guest) -->
                <div class="user">
                    <button class="user-icon" type="button" aria-label="Account" aria-haspopup="true">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Z" stroke="currentColor" stroke-width="2" />
                            <path d="M4 20a8 8 0 0 1 16 0" stroke="currentColor" stroke-width="2" />
                        </svg>
                    </button>

                    <!-- FIX: classi login/register per colori diversi nel CSS -->
                    <div class="user-menu" role="menu" aria-label="Menu account">
                        <a class="login" href="<?= base_url('login.php') ?>" role="menuitem">Accedi</a>
                        <a class="register" href="<?= base_url('registrazione.php') ?>" role="menuitem">Registrati</a>
                    </div>
                </div>

                <!-- Dropdown "Il progetto" -->
                <div class="dropdown">
                    <a class="btn dropdown-btn" href="#" onclick="return false;" aria-haspopup="true">
                        Il progetto ▾
                    </a>

                    <div class="dropdown-menu" role="menu" aria-label="Pagine del progetto">
                        <a href="<?= base_url('chi_siamo.php') ?>" role="menuitem">Chi siamo</a>
                        <a href="<?= base_url('contatti.php') ?>" role="menuitem">Contatti</a>
                        <a href="<?= base_url('faq.php') ?>" role="menuitem">FAQ</a>
                        <a href="<?= base_url('dicono_di_noi.php') ?>" role="menuitem">Dicono di noi</a>
                    </div>
                </div>

            <?php else: ?>
                <!-- =============================================
                     LOGGATO (user/admin)
                     - Admin: bottone premium verso area admin
                     - User: link Proponi evento + Area personale
                     - Dropdown "Il progetto" sempre disponibile
                     - Logout
                ============================================== -->

                <?php if ($ruolo === 'admin'): ?>
                    <!-- Admin button premium (coerenza UI: evidenzia ruolo) -->
                    <a class="btn btn-admin"
                        href="<?= base_url('admin/admin_dashboard.php') ?>"
                        aria-label="Area Admin">

                        <span class="admin-btn-content">
                            <svg class="admin-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path
                                    d="M12 3l7 4v5c0 5-3.5 8-7 9-3.5-1-7-4-7-9V7l7-4z"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linejoin="round" />
                            </svg>

                            <span class="admin-name"><?= e($nomeUtente) ?></span>
                            <span class="admin-badge">ADMIN</span>
                        </span>
                    </a>

                <?php else: ?>
                    <!-- User standard -->
                    <a class="btn" href="<?= base_url('proponi_evento.php') ?>">Proponi evento</a>

                    <a class="btn" href="<?= base_url('area_personale.php') ?>">
                        <span style="display:inline-flex;align-items:center;gap:8px;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Z" stroke="currentColor" stroke-width="2" />
                                <path d="M4 20a8 8 0 0 1 16 0" stroke="currentColor" stroke-width="2" />
                            </svg>
                            <!-- FIX: variabile sempre definita -->
                            <span><?= e($nomeCompleto) ?></span>
                        </span>
                    </a>
                <?php endif; ?>

                <!-- Dropdown "Il progetto" (anche da loggato) -->
                <div class="dropdown">
                    <a class="btn dropdown-btn" href="#" onclick="return false;" aria-haspopup="true">
                        Il progetto ▾
                    </a>

                    <div class="dropdown-menu" role="menu" aria-label="Pagine del progetto">
                        <a href="<?= base_url('chi_siamo.php') ?>" role="menuitem">Chi siamo</a>
                        <a href="<?= base_url('contatti.php') ?>" role="menuitem">Contatti</a>
                        <a href="<?= base_url('faq.php') ?>" role="menuitem">FAQ</a>
                        <a href="<?= base_url('dicono_di_noi.php') ?>" role="menuitem">Dicono di noi</a>
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