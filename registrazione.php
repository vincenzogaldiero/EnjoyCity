<?php
// FILE: registrazione.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/config.php';

$nome = "";
$cognome = "";
$email = "";
$errore = "";
$successo = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $nome     = $_POST['nome'] ?? '';
    $cognome  = $_POST['cognome'] ?? '';
    $email    = $_POST['email'] ?? '';
    $password = (string)($_POST['password'] ?? '');
    $conferma = (string)($_POST['conferma'] ?? '');

    if ($nome === '' || $cognome === '' || $email === '' || $password === '' || $conferma === '') {
        $errore = "Tutti i campi sono obbligatori.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errore = "Email non valida.";
    } elseif ($password !== $conferma) {
        $errore = "Le password non coincidono.";
    } elseif (strlen($password) < 8) {
        $errore = "La password deve essere di almeno 8 caratteri.";
    } else {
        try {
            // email già registrata?
            $stmt = $pdo->prepare("SELECT id FROM utenti WHERE email = ?");
            $stmt->execute([$email]);

            if ($stmt->fetch()) {
                $errore = "Questa email è già registrata.";
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);

                // se hai la colonna bloccato, mettila a false
                $sql = "INSERT INTO utenti (nome, cognome, email, password, ruolo) 
                        VALUES (?, ?, ?, ?, 'user')";
                $stmt = $pdo->prepare($sql);

                if ($stmt->execute([$nome, $cognome, $email, $hash])) {
                    $successo = "Registrazione avvenuta con successo! <a href='login.php'>Accedi ora</a>";
                    $nome = $cognome = $email = "";
                } else {
                    $errore = "Errore durante l'inserimento nel database.";
                }
            }
        } catch (PDOException $e) {
            $errore = "Errore Database: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrazione - Enjoy City</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body class="auth-page">

    <div class="auth-container" style="max-width: 500px; margin: 50px auto; padding: 30px; border: 1px solid #ddd; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">

        <div style="text-align: center; margin-bottom: 20px;">
            <img src="assets/img/logo.png" alt="Logo" style="height: 60px;">
            <h2 style="color: #2E7D32;">Crea il tuo account</h2>
        </div>

        <?php if ($errore !== ''): ?>
            <div style="background-color:#f8d7da; color:#721c24; padding:10px; margin-bottom:15px; border-radius:5px; border:1px solid #f5c6cb;">
                <?= $errore ?>
            </div>
        <?php endif; ?>

        <?php if ($successo !== ''): ?>
            <div style="background-color:#d1e7dd; color:#0f5132; padding:10px; margin-bottom:15px; border-radius:5px; border:1px solid #badbcc;">
                <?= $successo ?>
            </div>
        <?php endif; ?>

        <form action="registrazione.php" method="POST">
            <div style="margin-bottom: 12px;">
                <label for="nome">Nome:</label>
                <input type="text" id="nome" name="nome" value="<?= $nome ?>" required style="width:100%; padding:10px; margin-top:5px;">
            </div>

            <div style="margin-bottom: 12px;">
                <label for="cognome">Cognome:</label>
                <input type="text" id="cognome" name="cognome" value="<?= $cognome ?>" required style="width:100%; padding:10px; margin-top:5px;">
            </div>

            <div style="margin-bottom: 12px;">
                <label for="email">Email:</label>
                <input type="email" id="email" name="email" value="<?= $email ?>" required style="width:100%; padding:10px; margin-top:5px;">
            </div>

            <div style="margin-bottom: 12px;">
                <label for="password">Password (min 8):</label>
                <input type="password" id="password" name="password" required style="width:100%; padding:10px; margin-top:5px;">
            </div>

            <div style="margin-bottom: 18px;">
                <label for="conferma">Conferma Password:</label>
                <input type="password" id="conferma" name="conferma" required style="width:100%; padding:10px; margin-top:5px;">
            </div>

            <button type="submit" style="width: 100%; padding: 12px; background-color: #2E7D32; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 16px;">
                Registrati
            </button>
        </form>

        <p style="text-align:center; margin-top:18px;">
            Hai già un account? <a href="login.php" style="color:#2E7D32; font-weight:bold;">Accedi</a><br>
            <a href="index.php" style="color:#666; font-size:0.9em; text-decoration:none;">&larr; Torna alla Home</a>
        </p>
    </div>

</body>

</html>