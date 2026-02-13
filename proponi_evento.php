<?php
// FILE: proponi_evento.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/config.php';

$page_title = "Proponi evento - EnjoyCity";

// SOLO LOGGATO
if (!isset($_SESSION['logged']) || $_SESSION['logged'] !== true) {
    $_SESSION['flash_error'] = "Devi effettuare l'accesso per proporre un evento.";
    header("Location: " . base_url('login.php'));
    exit;
}

$organizzatore_id = (int)($_SESSION['user_id'] ?? 0);
if ($organizzatore_id <= 0) {
    die("Sessione non valida: user_id mancante.");
}

// Connessione
$conn = db_connect();

// Sticky
$val = [
    'titolo' => '',
    'descrizione_breve' => '',
    'descrizione_lunga' => '',
    'data_evento' => '',
    'luogo' => '',
    'latitudine' => '',
    'longitudine' => '',
    'prezzo' => '0.00',
    'posti_totali' => '',
    'prenotazione_obbligatoria' => '0',
];

$errore = "";
$successo = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    foreach ($val as $k => $_) {
        if (isset($_POST[$k])) {
            $val[$k] = trim((string)$_POST[$k]);
        }
    }
    $val['prenotazione_obbligatoria'] = isset($_POST['prenotazione_obbligatoria']) ? '1' : '0';

    // Required
    $titolo = $val['titolo'];
    $breve  = $val['descrizione_breve'];
    $lunga  = $val['descrizione_lunga'];
    $luogo  = $val['luogo'];

    if ($titolo === '' || $breve === '' || $lunga === '' || $val['data_evento'] === '' || $luogo === '') {
        $errore = "Compila tutti i campi obbligatori.";
    } elseif (mb_strlen($titolo) > 100) {
        $errore = "Il titolo può contenere al massimo 100 caratteri.";
    } elseif (mb_strlen($breve) > 255) {
        $errore = "La descrizione breve può contenere al massimo 255 caratteri.";
    } elseif (mb_strlen($luogo) > 100) {
        $errore = "Il luogo può contenere al massimo 100 caratteri.";
    } else {
        // datetime-local -> SQL timestamp
        $dataRaw = $val['data_evento'];
        $dataSql = str_replace('T', ' ', $dataRaw) . ':00';
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $dataSql);
        if (!$dt) {
            $errore = "Data/ora evento non valida.";
        }

        // Prezzo
        $prezzo = str_replace(',', '.', $val['prezzo']);
        if ($errore === '') {
            if ($prezzo === '') $prezzo = '0.00';
            if (!is_numeric($prezzo) || (float)$prezzo < 0) {
                $errore = "Prezzo non valido.";
            }
        }

        // Posti
        $postiTotali = null;

        if ($val['posti_totali'] !== '') {
            if (!ctype_digit($val['posti_totali']) || (int)$val['posti_totali'] <= 0) {
                $errore = "Posti totali deve essere un intero maggiore di 0.";
            } else {
                $postiTotali = (int)$val['posti_totali'];
            }
        }


        // Lat/Lon opzionali
        $lat = $val['latitudine'] !== '' ? str_replace(',', '.', $val['latitudine']) : null;
        $lon = $val['longitudine'] !== '' ? str_replace(',', '.', $val['longitudine']) : null;

        if ($errore === '') {
            if (($lat === null && $lon !== null) || ($lat !== null && $lon === null)) {
                $errore = "Se inserisci la geolocalizzazione devi indicare sia latitudine che longitudine.";
            } elseif ($lat !== null && $lon !== null) {
                if (!is_numeric($lat) || !is_numeric($lon)) {
                    $errore = "Latitudine/Longitudine non valide.";
                } else {
                    $latF = (float)$lat;
                    $lonF = (float)$lon;
                    if ($latF < -90 || $latF > 90 || $lonF < -180 || $lonF > 180) {
                        $errore = "Latitudine deve essere tra -90 e 90, longitudine tra -180 e 180.";
                    }
                }
            }
        }

        // Upload immagine (opzionale)
        $imgPath = null;
        if ($errore === '' && isset($_FILES['immagine']) && $_FILES['immagine']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['immagine']['error'] !== UPLOAD_ERR_OK) {
                $errore = "Errore nel caricamento dell'immagine.";
            } else {
                $tmp  = $_FILES['immagine']['tmp_name'];
                $size = (int)$_FILES['immagine']['size'];

                if ($size > 2 * 1024 * 1024) {
                    $errore = "Immagine troppo grande (max 2MB).";
                } else {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime  = $finfo->file($tmp);

                    $allowed = [
                        'image/jpeg' => 'jpg',
                        'image/png'  => 'png',
                        'image/webp' => 'webp'
                    ];

                    if (!isset($allowed[$mime])) {
                        $errore = "Formato immagine non supportato (solo JPG, PNG, WEBP).";
                    } else {
                        $ext = $allowed[$mime];
                        $dir = __DIR__ . '/uploads/eventi';
                        if (!is_dir($dir)) {
                            mkdir($dir, 0755, true);
                        }

                        $filename = 'ev_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                        $dest = $dir . '/' . $filename;

                        if (!move_uploaded_file($tmp, $dest)) {
                            $errore = "Impossibile salvare l'immagine caricata.";
                        } else {
                            $imgPath = 'uploads/eventi/' . $filename;
                        }
                    }
                }
            }
        }

        // INSERT
        if ($errore === '') {
            $sql = "
                INSERT INTO eventi
                (titolo, descrizione_breve, descrizione_lunga, immagine, data_evento, luogo,
                 latitudine, longitudine, prezzo, posti_totali, posti_prenotati,
                 prenotazione_obbligatoria, stato, organizzatore_id)
                VALUES
                ($1, $2, $3, $4, $5, $6,
                 $7, $8, $9, $10, 0,
                 $11, 'in_attesa', $12)
                RETURNING id;
            ";

            $params = [
                $titolo,
                $breve,
                $lunga,
                $imgPath,
                $dataSql,
                $luogo,
                $lat !== null ? (float)$lat : null,
                $lon !== null ? (float)$lon : null,
                (float)$prezzo,
                $postiTotali,
                ($val['prenotazione_obbligatoria'] === '1'),
                $organizzatore_id
            ];

            $res = pg_query_params($conn, $sql, $params);

            if (!$res) {
                $errore = "Errore DB: " . pg_last_error($conn);
            } else {
                $row = pg_fetch_assoc($res);
                $newId = $row ? (int)$row['id'] : 0;

                $successo = "Evento proposto correttamente! Ora è in attesa di approvazione (ID: $newId).";

                // reset
                foreach ($val as $k => $_) $val[$k] = '';
                $val['prezzo'] = '0.00';
                $val['prenotazione_obbligatoria'] = '0';
            }
        }
    }
}

db_close($conn);
?>

<?php require_once __DIR__ . '/includes/header.php'; ?>

<section class="auth">
    <div class="auth-card">
        <header class="auth-head">
            <img src="assets/img/logo.png" alt="EnjoyCity logo" class="auth-logo" onerror="this.style.display='none'">
            <h1>Proponi un evento</h1>
            <p>Compila il modulo: l’evento sarà inviato all’amministratore per l’approvazione.</p>
        </header>

        <?php if ($errore !== ""): ?>
            <div class="alert alert-error" role="alert">
                <?= e($errore) ?>
            </div>
        <?php endif; ?>

        <?php if ($successo !== ""): ?>
            <div class="alert alert-success" role="status">
                <?= e($successo) ?>
            </div>
        <?php endif; ?>

        <form id="formProponiEvento" class="auth-form" action="<?= e(base_url('proponi_evento.php')) ?>" method="POST" enctype="multipart/form-data" novalidate>

            <div class="field">
                <label for="titolo">Titolo *</label>
                <input type="text" id="titolo" name="titolo" maxlength="100" required
                       value="<?= e($val['titolo']) ?>">
                <small class="hint" id="titoloHint"></small>
            </div>

            <div class="field">
                <label for="descrizione_breve">Descrizione breve * (max 255)</label>
                <input type="text" id="descrizione_breve" name="descrizione_breve" maxlength="255" required
                       value="<?= e($val['descrizione_breve']) ?>">
                <small class="hint" id="breveHint"></small>
            </div>

            <div class="field">
                <label for="descrizione_lunga">Descrizione lunga *</label>
                <textarea id="descrizione_lunga" name="descrizione_lunga" rows="6" required><?= e($val['descrizione_lunga']) ?></textarea>
                <small class="hint" id="lungaHint"></small>
            </div>

            <div class="field">
                <label for="data_evento">Data e ora evento *</label>
                <input type="datetime-local" id="data_evento" name="data_evento" required
                       value="<?= e($val['data_evento']) ?>">
                <small class="hint" id="dataHint"></small>
            </div>

            <div class="field">
                <label for="luogo">Luogo *</label>
                <input type="text" id="luogo" name="luogo" maxlength="100" required
                       value="<?= e($val['luogo']) ?>">
                <small class="hint" id="luogoHint"></small>
            </div>

            <div class="field">
                <label for="prezzo">Prezzo (€)</label>
                <input type="text" id="prezzo" name="prezzo" inputmode="decimal"
                       value="<?= e($val['prezzo']) ?>" placeholder="0.00">
                <small class="hint" id="prezzoHint">Lascia 0 per evento gratuito.</small>
            </div>

            <div class="field">
                <label for="posti_totali">Posti totali (opzionale)</label>
                <input type="number" id="posti_totali" name="posti_totali" min="1" 
                       value="<?= e($val['posti_totali']) ?>">
                <small class="hint" id="postiHint"></small>
            </div>

            <div class="field">
                <label class="checkbox">
                    <input type="checkbox" id="prenotazione_obbligatoria" name="prenotazione_obbligatoria"
                        <?= ($val['prenotazione_obbligatoria'] === '1') ? 'checked' : '' ?>>
                    Prenotazione obbligatoria
                </label>
                <small class="hint">Se attivo, l’utente dovrà prenotare per partecipare.</small>
            </div>

            <div class="field">
                <label for="latitudine">Latitudine (opzionale)</label>
                <input type="text" id="latitudine" name="latitudine" inputmode="decimal"
                       value="<?= e($val['latitudine']) ?>" placeholder="40.852160">
                <small class="hint" id="latHint"></small>
            </div>

            <div class="field">
                <label for="longitudine">Longitudine (opzionale)</label>
                <input type="text" id="longitudine" name="longitudine" inputmode="decimal"
                       value="<?= e($val['longitudine']) ?>" placeholder="14.268110">
                <small class="hint" id="lonHint">Se inserisci la geo, compila entrambi.</small>
            </div>

            <div class="field">
                <label for="immagine">Immagine (opzionale)</label>
                <input type="file" id="immagine" name="immagine" accept=".jpg,.jpeg,.png,.webp">
                <small class="hint" id="imgHint">JPG/PNG/WEBP — max 2MB.</small>
            </div>

            <button type="submit" class="btn btn-primary w-100">Invia proposta</button>

            <p class="auth-links">
                <a href="<?= e(base_url('dashboard.php')) ?>" class="auth-links">Torna alla dashboard</a>
            </p>
        </form>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
