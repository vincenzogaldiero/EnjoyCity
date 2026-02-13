<?php
// includes/nav.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>

<header class="topbar">
    <div class="container topbar-inner">

        <div class="brand">
            <img src="assets/img/logo.png" alt="EnjoyCity logo" class="logo"
                onerror="this.style.display='none'">
            <div class="brand-title">
                <strong>EnjoyCity</strong>
                <span>Turista nella tua provincia</span>
            </div>
        </div>

        <nav class="nav">
            <a class="btn" href="index.php">Home</a>
            <a class="btn" href="eventi.php">Eventi</a>

            <div class="dropdown">
                <a class="btn dropdown-btn" href="#" onclick="return false;">Il progetto ▾</a>
                <div class="dropdown-menu">
                    <a href="chi_siamo.php">Chi siamo</a>
                    <a href="contatti.php">Contatti</a>
                    <a href="faq.php">FAQ</a>
                    <a href="dicono_di_noi.php">Dicono di noi</a>
                </div>
            </div>

            <?php if (!isset($_SESSION['logged']) || $_SESSION['logged'] !== true): ?>
                <!-- NON LOGGATO -->
                <div class="user">
                    <div class="user-icon" aria-label="Account" tabindex="0">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                            <path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Z" stroke="currentColor" stroke-width="2" />
                            <path d="M4 20a8 8 0 0 1 16 0" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                        </svg>
                    </div>

                    <div class="user-menu">
                        <a class="login" href="login.php">Accedi</a>
                        <a class="register" href="registrazione.php">Registrati</a>
                    </div>
                </div>

            <?php else: ?>
                <!-- LOGGATO (user o admin) -->
                <?php if (isset($_SESSION['ruolo']) && $_SESSION['ruolo'] === 'admin'): ?>
                    <a class="btn" href="admin_dashboard.php">Admin</a>
                <?php else: ?>
                    <a class="btn" href="dashboard.php">Dashboard</a>
                <?php endif; ?>

                <a class="btn" href="logout.php">Logout</a>
            <?php endif; ?>
        </nav>

    </div>
</header>