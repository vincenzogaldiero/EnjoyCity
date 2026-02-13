<?php
// FILE: login.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/config.php';

$errore = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email    = $_POST['email'] ?? '';
    $password = (string)($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $errore = "Inserisci sia email che password.";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, nome, email, password, ruolo, bloccato FROM utenti WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {

                if (!empty($user['bloccato'])) {
                    $errore = "Il tuo account è stato bloccato. Contatta l'amministratore.";
                } else {
                    // Sessione
                    $_SESSION['user_id']     = (int)$user['id'];
                    $_SESSION['nome_utente'] = $user['nome'];
                    $_SESSION['ruolo']       = $user['ruolo'];

                    // Redirect
                    if ($user['ruolo'] === 'admin') {
                        header("Location: admin/dashboard.php");
                    } else {
                        header("Location: index.php");
                    }
                    exit;
                }
            } else {
                $errore = "Credenziali non valide (Email o Password errata).";
            }
        } catch (PDOException $e) {
            $errore = "Errore di sistema: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accedi - Enjoy City</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body class="auth-page">

    <div class="auth-container" style="max-width: 400px; margin: 80px auto; padding: 30px; border: 1px solid #ddd; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">

        <div style="text-align: center; margin-bottom: 20px;">
            <img src="assets/img/logo.png" alt="Logo" style="height: 60px;">
            <h2 style="color: #2E7D32;">Accedi</h2>
        </div>

        <?php if ($errore !== ''): ?>
            <div style="background-color:#f8d7da; color:#721c24; padding:10px; margin-bottom:15px; border-radius:5px; border:1px solid #f5c6cb;">
                <?= $errore ?>
            </div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <div style="margin-bottom: 15px;">
                <label for="email">Email:</label>
                <input type="email" id="email" name="email" required style="width: 100%; padding: 10px; margin-top: 5px;">
            </div>

            <div style="margin-bottom: 20px;">
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" required style="width: 100%; padding: 10px; margin-top: 5px;">
            </div>

            <button type="submit" class="btn btn-login" style="width: 100%; padding: 12px; background-color: #2E7D32; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 16px;">
                Entra
            </button>
        </form>

        <p style="text-align: center; margin-top: 20px;">
            Non hai un account? <a href="registrazione.php" style="color: #2E7D32; font-weight: bold;">Registrati</a><br>