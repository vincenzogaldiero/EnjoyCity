<?php
// =========================================================
// FILE: admin/admin_event_action.php
// Scopo didattico:
// - Endpoint POST per azioni rapide sugli eventi (moderazione + lifecycle)
// - Pattern PRG: POST -> redirect (evita doppio submit)
// - Sicurezza:
//   - Solo admin
//   - Solo POST
//   - Validazione parametri (id + azione in whitelist)
// - Coerenza con DB "pulito":
//   - Moderazione: eventi.stato (approvato / in_attesa / rifiutato)
//   - Lifecycle: eventi.archiviato (true/false) + eventi.stato_evento (attivo/annullato)
// - Business rules (da spiegare alla prof):
//   - Non ha senso archiviare/annullare eventi non approvati (non sono "pubblici").
//   - Non ha senso riattivare un evento passato.
//   - Approva/Rifiuta solo su eventi in attesa (moderazione vera).
// =========================================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// =========================================================
// 1) Guard: SOLO ADMIN
// =========================================================
if (!isset($_SESSION['logged']) || $_SESSION['logged'] !== true || ($_SESSION['ruolo'] ?? '') !== 'admin') {
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
// 3) Parametri + whitelist
// =========================================================
$id     = $_POST['id'] ?? '';
$azione = (string)($_POST['azione'] ?? '');

$azioni_valide = [
    'approva',       // stato -> approvato
    'rifiuta',       // stato -> rifiutato
    'archivia',      // archiviato -> true
    'ripristina',    // archiviato -> false
    'annulla',       // stato_evento -> annullato
    'riattiva'       // stato_evento -> attivo
];

if (!ctype_digit((string)$id) || !in_array($azione, $azioni_valide, true)) {
    $_SESSION['flash_error'] = "Parametri non validi.";
    header("Location: " . base_url("admin/admin_dashboard.php"));
    exit;
}

$event_id = (int)$id;

// =========================================================
// 4) Connessione + lettura stato attuale evento
// - Prima leggiamo lo stato attuale per applicare regole di coerenza.
// =========================================================
$conn = db_connect();

$resCur = pg_query_params($conn, "
    SELECT id, stato, archiviato, stato_evento, data_evento
    FROM eventi
    WHERE id = $1
    LIMIT 1;
", [$event_id]);

$cur = $resCur ? pg_fetch_assoc($resCur) : null;

if (!$cur) {
    db_close($conn);
    $_SESSION['flash_error'] = "Evento non trovato (ID: $event_id).";
    // PRG redirect
    $back = $_SERVER['HTTP_REFERER'] ?? '';
    if ($back === '' || strpos($back, 'admin/') === false) $back = base_url("admin/admin_dashboard.php");
    header("Location: " . $back);
    exit;
}

// Normalizzo i valori letti dal DB (PostgreSQL boolean 't'/'f')
$stato        = (string)($cur['stato'] ?? '');
$archiviato   = (($cur['archiviato'] ?? 'f') === 't' || $cur['archiviato'] === true || $cur['archiviato'] === '1');
$stato_evento = (string)($cur['stato_evento'] ?? 'attivo');
$data_evento  = (string)($cur['data_evento'] ?? '');

// utile per regole sul tempo
$evento_ts = strtotime($data_evento);
$now_ts    = time();
$is_future = ($evento_ts !== false && $evento_ts >= $now_ts);

// =========================================================
// 5) Regole di coerenza (business rules)
// - Qui evitiamo update "assurdi" e rendiamo il progetto più professionale.
// =========================================================
$errore = "";

// Moderazione: approva/rifiuta SOLO se in_attesa
if (in_array($azione, ['approva', 'rifiuta'], true)) {
    if ($stato !== 'in_attesa') {
        $errore = "Azione non consentita: puoi approvare/rifiutare solo eventi in attesa.";
    }
}

// Lifecycle: archivia/ripristina SOLO se approvato
if ($errore === "" && in_array($azione, ['archivia', 'ripristina'], true)) {
    if ($stato !== 'approvato') {
        $errore = "Azione non consentita: puoi archiviare/ripristinare solo eventi approvati.";
    }
}

// Lifecycle: annulla/riattiva SOLO se approvato
if ($errore === "" && in_array($azione, ['annulla', 'riattiva'], true)) {
    if ($stato !== 'approvato') {
        $errore = "Azione non consentita: puoi annullare/riattivare solo eventi approvati.";
    }
}

// Riattiva: ha senso solo se evento futuro (se è passato è “concluso”)
if ($errore === "" && $azione === 'riattiva') {
    if (!$is_future) {
        $errore = "Non puoi riattivare un evento passato: risulta concluso (storico).";
    }
}

// Se ho errore, stoppo qui (PRG)
if ($errore !== "") {
    db_close($conn);
    $_SESSION['flash_error'] = $errore;

    $back = $_SERVER['HTTP_REFERER'] ?? '';
    if ($back === '' || strpos($back, 'admin/') === false) $back = base_url("admin/admin_dashboard.php");
    header("Location: " . $back);
    exit;
}

// =========================================================
// 6) Costruzione UPDATE (guidato, sicuro, senza SQL dinamico)
// =========================================================
$sql    = "";
$params = [$event_id];

switch ($azione) {

    // -------------------------
    // Moderazione
    // -------------------------
    case 'approva':
        // Nota: quando approvo non obbligo archiviato/stato_evento.
        // Il "pubblico" lo vedrà solo se: futuro + attivo + non archiviato
        $sql = "UPDATE eventi SET stato = 'approvato' WHERE id = $1;";
        break;

    case 'rifiuta':
        $sql = "UPDATE eventi SET stato = 'rifiutato' WHERE id = $1;";
        break;

    // -------------------------
    // Lifecycle
    // -------------------------
    case 'archivia':
        // Archivia = rimuovo dalle liste pubbliche (ma resta in DB per audit)
        $sql = "UPDATE eventi SET archiviato = TRUE WHERE id = $1;";
        break;

    case 'ripristina':
        $sql = "UPDATE eventi SET archiviato = FALSE WHERE id = $1;";
        break;

    case 'annulla':
        // Annulla = evento “non più valido” (non prenotabile, non in vigore)
        // Nota: puoi decidere se annullare implica anche archiviare.
        // Io NON lo faccio automaticamente: sono concetti distinti.
        $sql = "UPDATE eventi SET stato_evento = 'annullato' WHERE id = $1;";
        break;

    case 'riattiva':
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
        'riattiva'    => 'riattivato'
    ];
    $label = $mapLabel[$azione] ?? 'aggiornato';
    $_SESSION['flash_ok'] = "Evento #$event_id $label.";
} else {
    $_SESSION['flash_error'] = "Errore DB: " . pg_last_error($conn);
}

db_close($conn);

// =========================================================
// 8) Redirect PRG "intelligente"
// - torna alla pagina precedente se arriva dall'admin
// - altrimenti dashboard
// =========================================================
$back = $_SERVER['HTTP_REFERER'] ?? '';
if ($back === '' || strpos($back, 'admin/') === false) {
    $back = base_url("admin/admin_dashboard.php");
}
header("Location: " . $back);
exit;
