<?php
// FILE: login.php
// Scopo didattico:
// - Login utente con sessioni PHP
// - Controllo credenziali con password_hash/password_verify
// - Gestione blocco account (user_is_blocked)
// - Cookie "remember_email" per ricordare l'email per 30 giorni

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$page_title = "Accedi - EnjoyCity";
$errore     = "";

$conn = db_connect();

// ---------------------------------------------------------
// COOKIE "remember_email"
// - Se il login va a buon fine e l'utente spunta la checkbox,
//   salvo l'email per 30 giorni.
// - In caso contrario, lo elimino.
// ---------------------------------------------------------

// Valore di default per il campo email (sticky + cookie)
$old_email = (string)($_POST['email'] ?? ($_COOKIE['remember_email'] ?? ''));

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email    = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $remember = isset($_POST['remember_email']);   // checkbox "Ricorda la mia email"

    if ($email === '' || $password === '') {
        $errore = "Inserisci sia email che password.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errore = "Inserisci un'email valida.";
    } elseif (mb_strlen($password) < 8) {
        $errore = "La password deve contenere almeno 8 caratteri.";
    } else {

        $sql = "SELECT id, nome, email, password, ruolo, bloccato, bloccato_fino
                FROM utenti
                WHERE email = $1
                LIMIT 1;";
        $res = pg_query_params($conn, $sql, [$email]);

        if ($res && pg_num_rows($res) === 1) {
            $user = pg_fetch_assoc($res);

            if ($user && password_verify($password, (string)$user['password'])) {

                $uid   = (int)$user['id'];
                $block = user_is_blocked($conn, $uid);

                if ($block['blocked']) {
                    if (!empty($block['until'])) {
                        $errore = "Account bloccato fino al " . date('d/m/Y H:i', strtotime($block['until'])) . ".";
                    } else {
                        $errore = "Il tuo account è stato bloccato. Contatta l'amministratore.";
                    }
                } else {
                    // Login corretto
                    session_regenerate_id(true);

                    $_SESSION['logged']      = true;
                    $_SESSION['user_id']     = $uid;
                    $_SESSION['nome_utente'] = (string)$user['nome'];
                    $_SESSION['ruolo']       = (string)$user['ruolo'];

                    // ---------------------------------------------
                    // Gestione COOKIE "remember_email"
                    // ---------------------------------------------
                    if ($remember && $email !== '') {
                        // Cookie persistente: 30 giorni
                        $expire = time() + (60 * 60 * 24 * 30);
                        // path "/" per renderlo valido su tutto il sito
                        setcookie('remember_email', $email, $expire, '/');
                    } else {
                        // Se la checkbox non è spuntata, lo cancello
                        setcookie('remember_email', '', time() - 3600, '/');
                    }

                    db_close($conn);

                    // Redirect in base al ruolo
                    if ($_SESSION['ruolo'] === 'admin') {
                        header("Location: " . base_url('admin/admin_dashboard.php'));
                        exit;
                    } else {
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
                    value="<?= htmlspecialchars($old_email, ENT_QUOTES, 'UTF-8') ?>">
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

            <div class="field field-inline">
                <label>
                    <input
                        type="checkbox"
                        name="remember_email"
                        <?php if (!empty($old_email)) echo 'checked'; ?>>
                    Ricorda la mia email
                </label>
            </div>

            <button type="submit" class="btn btn-primary w-100">Entra</button>

            <p class="auth-links">
                Non hai un account?
                <a href="registrazione.php" class="auth-links">Registrati</a>
            </p>
        </form>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>