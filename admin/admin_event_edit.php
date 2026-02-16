<?php
// =========================================================
// FILE: admin/admin_event_edit.php
// Scopo didattico:
// - Form Admin per modifica evento
// - Validazioni server-side (sempre!)
// - Coerenza con stato evento:
//   - stato: approvato / in_attesa / rifiutato (moderazione)
//   - stato_evento: attivo / annullato (stato evento operativo)
//   - archiviato: true/false (soft delete / storico)
// - DB pulito per eventi informativi:
//   - posti_totali = NULL => informativo (no prenotazioni)
//   - posti_totali > 0    => a posti limitati
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
    header("Location: " . base_url('login.php'));
    exit;
}

$page_title = "Modifica evento - Area Admin";

$conn = db_connect();

// ---------------------------------------------------------
// Utility: boolean postgres ('t'/'f') -> bool
// ---------------------------------------------------------
function is_true_pg($v): bool
{
    return ($v === 't' || $v === true || $v === '1' || $v === 1);
}

// ---------------------------------------------------------
// Utility: escape output
// (se non hai già e() nel tuo progetto, lascialo qui)
// ---------------------------------------------------------
if (!function_exists('e')) {
    function e($s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

// ---------------------------------------------------------
// 2) ID evento (GET)
// ---------------------------------------------------------
$idRaw = $_GET['id'] ?? '';
if (!ctype_digit((string)$idRaw)) {
    $_SESSION['flash_error'] = "ID evento non valido.";
    db_close($conn);
    header("Location: " . base_url('admin/admin_eventi.php'));
    exit;
}
$event_id = (int)$idRaw;

// ---------------------------------------------------------
// 3) Categorie per select
// ---------------------------------------------------------
$categorie = [];
$resCat = pg_query($conn, "SELECT id, nome FROM categorie ORDER BY nome;");
if ($resCat) {
    while ($row = pg_fetch_assoc($resCat)) $categorie[] = $row;
}

// ---------------------------------------------------------
// 4) Carico evento
// ---------------------------------------------------------
$resEv = pg_query_params($conn, "SELECT * FROM eventi WHERE id = $1 LIMIT 1;", [$event_id]);
$evento = $resEv ? pg_fetch_assoc($resEv) : null;

if (!$evento) {
    $_SESSION['flash_error'] = "Evento non trovato.";
    db_close($conn);
    header("Location: " . base_url('admin/admin_eventi.php'));
    exit;
}

// ---------------------------------------------------------
// 5) Valori "sticky" (precompilati)
// - posti_totali NULL => informativo => campo vuoto nel form
// ---------------------------------------------------------
$val = [
    'titolo' => (string)($evento['titolo'] ?? ''),
    'descrizione_breve' => (string)($evento['descrizione_breve'] ?? ''),
    'descrizione_lunga' => (string)($evento['descrizione_lunga'] ?? ''),
    'data_evento' => '',
    'luogo' => (string)($evento['luogo'] ?? ''),
    'categoria_id' => (string)($evento['categoria_id'] ?? ''),
    'latitudine' => ($evento['latitudine'] === null ? '' : (string)$evento['latitudine']),
    'longitudine' => ($evento['longitudine'] === null ? '' : (string)$evento['longitudine']),
    'prezzo' => (string)($evento['prezzo'] ?? '0.00'),

    // DB pulito: NULL => informativo => mostro vuoto
    'posti_totali' => ($evento['posti_totali'] === null ? '' : (string)$evento['posti_totali']),

    'prenotazione_obbligatoria' => is_true_pg($evento['prenotazione_obbligatoria'] ?? false) ? '1' : '0',
    'stato' => (string)($evento['stato'] ?? 'in_attesa'),

    'stato_evento' => (string)($evento['stato_evento'] ?? 'attivo'),
    'archiviato' => is_true_pg($evento['archiviato'] ?? false) ? '1' : '0',
];

// timestamp -> datetime-local
$ts = (string)($evento['data_evento'] ?? '');
$t = strtotime($ts);
$val['data_evento'] = $t ? date('Y-m-d\TH:i', $t) : '';

// immagine attuale
$imgOld = (string)($evento['immagine'] ?? '');

$errore = "";

// ---------------------------------------------------------
// 6) POST update (validazioni + update DB)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 6.1 aggiorno sticky values
    foreach ($val as $k => $_) {
        if (isset($_POST[$k])) $val[$k] = trim((string)$_POST[$k]);
    }

    // checkbox: se non presente => 0
    $val['prenotazione_obbligatoria'] = isset($_POST['prenotazione_obbligatoria']) ? '1' : '0';
    $val['archiviato'] = isset($_POST['archiviato']) ? '1' : '0';

    // 6.2 estrazione
    $titolo = $val['titolo'];
    $breve  = $val['descrizione_breve'];
    $lunga  = $val['descrizione_lunga'];
    $luogo  = $val['luogo'];
    $catRaw = $val['categoria_id'];

    $stato  = $val['stato'];              // moderazione
    $stato_evento = $val['stato_evento']; // stato evento

    // ✅ FIX: archiviato sempre come boolean PG ('t'/'f')
    $archiviato_pg = ($val['archiviato'] === '1') ? 't' : 'f';

    // 6.3 validazioni testi e select
    if ($titolo === '' || $breve === '' || $lunga === '' || $val['data_evento'] === '' || $luogo === '' || $catRaw === '' || $stato === '') {
        $errore = "Compila tutti i campi obbligatori (inclusa categoria e stato).";
    } elseif (mb_strlen($titolo) > 100) {
        $errore = "Il titolo può contenere al massimo 100 caratteri.";
    } elseif (mb_strlen($breve) > 255) {
        $errore = "La descrizione breve può contenere al massimo 255 caratteri.";
    } elseif (mb_strlen($luogo) > 100) {
        $errore = "Il luogo può contenere al massimo 100 caratteri.";
    } elseif (!ctype_digit($catRaw)) {
        $errore = "Categoria non valida.";
    } elseif (!in_array($stato, ['approvato', 'in_attesa', 'rifiutato'], true)) {
        $errore = "Stato non valido.";
    } elseif (!in_array($stato_evento, ['attivo', 'annullato'], true)) {
        $errore = "Stato evento non valido.";
    }

    // 6.4 categoria esiste
    $categoria_id = null;
    if ($errore === '') {
        $categoria_id = (int)$catRaw;
        $resCheck = pg_query_params($conn, "SELECT 1 FROM categorie WHERE id = $1 LIMIT 1;", [$categoria_id]);
        if (!$resCheck || pg_num_rows($resCheck) !== 1) $errore = "Categoria non valida.";
    }

    // 6.5 data
    $dataSql = null;
    if ($errore === '') {
        $dataRaw = $val['data_evento'];
        $dataSql = str_replace('T', ' ', $dataRaw) . ':00';
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $dataSql);
        if (!$dt) $errore = "Data/ora evento non valida.";
    }

    // 6.6 prezzo (decimal)
    $prezzo = '0.00';
    if ($errore === '') {
        $tmp = str_replace(',', '.', $val['prezzo']);
        if ($tmp === '') $tmp = '0.00';
        if (!is_numeric($tmp) || (float)$tmp < 0) $errore = "Prezzo non valido.";
        else $prezzo = number_format((float)$tmp, 2, '.', '');
    }

    // 6.7 posti_totali (DB pulito)
    // - vuoto => NULL (informativo)
    // - numero >= 0 => valido (0 lo trattiamo come informativo e lo convertiamo a NULL)
    $postiTotali = null; // NULL => informativo
    if ($errore === '') {
        $raw = trim((string)$val['posti_totali']);

        if ($raw === '') {
            $postiTotali = null; // informativo
        } else {
            if (!ctype_digit($raw) || (int)$raw < 0) {
                $errore = "Posti totali deve essere un intero >= 0. Lascia vuoto per evento informativo.";
            } else {
                $n = (int)$raw;
                $postiTotali = ($n === 0) ? null : $n;
            }
        }
    }

    // 6.8 prenotazione_obbligatoria coerente
    // - se informativo => disattivo
    $pren_bool = ($val['prenotazione_obbligatoria'] === '1') ? 't' : 'f';
    if ($postiTotali === null) {
        $pren_bool = 'f';
        $val['prenotazione_obbligatoria'] = '0';
    }

    // 6.9 lat/lon
    $lat = $val['latitudine'] !== '' ? str_replace(',', '.', $val['latitudine']) : null;
    $lon = $val['longitudine'] !== '' ? str_replace(',', '.', $val['longitudine']) : null;

    $latF = null;
    $lonF = null;
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
                    $errore = "Latitudine tra -90 e 90, longitudine tra -180 e 180.";
                }
            }
        }
    }

    // 6.10 immagine:
    // - se non carico nulla => tengo vecchia
    // - se carico => valido formato, dimensione, sposto in uploads/eventi
    $imgPath = $imgOld;

    if ($errore === '' && isset($_FILES['immagine']) && $_FILES['immagine']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['immagine']['error'] !== UPLOAD_ERR_OK) {
            $errore = "Errore nel caricamento dell'immagine.";
        } else {
            $tmpFile = $_FILES['immagine']['tmp_name'];
            $size = (int)$_FILES['immagine']['size'];

            if ($size > 2 * 1024 * 1024) {
                $errore = "Immagine troppo grande (max 2MB).";
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($tmpFile);

                $allowed = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp'
                ];

                if (!isset($allowed[$mime])) {
                    $errore = "Formato immagine non supportato (JPG/PNG/WEBP).";
                } else {
                    $ext = $allowed[$mime];
                    $dir = __DIR__ . '/../uploads/eventi';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);

                    $filename = 'ev_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                    $dest = $dir . '/' . $filename;

                    if (!move_uploaded_file($tmpFile, $dest)) {
                        $errore = "Impossibile salvare l'immagine caricata.";
                    } else {
                        $imgPath = 'uploads/eventi/' . $filename;

                        // Cancello l'immagine vecchia (solo se era in uploads/eventi/)
                        $oldRel = (string)$imgOld;
                        if ($oldRel !== '' && strpos($oldRel, 'uploads/eventi/') === 0) {
                            $oldAbs = __DIR__ . '/../' . $oldRel;
                            if (is_file($oldAbs)) @unlink($oldAbs);
                        }
                    }
                }
            }
        }
    }

    // 6.11 UPDATE DB
    if ($errore === '') {
        $sql = "
            UPDATE eventi
            SET titolo = $1,
                descrizione_breve = $2,
                descrizione_lunga = $3,
                immagine = $4,
                data_evento = $5,
                luogo = $6,
                latitudine = $7,
                longitudine = $8,
                prezzo = $9,
                posti_totali = $10,
                prenotazione_obbligatoria = $11,
                categoria_id = $12,
                stato = $13,
                stato_evento = $14,
                archiviato = $15::boolean
            WHERE id = $16
        ";

        $params = [
            $titolo,
            $breve,
            $lunga,
            $imgPath,
            $dataSql,
            $luogo,
            $latF,
            $lonF,
            (float)$prezzo,
            $postiTotali,        // NULL => informativo
            $pren_bool,          // 't'/'f'
            $categoria_id,
            $stato,
            $stato_evento,
            $archiviato_pg,      // ✅ 't'/'f'
            $event_id
        ];

        $resUp = pg_query_params($conn, $sql, $params);

        if (!$resUp) {
            $errore = "Errore DB: " . pg_last_error($conn);
        } else {
            // PRG: flash + redirect
            $_SESSION['flash_ok'] = "Evento aggiornato correttamente (ID: $event_id).";
            db_close($conn);
            header("Location: " . base_url("admin/admin_eventi.php"));
            exit;
        }
    }
}

// chiusura connessione (render pagina)
db_close($conn);

require_once __DIR__ . '/../includes/admin_header.php';
?>

<section class="card">
    <header class="card-head">
        <h2>Modifica evento</h2>
        <p class="muted">
            Evento ID: <?= (int)$event_id ?>
        </p>
    </header>

    <?php if ($errore !== ""): ?>
        <div class="alert alert-error" role="alert"><?= e($errore) ?></div>
    <?php endif; ?>

    <?php if ($imgOld !== ''): ?>
        <figure style="margin:12px 0 0 0;">
            <img src="<?= e(base_url($imgOld)) ?>" alt="Immagine evento"
                style="max-width:100%; border-radius:14px;"
                onerror="this.style.display='none'">
            <figcaption style="color:var(--muted); font-size:13px; margin-top:6px;">
                Immagine attuale (caricane una nuova per sostituirla)
            </figcaption>
        </figure>
    <?php endif; ?>

    <form id="formEditEvento" class="auth-form"
        action="<?= e(base_url('admin/admin_event_edit.php?id=' . $event_id)) ?>"
        method="POST" enctype="multipart/form-data" novalidate>

        <div class="field">
            <label for="titolo">Titolo *</label>
            <input type="text" id="titolo" name="titolo" maxlength="100" required value="<?= e($val['titolo']) ?>">
            <small class="hint" id="titoloHint"></small>
        </div>

        <div class="field">
            <label for="descrizione_breve">Descrizione breve *</label>
            <input type="text" id="descrizione_breve" name="descrizione_breve" maxlength="255" required value="<?= e($val['descrizione_breve']) ?>">
            <small class="hint" id="breveHint"></small>
        </div>

        <div class="field">
            <label for="descrizione_lunga">Descrizione lunga *</label>
            <textarea id="descrizione_lunga" name="descrizione_lunga" rows="6" required><?= e($val['descrizione_lunga']) ?></textarea>
            <small class="hint" id="lungaHint"></small>
        </div>

        <div class="field">
            <label for="categoria_id">Categoria *</label>
            <select id="categoria_id" name="categoria_id" required>
                <option value="">Seleziona una categoria</option>
                <?php foreach ($categorie as $c): $cid = (int)$c['id']; ?>
                    <option value="<?= $cid ?>" <?= ((int)$val['categoria_id'] === $cid) ? 'selected' : '' ?>>
                        <?= e($c['nome']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small class="hint" id="catHint"></small>
        </div>

        <div class="field">
            <label for="stato">Stato (moderazione) *</label>
            <select id="stato" name="stato" required>
                <?php foreach (['approvato', 'in_attesa', 'rifiutato'] as $s): ?>
                    <option value="<?= e($s) ?>" <?= ($val['stato'] === $s) ? 'selected' : '' ?>>
                        <?= e(ucfirst(str_replace('_', ' ', $s))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="stato_evento">Stato evento *</label>
            <select id="stato_evento" name="stato_evento" required>
                <?php foreach (['attivo', 'annullato'] as $s): ?>
                    <option value="<?= e($s) ?>" <?= ($val['stato_evento'] === $s) ? 'selected' : '' ?>>
                        <?= e(ucfirst($s)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small class="hint">Se annullato: non compare nelle liste e non è prenotabile.</small>
        </div>

        <div class="field">
            <label class="checkbox">
                <input type="checkbox" id="archiviato" name="archiviato" <?= ($val['archiviato'] === '1') ? 'checked' : '' ?>>
                Archiviato
            </label>
            <small class="hint">Se archiviato: resta nel DB per storico ma non appare nelle liste pubbliche.</small>
        </div>

        <div class="field">
            <label for="data_evento">Data e ora evento *</label>
            <input type="datetime-local" id="data_evento" name="data_evento" required value="<?= e($val['data_evento']) ?>">
            <small class="hint" id="dataHint"></small>
        </div>

        <div class="field">
            <label for="luogo">Luogo *</label>
            <input type="text" id="luogo" name="luogo" maxlength="100" required value="<?= e($val['luogo']) ?>">
            <small class="hint" id="luogoHint"></small>
        </div>

        <div class="field">
            <label for="prezzo">Prezzo (€)</label>
            <input type="text" id="prezzo" name="prezzo" inputmode="decimal" value="<?= e($val['prezzo']) ?>" placeholder="0.00">
            <small class="hint" id="prezzoHint">Lascia 0 per gratuito.</small>
        </div>

        <div class="field">
            <label for="posti_totali">Posti totali</label>
            <input type="number" id="posti_totali" name="posti_totali" min="0" value="<?= e($val['posti_totali']) ?>" placeholder="(vuoto = informativo)">
            <small class="hint" id="postiHint">Lascia vuoto per evento informativo.

                .</small>
        </div>

        <div class="field">
            <label class="checkbox">
                <input type="checkbox" id="prenotazione_obbligatoria" name="prenotazione_obbligatoria"
                    <?= ($val['prenotazione_obbligatoria'] === '1') ? 'checked' : '' ?>
                    <?= ($val['posti_totali'] === '') ? 'disabled' : '' ?>>
                Prenotazione obbligatoria
            </label>
            <small class="hint">Se evento informativo, prenotazione è disattivata.</small>
        </div>

        <div class="field">
            <label for="latitudine">Latitudine (opzionale)</label>
            <input type="text" id="latitudine" name="latitudine" inputmode="decimal" value="<?= e($val['latitudine']) ?>">
            <small class="hint" id="latHint"></small>
        </div>

        <div class="field">
            <label for="longitudine">Longitudine (opzionale)</label>
            <input type="text" id="longitudine" name="longitudine" inputmode="decimal" value="<?= e($val['longitudine']) ?>">
            <button type="button" class="btn-search" id="btn-geo-evento" style="margin-top:6px;">Usa la mia posizione</button>
            <small class="hint" id="geoHint"></small>
        </div>

        <div class="field">
            <label for="immagine">Sostituisci immagine (opzionale)</label>
            <input type="file" id="immagine" name="immagine" accept=".jpg,.jpeg,.png,.webp">
            <small class="hint" id="imgHint">JPG/PNG/WEBP — max 2MB.</small>
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">
            <button type="submit" class="btn btn-admin">Salva modifiche</button>
            <a class="btn btn-ghost" href="<?= e(base_url('admin/admin_eventi.php')) ?>">Torna agli eventi</a>
        </div>
    </form>
</section>

<!-- JS: usi il tuo script per geo + validazioni -->
<script src="<?= e(base_url('assets/js/proponi_evento.js')) ?>"></script>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>