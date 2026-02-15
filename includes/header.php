<?php
// =====================================================
// FILE: includes/header.php
// -----------------------------------------------------
// Scopo:
// - Avvia sessione (se non attiva)
// - Imposta meta (title/description) in modo sicuro
// - Carica il CSS principale
// - Include la navbar (nav.php)
// - Apre il <main> (container) per il contenuto pagina
// =====================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Descrizione pagina (fallback)
$page_desc  = $page_desc  ?? "EnjoyCity - Turista nella tua città";
// Titolo pagina (fallback)
$page_title = $page_title ?? "EnjoyCity";
?>
<!doctype html>
<html lang="it">

<head>
    <!-- Charset + responsive -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">

    <!-- Meta description (escape per sicurezza) -->
    <meta name="description" content="<?= htmlspecialchars($page_desc, ENT_QUOTES, 'UTF-8') ?>">

    <!-- Title (escape per sicurezza) -->
    <title><?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?></title>

    <!-- CSS principale del sito -->
    <link rel="stylesheet" href="<?= base_url('assets/css/style.css?v=99') ?>">
</head>

<body>

    <!-- NAVBAR -->
    <?php require_once __DIR__ . '/nav.php'; ?>

    <!-- Contenuto pagina -->
    <main class="container">