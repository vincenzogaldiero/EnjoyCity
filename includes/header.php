<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!doctype html>
<html lang="it">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= isset($page_title) ? htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') : 'EnjoyCity' ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=99">
</head>

<body>

    <?php require_once __DIR__ . '/nav.php'; ?>

    <main class="container">