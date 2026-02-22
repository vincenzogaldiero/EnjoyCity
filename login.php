<?php
// =========================================================
// FILE: login.php
// Funzionalità coperte:
// - Login utente con sessioni PHP
// - Controllo credenziali con password_hash/password_verify
// - Gestione blocco account (user_is_blocked)
// - Cookie "remember_email" per ricordare l'email per 30 giorni
// =========================================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include di configurazione: connessione al DB, funzioni di utilità ecc.
require_once __DIR__ . '/includes/config.php';

// Avvio sicuro della sessione (se non già avviata)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Titolo della pagina, usato nel template dell'header
$page_title = "Accedi - EnjoyCity";
// Messaggio di errore da mostrare in pagina (vuoto di default)
$errore     = "";

// Apertura connessione al database (PostgreSQL) tramite helper
$conn = db_connect();

// ---------------------------------------------------------
// COOKIE "remember_email"
// ---------------------------------------------------------
// - Se il login va a buon fine e l'utente spunta la checkbox,
//   salvo l'email per 30 giorni.
// - In caso contrario, lo elimino.
// - $old_email viene usato per:
//   * ripopolare il campo email dopo un tentativo fallito (sticky)
//   * precompilare il campo se era stato salvato nel cookie.
// ---------------------------------------------------------

// Valore di default per il campo email (sticky + cookie)
$old_email = (string)($_POST['email'] ?? ($_COOKIE['remember_email'] ?? ''));

// Se il form è stato inviato (metodo POST), procedo con la logica di login
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Normalizzo e recupero i dati inseriti dall'utente
    $email    = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    // Checkbox "Ricorda la mia email"
    $remember = isset($_POST['remember_email']);

    // -----------------------------------------------------
    // VALIDAZIONI LATO SERVER
    // -----------------------------------------------------
    // Oltre all'eventuale validazione JS lato client,
    // è fondamentale validare anche lato server per sicurezza.
    // -----------------------------------------------------
    if ($email === '' || $password === '') {
        $errore = "Inserisci sia email che password.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errore = "Inserisci un'email valida.";
    } elseif (mb_strlen($password) < 8) {
        // Requisito minimo di robustezza password
        $errore = "La password deve contenere almeno 8 caratteri.";
    } else {

        // -------------------------------------------------
        // QUERY PARAMETRIZZATA
        // -------------------------------------------------
        // Uso pg_query_params per:
        // - separare query e dati
        // - evitare vulnerabilità di SQL injection
        // -------------------------------------------------
        $sql = "SELECT id, nome, email, password, ruolo, bloccato, bloccato_fino
                FROM utenti
                WHERE email = $1
                LIMIT 1;";
        $res = pg_query_params($conn, $sql, [$email]);

        // Verifico che esista un utente con quell'email
        if ($res && pg_num_rows($res) === 1) {
            $user = pg_fetch_assoc($res);

            // -------------------------------------------------
            // VERIFICA PASSWORD
            // -------------------------------------------------
            // Confronto la password inserita con l'hash salvato nel DB
            // mediante password_verify (best practice di sicurezza).
            // -------------------------------------------------
            if ($user && password_verify($password, (string)$user['password'])) {

                $uid   = (int)$user['id'];
                // Controllo se l'account è bloccato tramite funzione helper
                $block = user_is_blocked($conn, $uid);

                if ($block['blocked']) {
                    // Account bloccato: mostro un messaggio specifico
                    if (!empty($block['until'])) {
                        $errore = "Account bloccato fino al " . date('d/m/Y H:i', strtotime($block['until'])) . ".";
                    } else {
                        $errore = "Il tuo account è stato bloccato. Contatta l'amministratore.";
                    }
                } else {
                    // -------------------------------------------------
                    // LOGIN CORRETTO
                    // -------------------------------------------------

                    // Rigenero l'ID di sessione per prevenire session fixation
                    session_regenerate_id(true);

                    // Salvo in sessione le informazioni essenziali dell'utente
                    $_SESSION['logged']      = true;
                    $_SESSION['user_id']     = $uid;
                    $_SESSION['nome_utente'] = (string)$user['nome'];
                    $_SESSION['ruolo']       = (string)$user['ruolo'];

                    // ---------------------------------------------
                    // Gestione COOKIE "remember_email"
                    // ---------------------------------------------
                    // Se l'utente ha spuntato la checkbox, salvo l'email
                    // in un cookie con scadenza a 30 giorni.
                    // Altrimenti, se il cookie esiste, lo cancello.
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

                    // Chiudo la connessione prima del redirect
                    db_close($conn);

                    // ---------------------------------------------
                    // Redirect in base al ruolo
                    // ---------------------------------------------
                    // Implementa la distinzione funzionale:
                    // - admin -> area amministrativa
                    // - utente standard -> dashboard personale
                    // ---------------------------------------------
                    if ($_SESSION['ruolo'] === 'admin') {
                        header("Location: " . base_url('admin/admin_dashboard.php'));
                        exit;
                    } else {
                        header("Location: " . base_url('dashboard.php'));
                        exit;
                    }
                }
            } else {
                // Password errata o utente non valido
                $errore = "Credenziali non valide (Email o Password errata).";
            }
        } else {
            // Nessun utente trovato con quella email
            $errore = "Credenziali non valide (Email o Password errata).";
        }
    }
}

// Chiudo la connessione al DB se non è già stata chiusa da sopra
db_close($conn);
?>

<?php require_once __DIR__ . '/includes/header.php'; ?>

<section class="auth">
    <div class="auth-card">
        <header class="auth-head">
            <!-- Logo e intestazione del form di login -->
            <img src="assets/img/logo.png" alt="EnjoyCity logo" class="auth-logo" onerror="this.style.display='none'">
            <h1>Accedi</h1>
            <p>Inserisci le credenziali per continuare.</p>
        </header>

        <!-- Messaggio di errore globale (lato server) -->
        <?php if ($errore !== ""): ?>
            <div class="alert alert-error" role="alert">
                <?= htmlspecialchars($errore, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <!--
            Form di login:
            - method="POST" per invio sicuro delle credenziali.
            - novalidate per delegare la validazione a JS custom + PHP.
        -->
        <form id="loginForm" class="auth-form" action="login.php" method="POST" novalidate>
            <div class="field">
                <label for="email">Email</label>
                <!--
                    type="email" attiva controlli HTML5 lato client.
                    autocomplete="email" aiuta l'utente nella compilazione.
                    value usa $old_email per mantenere la mail:
                    - dopo un errore
                    - se salvata nel cookie remember_email.
                -->
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
                <!--
                    type="password" per nascondere i caratteri.
                    autocomplete="current-password" per aiutare il browser
                    a gestire in modo sicuro le credenziali salvate.
                -->
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
                <!--
                    Checkbox per il cookie "Ricorda la mia email".
                    Se $old_email non è vuoto (perché salvato nel cookie),
                    la checkbox risulta già spuntata.
                -->
                <label>
                    <input
                        type="checkbox"
                        name="remember_email"
                        <?php if (!empty($old_email)) echo 'checked'; ?>>
                    Ricorda la mia email
                </label>
            </div>

            <!-- Pulsante di submit principale -->
            <button type="submit" class="btn btn-primary w-100">Entra</button>

            <!-- Link per chi non è ancora registrato -->
            <p class="auth-links">
                Non hai un account?
                <a href="registrazione.php" class="auth-links">Registrati</a>
            </p>
        </form>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>