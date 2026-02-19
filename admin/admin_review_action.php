<?php
// ============================================================
// FILE: admin/admin_review_action.php
//
// Area Admin - Moderazione recensioni
// Scopo:
// - Gestire la moderazione delle recensioni lato admin
//   (azioni: approva / rifiuta).
// - Una recensione "approvata" sarà visibile nella sezione
//   pubblica "Dicono di noi".
// ============================================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ------------------------------------------------------------
// Helper: redirect di comodo
// - Se $conn è valorizzato, chiude la connessione DB.
// - Torna alla pagina precedente se è in area admin,
//   altrimenti alla dashboard admin.
// ------------------------------------------------------------
function redirect_back_reviews($conn = null): void
{
    if ($conn) {
        db_close($conn);
    }

    $back = $_SERVER['HTTP_REFERER'] ?? '';

    // Fallback sicuro: se non arrivo da una pagina admin, vado in dashboard
    if ($back === '' || strpos($back, 'admin/') === false) {
        $back = base_url("admin/admin_dashboard.php");
    }

    header("Location: " . $back);
    exit;
}

// ============================================================
// 1) AUTH GUARD: accesso solo admin
// ============================================================
if (
    !isset($_SESSION['logged']) || $_SESSION['logged'] !== true ||
    ($_SESSION['ruolo'] ?? '') !== 'admin'
) {
    $_SESSION['flash_error'] = "Accesso non autorizzato.";
    header("Location: " . base_url("login.php"));
    exit;
}

// ============================================================
// 2) Guard: SOLO POST
//    Le azioni di moderazione non devono essere inviate via GET.
// ============================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: " . base_url("admin/admin_dashboard.php"));
    exit;
}

// ============================================================
// 3) LETTURA INPUT (POST) + VALIDAZIONE
//    - id: intero > 0
//    - azione: 'approva' oppure 'rifiuta'
+ // ============================================================
$idRaw  = $_POST['id'] ?? '';
$azione = strtolower(trim((string)($_POST['azione'] ?? '')));

// Whitelist azioni consentite
$azioni_valide = ['approva', 'rifiuta'];

if (!ctype_digit((string)$idRaw) || !in_array($azione, $azioni_valide, true)) {
    $_SESSION['flash_error'] = "Richiesta non valida per la moderazione recensione.";
    header("Location: " . base_url("admin/admin_dashboard.php"));
    exit;
}

$id = (int)$idRaw;
if ($id <= 0) {
    $_SESSION['flash_error'] = "ID recensione non valido.";
    header("Location: " . base_url("admin/admin_dashboard.php"));
    exit;
}

// ============================================================
// 4) DB CONNECT
// ============================================================
$conn = db_connect();

// ============================================================
// 5) Lettura stato attuale della recensione
//      approva/rifiuta ha senso solo se la recensione è "in_attesa".
// ============================================================
$resCur = pg_query_params(
    $conn,
    "SELECT id, stato
     FROM recensioni
     WHERE id = $1
     LIMIT 1;",
    [$id]
);

$cur = $resCur ? pg_fetch_assoc($resCur) : null;

if (!$cur) {
    $_SESSION['flash_error'] = "Recensione non trovata (ID: $id).";
    redirect_back_reviews($conn);
}

$stato_attuale = (string)($cur['stato'] ?? '');

// ------------------------------------------------------------
// - Ha senso approvare/rifiutare SOLO se la recensione è ancora
//   in attesa ("in_attesa").
// ------------------------------------------------------------
if ($stato_attuale !== 'in_attesa') {
    $_SESSION['flash_error'] = "Azione non consentita: puoi approvare o rifiutare solo recensioni in attesa.";
    redirect_back_reviews($conn);
}

// ============================================================
// 6) MAPPATURA AZIONE -> STATO DB
//    Il DB accetta solo: approvato / in_attesa / rifiutato
// ============================================================
$nuovo_stato = ($azione === 'approva') ? 'approvato' : 'rifiutato';

// ============================================================
// 7) UPDATE SICURO (query parametrizzata)
// ============================================================
$sql = "
    UPDATE recensioni
    SET stato = $1
    WHERE id = $2;
";

$res = pg_query_params($conn, $sql, [$nuovo_stato, $id]);

// ============================================================
// 8) FEEDBACK (flash) + redirect
// ============================================================
if ($res) {
    $affected = pg_affected_rows($res);

    if ($affected > 0) {
        if ($azione === 'approva') {
            $_SESSION['flash_ok'] = "Recensione #$id approvata: ora è visibile in \"Dicono di noi\".";
        } else {
            $_SESSION['flash_ok'] = "Recensione #$id rifiutata.";
        }
    } else {
        // Query eseguita ma nessuna riga aggiornata (caso limite)
        $_SESSION['flash_error'] = "Nessuna recensione aggiornata (ID: $id).";
    }
} else {
    $_SESSION['flash_error'] = "Errore DB durante l'aggiornamento della recensione: " . pg_last_error($conn);
}

redirect_back_reviews($conn);
