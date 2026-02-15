<?php
// includes/config.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* =========================
   DB CONFIG (PostgreSQL)
========================= */
$host   = 'localhost';
$port   = '5432';
$dbname = 'gruppo22';
$dbuser = 'www';
$dbpass = 'www';

$connection_string = "host=$host port=$port dbname=$dbname user=$dbuser password=$dbpass";

function db_connect()
{
    global $connection_string;

    $conn = pg_connect($connection_string);
    if (!$conn) {
        die("Errore di connessione al database.");
    }
    return $conn;
}

function db_close($conn)
{
    if ($conn) {
        pg_close($conn);
    }
}

/* =========================
   BASE URL (auto + fix /admin)
   Funziona se apri:
   /public_html/enjoycity/...
   oppure /enjoycity/...
========================= */
$BASE_URL = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if ($BASE_URL === '') $BASE_URL = '';

// Se siamo dentro /admin, risaliamo alla root del progetto
if (str_ends_with($BASE_URL, '/admin')) {
    $BASE_URL = substr($BASE_URL, 0, -6); // toglie "/admin"
}

function base_url(string $path = ''): string
{
    global $BASE_URL;
    $path = ltrim($path, '/');
    return $BASE_URL . ($path ? '/' . $path : '');
}


/* =========================
   HELPERS
========================= */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fmt_datetime($ts): string
{
    $t = strtotime((string)$ts);
    if ($t === false) return e($ts);
    return date('d/m/Y H:i', $t);
}

// --- BLOCCO UTENTE: helper riutilizzabile ---
function user_is_blocked($conn, int $user_id): array
{
    // Ritorna: ['blocked' => bool, 'until' => ?string]
    $res = pg_query_params($conn, "SELECT bloccato, bloccato_fino FROM utenti WHERE id = $1 LIMIT 1;", [$user_id]);
    $u = $res ? pg_fetch_assoc($res) : null;

    if (!$u) return ['blocked' => false, 'until' => null];

    $bl = ($u['bloccato'] === 't' || $u['bloccato'] === true || $u['bloccato'] === '1');
    $bf = $u['bloccato_fino'] ?? null;

    // blocco temporaneo attivo?
    $tempActive = false;
    if (!empty($bf)) {
        $ts = strtotime((string)$bf);
        if ($ts !== false && $ts > time()) $tempActive = true;
    }

    // se il blocco temporaneo è scaduto e bloccato era TRUE, lo “ripulisco” automaticamente
    if ($bl && !empty($bf)) {
        $ts = strtotime((string)$bf);
        if ($ts !== false && $ts <= time()) {
            pg_query_params($conn, "UPDATE utenti SET bloccato = FALSE, bloccato_fino = NULL WHERE id = $1;", [$user_id]);
            $bl = false;
            $bf = null;
        }
    }

    $blockedNow = $bl || $tempActive;

    return ['blocked' => $blockedNow, 'until' => (!empty($bf) ? (string)$bf : null)];
}
