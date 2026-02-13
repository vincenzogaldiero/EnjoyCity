<?php
// login.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$page_title = "Accedi - EnjoyCity";

$errore = "";

// Connessione PG
$conn = db_connect();

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email    = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    // Validazioni lato server (sempre!)
    if ($email === '' || $password === '') {
        $errore = "Inserisci sia email che password.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errore = "Inserisci un'email valida.";
    } elseif (mb_strlen($password) < 8) {
        $errore = "La password deve contenere almeno 8 caratteri.";
    } else {

        // Cerca utente per email
        $sql = "SELECT id, nome, email, password, ruolo, bloccato FROM utenti WHERE email = $1 LIMIT 1;";
        $res = pg_query_params($conn, $sql, [$email]);

        if ($res && pg_num_rows($res) === 1) {
            $user = pg_fetch_assoc($res);

            if ($user && password_verify($password, (string)$user['password'])) {

                if (!empty($user['bloccato']) && ($user['bloccato'] === 't' || $user['bloccato'] === true || $user['bloccato'] === '1')) {
                    $errore = "Il tuo account è stato bloccato. Contatta l'amministratore.";
                } else {
                    session_regenerate_id(true);

                    // Sessioni coerenti con nav.php / dashboard
                    $_SESSION['logged']      = true;
                    $_SESSION['user_id']     = (int)$user['id'];
                    $_SESSION['nome_utente'] = (string)$user['nome'];
                    $_SESSION['ruolo']       = (string)$user['ruolo'];

                    // Redirect
                    if ($_SESSION['ruolo'] === 'admin') {
                        db_close($conn);
                        header("Location: " . base_url('admin_dashboard.php'));
                        exit;
                    } else {
                        db_close($conn);
                        header("Location: " . base_url('dashboard.php'));
                        exit;
                    }
                }
            } else {
                $errore = "Credenziali non valide (Email o Password errata).";
            }
        } else {
            $errore = "Credenziali non valide (Email o Password errata).";
        }
    }
}

db_close($conn);
?>

<?php require_once __DIR__ . '/includes/header.php'; ?>

<section class="auth">
    <div class="auth-card">
        <header class="auth-head">
            <img src="assets/img/logo.png" alt="EnjoyCity logo" class="auth-logo" onerror="this.style.display='none'">
            <h1>Accedi</h1>
            <p>Inserisci le credenziali per continuare.</p>
        </header>

        <?php if ($errore !== ""): ?>
            <div class="alert alert-error" role="alert">
                <?= htmlspecialchars($errore, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form id="loginForm" class="auth-form" action="login.php" method="POST" novalidate>
            <div class="field">
                <label for="email">Email</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    autocomplete="email"
                    required
                    value="<?= htmlspecialchars((string)($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <small class="hint" id="emailHint"></small>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    autocomplete="current-password"
                    required
                    minlength="8">
                <small class="hint" id="passwordHint"></small>
            </div>

            <button type="submit" class="btn btn-primary w-100">Entra</button>

            <p class="auth-links">
                Non hai un account? <a href="registrazione.php" class="auth-links">Registrati</a>
            </p>
        </form>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>