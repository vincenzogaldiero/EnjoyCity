<?php
// FILE: admin/admin_event_action.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// SOLO ADMIN
if (!isset($_SESSION['logged']) || $_SESSION['logged'] !== true || ($_SESSION['ruolo'] ?? '') !== 'admin') {
    $_SESSION['flash_error'] = "Accesso non autorizzato.";
    header("Location: " . base_url("login.php"));
    exit;
}

// SOLO POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: " . base_url("admin/admin_dashboard.php"));
    exit;
}

$id = $_POST['id'] ?? '';
$azione = (string)($_POST['azione'] ?? '');

if (!ctype_digit((string)$id) || !in_array($azione, ['approva', 'rifiuta'], true)) {
    $_SESSION['flash_error'] = "Parametri non validi.";
    header("Location: " . base_url("admin/admin_dashboard.php"));
    exit;
}

$stato = ($azione === 'approva') ? 'approvato' : 'rifiutato';

$conn = db_connect();
$res = pg_query_params($conn, "UPDATE eventi SET stato = $1 WHERE id = $2;", [$stato, (int)$id]);

if ($res) {
    $_SESSION['flash_ok'] = "Evento #$id aggiornato: $stato.";
} else {
    $_SESSION['flash_error'] = "Errore DB: " . pg_last_error($conn);
}

db_close($conn);

header("Location: " . base_url("admin/admin_dashboard.php"));
exit;
