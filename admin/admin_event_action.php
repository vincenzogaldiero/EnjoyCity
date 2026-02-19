<?php
// =========================================================
// FILE: admin/admin_event_action.php
//
// Utilizzato per le AZIONI RAPIDE sugli eventi eseguite da area admin.
// Regole implementate:
// - Approva/Rifiuta      solo su eventi in_attesa (moderazione reale).
// - Archivia/Ripristina  solo su eventi approvati (eventi pubblicati).
// - Annulla/Riattiva     solo su eventi approvati.
// - Riattiva             solo se l’evento è futuro (un evento passato è “concluso”).
// =========================================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------------------------------------------------------
// Helper: redirect verso la pagina admin
// di provenienza (HTTP_REFERER), con fallback alla dashboard.
// ---------------------------------------------------------
function redirect_back_admin(string $fallbackRel = 'admin/admin_dashboard.php'): void
{
    $back = $_SERVER['HTTP_REFERER'] ?? '';
    if ($back === '' || strpos($back, 'admin/') === false) {
        $back = base_url($fallbackRel);
    }
    header("Location: " . $back);
    exit;
}

// =========================================================
// 1) Guard: SOLO ADMIN
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
// 2) Guard: SOLO POST (niente azioni via URL)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: " . base_url("admin/admin_dashboard.php"));
    exit;
}

// =========================================================
// 3) Parametri +  azioni
// =========================================================
$id     = $_POST['id']     ?? '';
$azione = (string)($_POST['azione'] ?? '');

$azioni_valide = [
    'approva',     // moderazione:   stato -> approvato
    'rifiuta',     // moderazione:   stato -> rifiutato
    'archivia',    // lifecycle:     archiviato -> TRUE
    'ripristina',  // lifecycle:     archiviato -> FALSE
    'annulla',     // lifecycle:     stato_evento -> annullato
    'riattiva'     // lifecycle:     stato_evento -> attivo
];

if (!ctype_digit((string)$id) || !in_array($azione, $azioni_valide, true)) {
    $_SESSION['flash_error'] = "Parametri non validi per l’azione sugli eventi.";
    header("Location: " . base_url("admin/admin_dashboard.php"));
    exit;
}

$event_id = (int)$id;

// =========================================================
// 4) Connessione + lettura stato attuale evento
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

if (!$cur) {
    db_close($conn);
    $_SESSION['flash_error'] = "Evento non trovato (ID: $event_id).";
    redirect_back_admin();
}

// Normalizzo i valori letti dal DB (PostgreSQL boolean 't'/'f')
$stato        = (string)($cur['stato'] ?? '');
$archiviato   = (
    ($cur['archiviato'] ?? 'f') === 't' ||
    $cur['archiviato'] === true ||
    $cur['archiviato'] === '1'
);
$stato_evento = (string)($cur['stato_evento'] ?? 'attivo');
$data_evento  = (string)($cur['data_evento'] ?? '');

// Utile per le regole temporali (es: riattiva solo su futuri)
$evento_ts = strtotime($data_evento);
$now_ts    = time();
$is_future = ($evento_ts !== false && $evento_ts >= $now_ts);

// =========================================================
// 5) Regole di coerenza
// =========================================================
$errore = "";

// Moderazione: approva/rifiuta SOLO se in_attesa
if (in_array($azione, ['approva', 'rifiuta'], true)) {
    if ($stato !== 'in_attesa') {
        $errore = "Azione non consentita: puoi approvare o rifiutare solo eventi in attesa.";
    }
}

// Lifecycle: archivia/ripristina SOLO se approvato
if ($errore === "" && in_array($azione, ['archivia', 'ripristina'], true)) {
    if ($stato !== 'approvato') {
        $errore = "Azione non consentita: puoi archiviare o ripristinare solo eventi approvati.";
    }
}

// Lifecycle: annulla/riattiva SOLO se approvato
if ($errore === "" && in_array($azione, ['annulla', 'riattiva'], true)) {
    if ($stato !== 'approvato') {
        $errore = "Azione non consentita: puoi annullare o riattivare solo eventi approvati.";
    }
}

// Riattiva: ha senso solo se evento futuro (se passato è “concluso”)
if ($errore === "" && $azione === 'riattiva') {
    if (!$is_future) {
        $errore = "Non puoi riattivare un evento già passato: risulta concluso.";
    }
}

// Se ho errore, interrompo qui (PRG + messaggio)
if ($errore !== "") {
    db_close($conn);
    $_SESSION['flash_error'] = $errore;
    redirect_back_admin();
}

// =========================================================
// 6) Costruzione UPDATE 
// =========================================================
$sql    = "";
$params = [$event_id];

switch ($azione) {

    // -------------------------
    // Moderazione
    // -------------------------
    case 'approva':
        // NB: non forziamo stato_evento qui:
        //     la “pubblicazione” dipende anche da data_evento, archiviato, stato_evento.
        $sql = "UPDATE eventi SET stato = 'approvato' WHERE id = $1;";
        break;

    case 'rifiuta':
        $sql = "UPDATE eventi SET stato = 'rifiutato' WHERE id = $1;";
        break;

    // -------------------------
    // Lifecycle (visibilità e stato operativo)
    // -------------------------
    case 'archivia':
        // Archivia = rimuovo dalle liste pubbliche, ma NON elimino dal DB.
        $sql = "UPDATE eventi SET archiviato = TRUE WHERE id = $1;";
        break;

    case 'ripristina':
        // Ripristina = torna gestibile come evento “normale” (se i filtri lo includono).
        $sql = "UPDATE eventi SET archiviato = FALSE WHERE id = $1;";
        break;

    case 'annulla':
        // Annulla = evento non più valido/erogato (non prenotabile, non attivo).
        $sql = "UPDATE eventi SET stato_evento = 'annullato' WHERE id = $1;";
        break;

    case 'riattiva':
        // Riattiva = stato operativo “attivo” (solo per eventi futuri, vedi regola sopra).
        $sql = "UPDATE eventi SET stato_evento = 'attivo' WHERE id = $1;";
        break;
}

// =========================================================
// 7) Esecuzione DB + messaggi flash
// =========================================================
$resUp = pg_query_params($conn, $sql, $params);

if ($resUp) {
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
// 8) Redirect PRG verso la pagina di provenienza
// =========================================================
redirect_back_admin();
