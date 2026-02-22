<?php
// ============================================================
// FILE: admin/admin_review_action.php
//
// AREA ADMIN - Moderazione recensioni
// ------------------------------------------------------------
// Questo script gestisce le azioni di moderazione delle
// recensioni lato amministratore.
//
// Azioni consentite:
// - approva  → la recensione diventa visibile pubblicamente
// - rifiuta  → la recensione non sarà pubblicata
//
// Il file NON genera output HTML:
// - esegue logica server-side
// - aggiorna il database
// - imposta messaggi flash in sessione
// - effettua redirect (pattern PRG: Post → Redirect → Get)
// ============================================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Inclusione configurazione generale:
// - connessione DB PostgreSQL
// - funzione base_url()
// - gestione sessioni
require_once __DIR__ . '/../includes/config.php';

// Avvio sessione se non già attiva
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ------------------------------------------------------------
// Helper: redirect_back_reviews()
// ------------------------------------------------------------
// Funzione di utilità per:
// - chiudere la connessione al DB (se aperta)
// - riportare l’admin alla pagina precedente
// - fallback sicuro verso la dashboard admin
//
// Questo evita redirect hardcoded e migliora UX.
// ------------------------------------------------------------
function redirect_back_reviews($conn = null): void
{
    // Se esiste una connessione aperta, la chiudiamo
    if ($conn) {
        db_close($conn);
    }

    // Recupero pagina di provenienza
    $back = $_SERVER['HTTP_REFERER'] ?? '';

    // Sicurezza: se il referer non è in area admin
    // oppure è vuoto, reindirizzo alla dashboard
    if ($back === '' || strpos($back, 'admin/') === false) {
        $back = base_url("admin/admin_dashboard.php");
    }

    header("Location: " . $back);
    exit;
}

// ============================================================
// 1) AUTHORIZATION GUARD
// ------------------------------------------------------------
// Accesso consentito SOLO a utenti:
// - autenticati
// - con ruolo = 'admin'
//
// Questo controllo protegge l’endpoint da accessi diretti
// non autorizzati.
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
// 2) METHOD GUARD
// ------------------------------------------------------------
// Le azioni di moderazione devono arrivare SOLO via POST.
// Evitiamo che un utente possa approvare/rifiutare tramite
// semplice URL (GET).
// ============================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: " . base_url("admin/admin_dashboard.php"));
    exit;
}

// ============================================================
// 3) LETTURA INPUT + VALIDAZIONE
// ------------------------------------------------------------
// - id recensione: deve essere intero positivo
// - azione: deve appartenere alla whitelist consentita
// ============================================================

// Recupero dati POST
$idRaw  = $_POST['id'] ?? '';
$azione = strtolower(trim((string)($_POST['azione'] ?? '')));

// Whitelist azioni consentite (approccio sicuro)
$azioni_valide = ['approva', 'rifiuta'];

// Validazione ID e azione
if (!ctype_digit((string)$idRaw) || !in_array($azione, $azioni_valide, true)) {
    $_SESSION['flash_error'] = "Richiesta non valida per la moderazione recensione.";
    header("Location: " . base_url("admin/admin_dashboard.php"));
    exit;
}

// Conversione a intero
$id = (int)$idRaw;

// Ulteriore controllo su ID > 0
if ($id <= 0) {
    $_SESSION['flash_error'] = "ID recensione non valido.";
    header("Location: " . base_url("admin/admin_dashboard.php"));
    exit;
}

// ============================================================
// 4) CONNESSIONE AL DATABASE
// ============================================================
$conn = db_connect();

// ============================================================
// 5) LETTURA STATO ATTUALE RECENSIONE
// ------------------------------------------------------------
// È possibile approvare o rifiutare SOLO recensioni
// che si trovano nello stato "in_attesa".
// ============================================================

$resCur = pg_query_params(
    $conn,
    "SELECT id, stato
     FROM recensioni
     WHERE id = $1
     LIMIT 1;",
    [$id]
);

// Recupero risultato come array associativo
$cur = $resCur ? pg_fetch_assoc($resCur) : null;

// Se la recensione non esiste → errore
if (!$cur) {
    $_SESSION['flash_error'] = "Recensione non trovata (ID: $id).";
    redirect_back_reviews($conn);
}

$stato_attuale = (string)($cur['stato'] ?? '');

// ------------------------------------------------------------
// Regola di business:
// L'admin può agire SOLO su recensioni ancora in attesa.
// ------------------------------------------------------------
if ($stato_attuale !== 'in_attesa') {
    $_SESSION['flash_error'] = "Azione non consentita: puoi approvare o rifiutare solo recensioni in attesa.";
    redirect_back_reviews($conn);
}

// ============================================================
// 6) MAPPATURA AZIONE → NUOVO STATO DB
// ------------------------------------------------------------
// Valori ammessi nel DB:
// - approvato
// - in_attesa
// - rifiutato
// ============================================================

$nuovo_stato = ($azione === 'approva') ? 'approvato' : 'rifiutato';

// ============================================================
// 7) UPDATE SICURO (Query Parametrizzata)
// ------------------------------------------------------------
// Uso di pg_query_params per prevenire SQL Injection.
// ============================================================

$sql = "
    UPDATE recensioni
    SET stato = $1
    WHERE id = $2;
";

$res = pg_query_params($conn, $sql, [$nuovo_stato, $id]);

// ============================================================
// 8) FEEDBACK (FLASH MESSAGE) + REDIRECT
// ------------------------------------------------------------
// Pattern PRG:
// - elaboro POST
// - salvo messaggio in sessione
// - reindirizzo alla pagina precedente
// ============================================================

if ($res) {

    // Numero righe aggiornate
    $affected = pg_affected_rows($res);

    if ($affected > 0) {

        if ($azione === 'approva') {
            $_SESSION['flash_ok'] = "Recensione #$id approvata: ora è visibile in \"Dicono di noi\".";
        } else {
            $_SESSION['flash_ok'] = "Recensione #$id rifiutata.";
        }
    } else {
        // Caso limite: UPDATE eseguito ma nessuna riga modificata
        $_SESSION['flash_error'] = "Nessuna recensione aggiornata (ID: $id).";
    }
} else {
    // Errore a livello database
    $_SESSION['flash_error'] = "Errore DB durante l'aggiornamento della recensione: " . pg_last_error($conn);
}

// Redirect finale con chiusura connessione
redirect_back_reviews($conn);
