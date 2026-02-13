<?php
// includes/functions.php

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
