<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

/*
 * Calcola automaticamente la base path del progetto.
 * Funziona anche se il progetto sta in /enjoycity oppure in / oppure in /qualcosa.
 */
$BASE_URL = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if ($BASE_URL === '') $BASE_URL = '';

function base_url(string $path = ''): string
{
    global $BASE_URL;
    $path = ltrim($path, '/');
    return $BASE_URL . ($path ? '/' . $path : '');
}


function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fmt_datetime($ts)
{
    // $ts può arrivare come stringa timestamp da PostgreSQL
    $t = strtotime($ts);
    if ($t === false) return e($ts);
    return date('d/m/Y H:i', $t);
}
