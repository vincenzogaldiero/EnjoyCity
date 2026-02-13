<?php

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
