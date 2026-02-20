<?php
// registrazione.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Avvio sessione e caricamento configurazione
require_once __DIR__ . '/includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$page_title = "Registrazione - EnjoyCity";

//Inizializzazione variabili
$nome = "";
$cognome = "";
$email = "";
$errore = "";
$successo = "";

// Connessione al database
$conn = db_connect();

// Gestione invio form di registrazione
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Normalizzazione degli input
    $nome     = trim((string)($_POST['nome'] ?? ''));
    $cognome  = trim((string)($_POST['cognome'] ?? ''));
    $email    = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $conferma = (string)($_POST['conferma'] ?? '');

    // Validazioni lato server (fonte di verità)
    if ($nome === '' || $cognome === '' || $email === '' || $password === '' || $conferma === '') {
        $errore = "Tutti i campi sono obbligatori.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errore = "Email non valida.";
    } elseif (mb_strlen($password) < 8) {
        $errore = "La password deve contenere almeno 8 caratteri.";
    } elseif ($password !== $conferma) {
        $errore = "Le password non coincidono.";
    } else {

        // Verifica email già registrata
        $sqlCheck = "SELECT id FROM utenti WHERE email = $1 LIMIT 1;";
        $resCheck = pg_query_params($conn, $sqlCheck, [$email]);

        if ($resCheck && pg_num_rows($resCheck) > 0) {
            $errore = "Questa email è già registrata.";
        } else {
            // Inserimento nuovo utente
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $sqlIns = "
                INSERT INTO utenti (nome, cognome, email, password, ruolo)
                VALUES ($1, $2, $3, $4, 'user');
            ";
            $resIns = pg_query_params($conn, $sqlIns, [$nome, $cognome, $email, $hash]);

            if ($resIns) {
                $successo = "Registrazione avvenuta con successo! Ora puoi accedere.";
                $nome = $cognome = $email = "";
            } else {
                $errore = "Errore durante la registrazione. Riprova.";
            }
        }
    }
}

// Chiusura connessione al database
db_close($conn);
?>

<?php require_once __DIR__ . '/includes/header.php'; ?>

<section class="auth">
    <div class="auth-card">
        <header class="auth-head">
            <img src="assets/img/logo.png" alt="EnjoyCity logo" class="auth-logo" onerror="this.style.display='none'">
            <h1>Crea il tuo account</h1>
            <p>Compila i campi per registrarti.</p>
        </header>

        <?php if ($errore !== ''): ?>
            <div class="alert alert-error" role="alert">
                <?= htmlspecialchars($errore, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($successo !== ''): ?>
            <div class="alert alert-success" role="status">
                <?= htmlspecialchars($successo, ENT_QUOTES, 'UTF-8') ?>
                <div class="mt-8">
                    <a class="link-register" href="login.php">Accedi ora</a>
                </div>
            </div>
        <?php endif; ?>

        <form id="registerForm" class="auth-form" action="registrazione.php" method="POST" novalidate>
            <div class="field">
                <label for="nome">Nome</label>
                <input
                    type="text"
                    id="nome"
                    name="nome"
                    autocomplete="given-name"
                    required
                    value="<?= htmlspecialchars($nome, ENT_QUOTES, 'UTF-8') ?>">
                <small class="hint" id="nomeHint"></small>
            </div>

            <div class="field">
                <label for="cognome">Cognome</label>
                <input
                    type="text"
                    id="cognome"
                    name="cognome"
                    autocomplete="family-name"
                    required
                    value="<?= htmlspecialchars($cognome, ENT_QUOTES, 'UTF-8') ?>">
                <small class="hint" id="cognomeHint"></small>
            </div>

            <div class="field">
                <label for="email">Email</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    autocomplete="email"
                    required
                    value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>">
                <small class="hint" id="emailHint"></small>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    autocomplete="new-password"
                    required
                    minlength="8">
                <small class="hint" id="passwordHint"></small>
            </div>

            <div class="field">
                <label for="conferma">Conferma password</label>
                <input
                    type="password"
                    id="conferma"
                    name="conferma"
                    autocomplete="new-password"
                    required
                    minlength="8">
                <small class="hint" id="confermaHint"></small>
            </div>

            <button type="submit" class="btn btn-primary w-100">Registrati</button>

            <p class="auth-links">
                Hai già un account?
                <a href="login.php" class="link-register">Accedi</a>
            </p>

            <p class="auth-links">
                <a href="index.php" class="link-muted">&larr; Torna alla Home</a>
            </p>
        </form>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>