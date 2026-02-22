<?php
// =========================================================
// FILE: admin/admin_event_action.php
//
// AREA ADMIN - Azioni rapide sugli eventi
// Questo script gestisce tutte le operazioni rapide che l’amministratore può effettuare sugli eventi.
//
// È un endpoint server-side:
// - non produce HTML
// - riceve dati via POST
// - applica regole di coerenza
// - aggiorna il database
// - imposta messaggi flash
// - reindirizza (pattern PRG)
//
// Regole implementate:
// - Approva/Rifiuta      → solo su eventi in_attesa
// - Archivia/Ripristina  → solo su eventi approvati
// - Annulla/Riattiva     → solo su eventi approvati
// - Riattiva             → solo se evento futuro
// =========================================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Inclusione configurazione generale:
// - connessione PostgreSQL
// - funzione base_url()
// - gestione sessioni
require_once __DIR__ . '/../includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------------------------------------------------------
// Helper: redirect_back_admin()
// ---------------------------------------------------------
// Reindirizza l’admin alla pagina di provenienza
// (HTTP_REFERER), ma solo se è una pagina admin.
// In caso contrario → fallback alla dashboard.
// ---------------------------------------------------------
function redirect_back_admin(string $fallbackRel = 'admin/admin_dashboard.php'): void
{
    $back = $_SERVER['HTTP_REFERER'] ?? '';

    // Sicurezza: evito redirect verso pagine non admin
    if ($back === '' || strpos($back, 'admin/') === false) {
        $back = base_url($fallbackRel);
    }

    header("Location: " . $back);
    exit;
}

// =========================================================
// 1) AUTHORIZATION GUARD (SOLO ADMIN)
// Verifico che:
// - l’utente sia autenticato
// - il ruolo sia 'admin'
//
// Protegge l’endpoint da accessi diretti non autorizzati.
// =========================================================
if (
    !isset($_SESSION['logged']) ||
    $_SESSION['logged'] !== true ||
    ($_SESSION['ruolo'] ?? '') !== 'admin'
) {
    $_SESSION['flash_error'] = "Accesso non autorizzato.";
    header("Location: " . base_url("login.php"));
    exit;
}

// =========================================================
// 2) METHOD GUARD (SOLO POST)
// Le azioni amministrative NON devono essere richiamabili
// tramite GET (URL manipolabile).
// =========================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: " . base_url("admin/admin_dashboard.php"));
    exit;
}

// =========================================================
// 3) LETTURA PARAMETRI + VALIDAZIONE
// - id evento → deve essere intero positivo
// - azione    → deve appartenere a una whitelist
// =========================================================
$id     = $_POST['id']     ?? '';
$azione = (string)($_POST['azione'] ?? '');

// Whitelist delle azioni consentite
$azioni_valide = [
    'approva',
    'rifiuta',
    'archivia',
    'ripristina',
    'annulla',
    'riattiva'
];

// Validazione input (difesa contro richieste manipolate)
if (!ctype_digit((string)$id) || !in_array($azione, $azioni_valide, true)) {
    $_SESSION['flash_error'] = "Parametri non validi per l’azione sugli eventi.";
    header("Location: " . base_url("admin/admin_dashboard.php"));
    exit;
}

$event_id = (int)$id;

// =========================================================
// 4) CONNESSIONE DB + LETTURA STATO ATTUALE EVENTO
// Recupero tutte le informazioni necessarie per applicare
// le regole di coerenza.
// =========================================================
$conn = db_connect();

$resCur = pg_query_params(
    $conn,
    "SELECT id, stato, archiviato, stato_evento, data_evento
     FROM eventi
     WHERE id = $1
     LIMIT 1;",
    [$event_id]
);

$cur = $resCur ? pg_fetch_assoc($resCur) : null;

// Se l’evento non esiste → errore
if (!$cur) {
    db_close($conn);
    $_SESSION['flash_error'] = "Evento non trovato (ID: $event_id).";
    redirect_back_admin();
}

// ---------------------------------------------------------
// Normalizzazione valori dal DB
// PostgreSQL restituisce boolean come 't'/'f'
// ---------------------------------------------------------
$stato        = (string)($cur['stato'] ?? '');
$archiviato   = (
    ($cur['archiviato'] ?? 'f') === 't' ||
    $cur['archiviato'] === true ||
    $cur['archiviato'] === '1'
);
$stato_evento = (string)($cur['stato_evento'] ?? 'attivo');
$data_evento  = (string)($cur['data_evento'] ?? '');

// ---------------------------------------------------------
// Controllo temporale:
// utile per stabilire se l’evento è futuro o passato
// ---------------------------------------------------------
$evento_ts = strtotime($data_evento);
$now_ts    = time();
$is_future = ($evento_ts !== false && $evento_ts >= $now_ts);

// =========================================================
// 5) BUSINESS RULES (REGOLE DI COERENZA)
// Ogni azione è subordinata allo stato corrente.
// Questo evita transizioni incoerenti.
// =========================================================
$errore = "";

// Moderazione → solo se in_attesa
if (in_array($azione, ['approva', 'rifiuta'], true)) {
    if ($stato !== 'in_attesa') {
        $errore = "Azione non consentita: puoi approvare o rifiutare solo eventi in attesa.";
    }
}

// Archivia/Ripristina → solo se approvato
if ($errore === "" && in_array($azione, ['archivia', 'ripristina'], true)) {
    if ($stato !== 'approvato') {
        $errore = "Azione non consentita: puoi archiviare o ripristinare solo eventi approvati.";
    }
}

// Annulla/Riattiva → solo se approvato
if ($errore === "" && in_array($azione, ['annulla', 'riattiva'], true)) {
    if ($stato !== 'approvato') {
        $errore = "Azione non consentita: puoi annullare o riattivare solo eventi approvati.";
    }
}

// Riattiva → solo evento futuro
if ($errore === "" && $azione === 'riattiva') {
    if (!$is_future) {
        $errore = "Non puoi riattivare un evento già passato: risulta concluso.";
    }
}

// Se ho un errore → interrompo (PRG)
if ($errore !== "") {
    db_close($conn);
    $_SESSION['flash_error'] = $errore;
    redirect_back_admin();
}

// =========================================================
// 6) COSTRUZIONE QUERY UPDATE
// In base all’azione costruisco la query corretta.
// =========================================================
$sql    = "";
$params = [$event_id];

switch ($azione) {

    // -------------------------
    // Moderazione contenuto
    // -------------------------
    case 'approva':
        $sql = "UPDATE eventi SET stato = 'approvato' WHERE id = $1;";
        break;

    case 'rifiuta':
        $sql = "UPDATE eventi SET stato = 'rifiutato' WHERE id = $1;";
        break;

    // -------------------------
    // Stato evento
    // -------------------------
    case 'archivia':
        // Rimuove dalle liste pubbliche senza cancellare
        $sql = "UPDATE eventi SET archiviato = TRUE WHERE id = $1;";
        break;

    case 'ripristina':
        $sql = "UPDATE eventi SET archiviato = FALSE WHERE id = $1;";
        break;

    case 'annulla':
        // Evento non più attivo/erogabile
        $sql = "UPDATE eventi SET stato_evento = 'annullato' WHERE id = $1;";
        break;

    case 'riattiva':
        // Evento torna operativo (solo se futuro)
        $sql = "UPDATE eventi SET stato_evento = 'attivo' WHERE id = $1;";
        break;
}

// =========================================================
// 7) ESECUZIONE QUERY + FLASH MESSAGE
// Uso di pg_query_params → prevenzione SQL Injection.
// =========================================================
$resUp = pg_query_params($conn, $sql, $params);

if ($resUp) {

    // Mappatura etichette leggibili
    $mapLabel = [
        'approva'     => 'approvato',
        'rifiuta'     => 'rifiutato',
        'archivia'    => 'archiviato',
        'ripristina'  => 'ripristinato',
        'annulla'     => 'annullato',
        'riattiva'    => 'riattivato',
    ];

    $label = $mapLabel[$azione] ?? 'aggiornato';
    $_SESSION['flash_ok'] = "Evento #$event_id $label correttamente.";
} else {
    $_SESSION['flash_error'] = "Errore DB durante l’aggiornamento dell’evento: " . pg_last_error($conn);
}

db_close($conn);

// =========================================================
// 8) REDIRECT (Pattern PRG)
// Post → Redirect → Get
// Evita doppio invio del form e mantiene pulita la UX.
// =========================================================
redirect_back_admin();
