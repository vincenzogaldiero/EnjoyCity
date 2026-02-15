<?php
// ============================================================
// FILE: admin/admin_review_action.php
// Scopo:
// - Gestire la moderazione delle recensioni lato admin (approva/rifiuta)
// - Dopo "approva": la recensione deve comparire in "Dicono di noi"
// - DB (schema gruppo22): recensioni.stato ∈ ('approvato','in_attesa','rifiutato')
// Tecniche usate:
// - pg_query_params per query parametrizzate (anti SQL Injection)
// - flash messages in sessione per feedback all'admin
// ============================================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';

// Avvio sessione se non già attiva
if (session_status() === PHP_SESSION_NONE) {
    session_start();
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
// 2) LETTURA INPUT (POST) + VALIDAZIONE
// - id: deve essere un intero > 0
// - azione: 'approva' oppure 'rifiuta'
// Nota: normalizzo l'azione in lowercase per accettare anche input tipo "APPROVA"
// ============================================================
$id = (int)($_POST['id'] ?? 0);
$azione = strtolower(trim((string)($_POST['azione'] ?? '')));

if ($id <= 0 || ($azione !== 'approva' && $azione !== 'rifiuta')) {
    $_SESSION['flash_error'] = "Richiesta non valida.";
    header("Location: " . base_url("admin/admin_dashboard.php"));
    exit;
}

// ============================================================
// 3) DB CONNECT
// ============================================================
$conn = db_connect();

// ============================================================
// 4) MAPPATURA AZIONE -> STATO DB
// - Il DB accetta solo: approvato / in_attesa / rifiutato
// - Quindi NON usare "APPROVATA" o varianti: non passano il CHECK e/o non matchano le query
// ============================================================
$nuovo_stato = ($azione === 'approva') ? 'approvato' : 'rifiutato';

// ============================================================
// 5) UPDATE SICURO (query parametrizzata)
// ============================================================
$sql = "UPDATE recensioni
        SET stato = $1
        WHERE id = $2;";

$res = pg_query_params($conn, $sql, [$nuovo_stato, $id]);

// ============================================================
// 6) FEEDBACK (flash) + redirect
// ============================================================
if ($res) {
    // pg_affected_rows: utile per capire se l'ID esisteva davvero
    $affected = pg_affected_rows($res);

    if ($affected > 0) {
        if ($azione === 'approva') {
            $_SESSION['flash_ok'] = "Recensione #$id approvata: ora è visibile in 'Dicono di noi'.";
        } else {
            $_SESSION['flash_ok'] = "Recensione #$id rifiutata.";
        }
    } else {
        // Query OK ma nessuna riga aggiornata -> ID non trovato
        $_SESSION['flash_error'] = "Nessuna recensione trovata con ID #$id.";
    }
} else {
    // Errore DB (query fallita)
    $_SESSION['flash_error'] = "Errore aggiornamento recensione: " . pg_last_error($conn);
}

// ============================================================
// 7) CHIUSURA CONNESSIONE + REDIRECT
// ============================================================
db_close($conn);

header("Location: " . base_url("admin/admin_dashboard.php"));
exit;
