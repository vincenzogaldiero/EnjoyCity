<?php
// logout.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Svuota array sessione
$_SESSION = [];

// Se usi cookie di sessione, distruggilo
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Distruggi sessione
session_destroy();

// Redirect
header("Location: index.php");
exit;
