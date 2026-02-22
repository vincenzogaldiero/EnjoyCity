<?php
// FILE: includes/admin_header.php
// Scopo: Header unico per tutte le pagine admin
// - Guard: accesso consentito solo agli ADMIN
// - Layout: Topbar + Sidebar + Main container
// - Include CSS base + CSS admin

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/config.php'; // base_url(), e(), fmt_datetime(), ecc.

// =========================================================
// 1) Guard: SOLO ADMIN
// =========================================================
$logged = isset($_SESSION['logged']) && $_SESSION['logged'] === true;
$ruolo  = $_SESSION['ruolo'] ?? '';

if (!$logged || $ruolo !== 'admin') {
    $_SESSION['flash_error'] = "Accesso non autorizzato.";
    header("Location: " . base_url("login.php"));
    exit;
}

// =========================================================
// 2) Meta pagina (fallback)
// =========================================================
$page_desc  = $page_desc ?? "EnjoyCity - Area Admin";
$page_title = $page_title ?? "Admin - EnjoyCity";

// =========================================================
// 3) Helper compatibilità: ends_with (evita dipendenza PHP8)
// =========================================================
function ends_with(string $haystack, string $needle): bool
{
    if ($needle === '') return true;
    $len = strlen($needle);
    return substr($haystack, -$len) === $needle;
}

// =========================================================
// 4) Evidenzia link attivo in sidebar
// =========================================================
$current = $_SERVER['SCRIPT_NAME'] ?? '';
$active = function (string $path) use ($current): string {
    return (ends_with($current, $path)) ? ' is-active' : '';
};

// =========================================================
// 5) Dati utente (badge topbar)
// =========================================================
$admin_name = trim((string)($_SESSION['nome'] ?? '')) ?: 'Admin';
?>
<!doctype html>
<html lang="it">

<head>

    <!-- Icona nella barra di ricerca (favicon) -->
    <link rel="icon" type="image/png" href="<?php echo base_url('assets/img/logo.png'); ?>">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="description" content="<?= e($page_desc) ?>">
    <title><?= e($page_title) ?></title>

    <!-- CSS BASE -->
    <link rel="stylesheet" href="<?= e(base_url('assets/css/style.css?v=99')) ?>">
    <!-- CSS ADMIN -->
    <link rel="stylesheet" href="<?= e(base_url('assets/css/admin.css?v=12')) ?>">
</head>

<body class="admin">

    <header class="admin-topbar">
        <div class="admin-topbar-inner">

            <a class="admin-brand" href="<?= e(base_url('admin/admin_dashboard.php')) ?>">
                <img src="<?= e(base_url('assets/img/logo.png')) ?>" alt="EnjoyCity" class="admin-logo"
                    onerror="this.style.display='none'">
                <div class="admin-brand-text">
                    <strong>EnjoyCity</strong>
                    <span>Area Admin</span>
                </div>
            </a>

            <nav class="admin-top-actions" aria-label="Azioni admin">

                <a class="btn btn-ghost" href="<?= e(base_url('index.php')) ?>">Vai al sito</a>
                <a class="btn btn-danger"
                    href="<?= e(base_url('logout.php')) ?>"
                    onclick="return confirm('Sei sicuro di voler uscire?');">
                    Logout
                </a>
            </nav>

        </div>
    </header>

    <div class="admin-shell">
        <aside class="admin-sidebar" aria-label="Menu admin">
            <a class="admin-link<?= $active('/admin/admin_dashboard.php') ?>" href="<?= e(base_url('admin/admin_dashboard.php')) ?>">🏠 Dashboard</a>
            <a class="admin-link<?= $active('/admin/admin_eventi.php') ?>" href="<?= e(base_url('admin/admin_eventi.php')) ?>">📅 Eventi</a>
            <a class="admin-link<?= $active('/admin/admin_utenti.php') ?>" href="<?= e(base_url('admin/admin_utenti.php')) ?>">👤 Utenti</a>

            <div class="admin-sep"></div>

            <!-- Pagine pubbliche utili all'admin -->
            <a class="admin-link<?= $active('/dicono_di_noi.php') ?>" href="<?= e(base_url('dicono_di_noi.php')) ?>">⭐ Dicono di noi</a>
            <a class="admin-link<?= $active('/faq.php') ?>" href="<?= e(base_url('faq.php')) ?>">❓ FAQ</a>
            <a class="admin-link<?= $active('/contatti.php') ?>" href="<?= e(base_url('contatti.php')) ?>">✉️ Contatti</a>
        </aside>

        <main class="admin-main container">