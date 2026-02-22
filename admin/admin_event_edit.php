<?php
// =========================================================
// FILE: admin/admin_event_edit.php
// =========================================================
// Scopo didattico:
// - Form di modifica evento in Area Admin
// - Gestione combinata di:
//     • moderazione (stato: approvato / in_attesa / rifiutato)
//     • stato logico dell’evento (attivo / annullato)
//     • archiviazione (archiviato sì/no)
// - Mantenimento delle regole business su:
//     • eventi informativi (posti_totali = NULL)
//     • prenotazione obbligatoria coerente con i posti
// - Gestione sicura di upload/sostituzione immagine
// - Validazioni server-side di tutti i campi principali
// =========================================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Config principale (connessione DB, helper vari, base_url, ecc.)
require_once __DIR__ . '/../includes/config.php';

// Avvio sessione se non già attiva (necessaria per autenticazione e flash messages)
if (session_status() === PHP_SESSION_NONE) session_start();

// =========================================================
// 1) Guard: SOLO ADMIN
// =========================================================
// Controllo server-side dei permessi:
// - Utente deve risultare loggato in sessione
// - Il ruolo memorizzato deve essere 'admin'
// In caso contrario: messaggio di errore + redirect al login.
// Questo impedisce l’accesso diretto all’URL a utenti non autorizzati.
if (!isset($_SESSION['logged']) || $_SESSION['logged'] !== true || ($_SESSION['ruolo'] ?? '') !== 'admin') {
    $_SESSION['flash_error'] = "Accesso non autorizzato.";
    header("Location: " . base_url('login.php'));
    exit;
}

// Titolo pagina per il layout admin
$page_title = "Modifica evento - Area Admin";

// Apertura connessione a PostgreSQL
$conn = db_connect();

// ---------------------------------------------------------
// Utility: boolean postgres ('t'/'f') -> bool
// ---------------------------------------------------------
// PostgreSQL rappresenta spesso booleani come 't'/'f'.
// Questa funzione uniforma il valore a un vero booleano PHP.
function is_true_pg($v): bool
{
    return ($v === 't' || $v === true || $v === '1' || $v === 1);
}

// ---------------------------------------------------------
// Utility: escape output (se non esiste già e())
// ---------------------------------------------------------
// In alcuni contesti l'helper e() potrebbe non essere definito.
// Qui viene definito localmente per sicurezza (XSS-safe).
if (!function_exists('e')) {
    function e($s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

// ---------------------------------------------------------
// 2) ID evento (GET)
// ---------------------------------------------------------
// Recupero e valido l'ID evento dalla query string.
// Deve essere strettamente numerico (ctype_digit).
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
// Precarico tutte le categorie dal DB per popolare la select.
// Ordinamento alfabetico per una migliore UX.
$categorie = [];
$resCat = pg_query($conn, "SELECT id, nome FROM categorie ORDER BY nome;");
if ($resCat) {
    while ($row = pg_fetch_assoc($resCat)) $categorie[] = $row;
}

// ---------------------------------------------------------
// 4) Carico evento
// ---------------------------------------------------------
// Recupero i dati dell'evento da modificare.
// Se non esiste, mostro errore e torno alla lista eventi.
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
// - Precarico i campi con i valori attuali dell'evento
// - Se il form fallisce la validazione, questi verranno sovrascritti con il POST
// - posti_totali NULL => evento informativo => campo vuoto nel form
// ---------------------------------------------------------
$val = [
    'titolo'                    => (string)($evento['titolo'] ?? ''),
    'descrizione_breve'         => (string)($evento['descrizione_breve'] ?? ''),
    'descrizione_lunga'         => (string)($evento['descrizione_lunga'] ?? ''),
    'data_evento'               => '',
    'luogo'                     => (string)($evento['luogo'] ?? ''),
    'categoria_id'              => (string)($evento['categoria_id'] ?? ''),
    'latitudine'                => ($evento['latitudine'] === null ? '' : (string)$evento['latitudine']),
    'longitudine'               => ($evento['longitudine'] === null ? '' : (string)$evento['longitudine']),
    'prezzo'                    => (string)($evento['prezzo'] ?? '0.00'),

    // DB "pulito": NULL => informativo => nel form mostro vuoto
    'posti_totali'              => ($evento['posti_totali'] === null ? '' : (string)$evento['posti_totali']),

    'prenotazione_obbligatoria' => is_true_pg($evento['prenotazione_obbligatoria'] ?? false) ? '1' : '0',
    'stato'                     => (string)($evento['stato'] ?? 'in_attesa'),

    // Doppio livello di stato:
    // - stato: moderazione (approvato, in_attesa, rifiutato)
    // - stato_evento: attivo/annullato (per gestire annullamento eventi già approvati)
    'stato_evento'              => (string)($evento['stato_evento'] ?? 'attivo'),
    'archiviato'                => is_true_pg($evento['archiviato'] ?? false) ? '1' : '0',
];

// Conversione timestamp DB → formato datetime-local HTML5
$ts = (string)($evento['data_evento'] ?? '');
$t = strtotime($ts);
$val['data_evento'] = $t ? date('Y-m-d\TH:i', $t) : '';

// Percorso immagine attuale (se presente)
$imgOld = (string)($evento['immagine'] ?? '');

$errore = "";

// ---------------------------------------------------------
// 6) POST update (validazioni + update DB)
// ---------------------------------------------------------
// Se il form è stato inviato, eseguo:
// - aggiornamento sticky values con i nuovi dati
// - validazione completa
// - eventuale gestione upload immagine
// - UPDATE su DB se tutto ok
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 6.1 aggiorno sticky values dai valori POST
    foreach ($val as $k => $_) {
        if (isset($_POST[$k])) $val[$k] = trim((string)$_POST[$k]);
    }

    // checkbox: se non presenti nel POST => considerati '0'
    $val['prenotazione_obbligatoria'] = isset($_POST['prenotazione_obbligatoria']) ? '1' : '0';
    $val['archiviato'] = isset($_POST['archiviato']) ? '1' : '0';

    // 6.2 estrazione variabili principali
    $titolo = $val['titolo'];
    $breve  = $val['descrizione_breve'];
    $lunga  = $val['descrizione_lunga'];
    $luogo  = $val['luogo'];
    $catRaw = $val['categoria_id'];

    $stato        = $val['stato'];         // stato di moderazione
    $stato_evento = $val['stato_evento'];  // stato evento (attivo/annullato)

    // Booleano PostgreSQL per campo archiviato
    $archiviato_pg = ($val['archiviato'] === '1') ? 't' : 'f';

    // 6.3 validazioni testi e select obbligatorie
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

    // 6.4 categoria esiste (integrità referenziale lato applicativo)
    $categoria_id = null;
    if ($errore === '') {
        $categoria_id = (int)$catRaw;
        $resCheck = pg_query_params($conn, "SELECT 1 FROM categorie WHERE id = $1 LIMIT 1;", [$categoria_id]);
        if (!$resCheck || pg_num_rows($resCheck) !== 1) $errore = "Categoria non valida.";
    }

    // 6.5 data/ora evento (conversione e validità)
    $dataSql = null;
    if ($errore === '') {
        $dataRaw = $val['data_evento'];                 // formato HTML5: Y-m-d\TH:i
        $dataSql = str_replace('T', ' ', $dataRaw) . ':00'; // → Y-m-d H:i:s
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $dataSql);
        if (!$dt) $errore = "Data/ora evento non valida.";
    }

    // 6.6 prezzo (decimal, ≥ 0)
    $prezzo = '0.00';
    if ($errore === '') {
        $tmp = str_replace(',', '.', $val['prezzo']);
        if ($tmp === '') $tmp = '0.00';
        if (!is_numeric($tmp) || (float)$tmp < 0) $errore = "Prezzo non valido.";
        else $prezzo = number_format((float)$tmp, 2, '.', '');
    }

    // 6.7 posti_totali (DB pulito)
    // ---------------------------------------------------------
    // Logica business su posti_totali:
    // - vuoto => NULL (evento informativo)
    // - numero >= 0 => valido
    //   • se 0 => lo convertiamo a NULL per mantenere la semantica "informativo"
    // ---------------------------------------------------------
    $postiTotali = null; // NULL => informativo
    if ($errore === '') {
        $raw = trim((string)$val['posti_totali']);

        if ($raw === '') {
            $postiTotali = null; // informativo
        } else {
            if (!ctype_digit($raw) || (int)$raw < 0) {
                $errore = "Posti totali deve essere un intero ≥ 0. Lascia vuoto per evento informativo.";
            } else {
                $n = (int)$raw;
                $postiTotali = ($n === 0) ? null : $n;
            }
        }
    }

    // 6.8 prenotazione_obbligatoria coerente con i posti
    // - Se evento informativo (posti NULL) → prenotazione disattivata forzatamente.
    $pren_bool = ($val['prenotazione_obbligatoria'] === '1') ? 't' : 'f';
    if ($postiTotali === null) {
        $pren_bool = 'f';
        $val['prenotazione_obbligatoria'] = '0';
    }

    // 6.9 lat/lon (geolocalizzazione)
    // Campi opzionali, ma se uno è valorizzato devono esserlo entrambi.
    // Inoltre devono rientrare in range geografici validi.
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
    // - Se non carico nulla → mantengo l'immagine esistente
    // - Se carico una nuova immagine:
    //     • controllo errori, dimensione (max 2MB) e MIME reale
    //     • salvo in uploads/eventi con nome univoco
    //     • elimino l'eventuale vecchia immagine dal filesystem
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
                // Verifica MIME affidandosi a finfo (non solo estensione).
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

                    // Nome file univoco basato su timestamp + random
                    $filename = 'ev_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                    $dest = $dir . '/' . $filename;

                    if (!move_uploaded_file($tmpFile, $dest)) {
                        $errore = "Impossibile salvare l'immagine caricata.";
                    } else {
                        $imgPath = 'uploads/eventi/' . $filename;

                        // Cancello l'immagine precedente (se era nel percorso previsto)
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

    // 6.11 UPDATE DB (eseguito solo se non ci sono errori)
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
            $postiTotali,   // NULL => informativo
            $pren_bool,     // 't'/'f'
            $categoria_id,
            $stato,
            $stato_evento,
            $archiviato_pg, // 't'/'f'
            $event_id
        ];

        // Uso di query parametrizzate per prevenire SQL injection
        $resUp = pg_query_params($conn, $sql, $params);

        if (!$resUp) {
            $errore = "Errore DB: " . pg_last_error($conn);
        } else {
            $_SESSION['flash_ok'] = "Evento aggiornato correttamente (ID: $event_id).";
            db_close($conn);
            header("Location: " . base_url("admin/admin_eventi.php"));
            exit;
        }
    }
}

// chiusura connessione per la parte di render pagina HTML
db_close($conn);

// Header comune area admin (layout, menu, breadcrumb, ecc.)
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
        <!-- Messaggio di errore globale visualizzato sopra il form -->
        <div class="alert alert-error" role="alert"><?= e($errore) ?></div>
    <?php endif; ?>

    <?php if ($imgOld !== ''): ?>
        <!-- Preview immagine attuale dell'evento (se esiste) -->
        <figure style="margin:12px 0 0 0;">
            <img src="<?= e(base_url($imgOld)) ?>" alt="Immagine evento"
                style="max-width:100%; border-radius:14px;"
                onerror="this.style.display='none'">
            <figcaption style="color:var(--muted); font-size:13px; margin-top:6px;">
                Immagine attuale (caricane una nuova per sostituirla)
            </figcaption>
        </figure>
    <?php endif; ?>

    <!--
        NB: id = formProponiEvento
        Si riusa lo stesso JavaScript (assets/js/proponi_evento.js)
        già utilizzato per il form di creazione evento.
        In questo modo la UX (hint, controlli lato client, geolocalizzazione)
        rimane coerente tra add e edit.
    -->
    <form id="formProponiEvento" class="auth-form"
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
            <input type="number" id="posti_totali" name="posti_totali" min="0"
                value="<?= e($val['posti_totali']) ?>" placeholder="(vuoto = informativo)">
            <small class="hint" id="postiHint">Lascia vuoto per evento informativo.</small>
        </div>

        <!-- Prenotazione obbligatoria: coerente con la logica dell'add -->
        <div class="field checkbox-row">
            <label for="prenotazione_obbligatoria">Prenotazione obbligatoria</label>

            <div class="checkbox-control">
                <input type="checkbox" id="prenotazione_obbligatoria" name="prenotazione_obbligatoria"
                    <?= ($val['prenotazione_obbligatoria'] === '1') ? 'checked' : '' ?>
                    <?= ($val['posti_totali'] === '') ? 'disabled' : '' ?>>
                <span>Attiva prenotazione</span>
            </div>

            <small class="hint">Se evento informativo, la prenotazione è disattivata.</small>
        </div>

        <!-- Geolocalizzazione: stessa UI del form di creazione -->
        <div class="field">
            <label>Geolocalizzazione (opzionale)</label>

            <div class="geo-row">
                <div class="geo-col">
                    <input type="text" id="latitudine" name="latitudine"
                        inputmode="decimal" placeholder="Latitudine"
                        value="<?= e($val['latitudine']) ?>">
                    <small class="hint" id="latHint"></small>
                </div>

                <div class="geo-col">
                    <input type="text" id="longitudine" name="longitudine"
                        inputmode="decimal" placeholder="Longitudine"
                        value="<?= e($val['longitudine']) ?>">
                    <small class="hint" id="geoHint"></small>
                </div>

                <!-- Pulsante che usa la Geolocation API lato JS per precompilare i campi -->
                <button type="button" class="btn-search" id="btn-geo-evento">
                    Usa la mia posizione
                </button>
            </div>
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

<!--
    JS condiviso:
    - valida alcuni campi lato client (hint, controlli base)
    - gestisce la geolocalizzazione tramite HTML5 Geolocation API
    - migliora l'UX ma non sostituisce le validazioni server-side
-->
<script defer src="<?= e(base_url('assets/js/proponi_evento.js')) ?>"></script>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>