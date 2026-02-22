<?php
// =========================================================
// FILE: admin/admin_event_add.php
// =========================================================
// Scopo didattico:
// - Form di creazione evento da parte dell'admin con pubblicazione diretta
// - Validazioni server-side su tutti i campi critici (titolo, date, prezzo...)
// - Gestione opzionale dell'immagine (upload con controlli su MIME e dimensione)
// - Gestione di eventi "informativi" (0 posti = nessuna prenotazione possibile)
// - Regola di business: l'admin può pubblicare direttamente eventi "approvati"
// =========================================================

// Abilitazione messaggi di errore (utile in fase di sviluppo/debug).
// In produzione normalmente si disabiliterebbero.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Config generale (connessione DB, helper, base_url, ecc.)
require_once __DIR__ . '/../includes/config.php';

// Avvio sessione se non già attiva (usata per autenticazione e flash messages).
if (session_status() === PHP_SESSION_NONE) session_start();

// =========================================================
// 1) Guard: SOLO ADMIN
// =========================================================
// Controllo lato server sui permessi di accesso:
// - Utente deve essere autenticato
// - Il suo ruolo (in sessione) deve essere 'admin'
// In caso contrario viene reindirizzato al login con messaggio di errore.
if (
    !isset($_SESSION['logged']) || $_SESSION['logged'] !== true ||
    ($_SESSION['ruolo'] ?? '') !== 'admin'
) {
    $_SESSION['flash_error'] = "Accesso non autorizzato.";
    header("Location: " . base_url("login.php"));
    exit;
}

// Titolo pagina (usato nell'HTML dell'header admin).
$page_title = "Aggiungi evento - Area Admin";

// Apertura connessione al database PostgreSQL.
$conn = db_connect();

// =========================================================
// 2) Carico categorie (select)
// =========================================================
// Recupero tutte le categorie presenti nel DB per popolare
// la select di scelta categoria dell'evento.
$categorie = [];
$resCat = pg_query($conn, "SELECT id, nome FROM categorie ORDER BY nome;");
if ($resCat) {
    while ($row = pg_fetch_assoc($resCat)) {
        $categorie[] = $row;
    }
}

// =========================================================
// 3) Valori “sticky” (se errore, non perdo input)
// =========================================================
// Array di default per i campi del form.
// Se il form fallisce la validazione, questi valori verranno
// popolati con il POST e riscritti nel form, evitando di far
// perdere all'utente admin quanto inserito.
$val = [
    'titolo'                    => '',
    'descrizione_breve'         => '',
    'descrizione_lunga'         => '',
    'data_evento'               => '',
    'luogo'                     => '',
    'categoria_id'              => '',
    'latitudine'                => '',
    'longitudine'               => '',
    'prezzo'                    => '0.00',
    'posti_totali'              => '0',
    'prenotazione_obbligatoria' => '0',
    // Stato: l'admin pubblica direttamente -> approvato di default
    'stato'                     => 'approvato',
];

// Variabile per memorizzare eventuale messaggio di errore globale.
$errore = "";

// =========================================================
// 4) POST: INSERT evento
// =========================================================
// Se la richiesta arriva via POST, significa che l'admin ha
// inviato il form e bisogna:
// - validare i dati
// - gestire eventuale upload immagine
// - inserire il record nel database
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Copio i valori dal POST nei "sticky" per non perderli in caso di errore.
    foreach ($val as $k => $_) {
        if (isset($_POST[$k])) {
            $val[$k] = trim((string)$_POST[$k]);
        }
    }

    // Prenotazione obbligatoria: checkbox → se non spuntata non arriva nel POST.
    $val['prenotazione_obbligatoria'] = isset($_POST['prenotazione_obbligatoria']) ? '1' : '0';

    // Se per qualche motivo non arriva "stato", garantisco comunque "approvato".
    if (!isset($_POST['stato']) || $val['stato'] === '') {
        $val['stato'] = 'approvato';
    }

    // ---- Validazioni base sui campi testuali obbligatori ----
    $titolo = $val['titolo'];
    $breve  = $val['descrizione_breve'];
    $lunga  = $val['descrizione_lunga'];
    $luogo  = $val['luogo'];
    $catRaw = $val['categoria_id'];
    $stato  = $val['stato'];

    // Controllo campi obbligatori non vuoti.
    if (
        $titolo === '' ||
        $breve === '' ||
        $lunga === '' ||
        $val['data_evento'] === '' ||
        $luogo === '' ||
        $catRaw === '' ||
        $stato === ''
    ) {
        $errore = "Compila tutti i campi obbligatori.";
        // Vincoli di lunghezza stringhe per evitare testi troppo lunghi.
    } elseif (mb_strlen($titolo) > 100) {
        $errore = "Il titolo può contenere al massimo 100 caratteri.";
    } elseif (mb_strlen($breve) > 255) {
        $errore = "La descrizione breve può contenere al massimo 255 caratteri.";
    } elseif (mb_strlen($luogo) > 100) {
        $errore = "Il luogo può contenere al massimo 100 caratteri.";
        // Categoria deve essere un intero (id).
    } elseif (!ctype_digit($catRaw)) {
        $errore = "Categoria non valida.";
        // Stato deve appartenere alla lista consentita.
    } elseif (!in_array($stato, ['approvato', 'in_attesa', 'rifiutato'], true)) {
        $errore = "Stato non valido.";
    }

    // ---- Categoria esiste nel DB ----
    // Verifica integrità referenziale a livello applicativo
    // prima dell'INSERT.
    $categoria_id = null;
    if ($errore === '') {
        $categoria_id = (int)$catRaw;
        $resCheck = pg_query_params(
            $conn,
            "SELECT 1 FROM categorie WHERE id = $1 LIMIT 1;",
            [$categoria_id]
        );
        if (!$resCheck || pg_num_rows($resCheck) !== 1) {
            $errore = "Categoria non valida.";
        }
    }

    // ---- Data/ora evento ----
    // Converte il formato HTML5 datetime-local (YYYY-MM-DDTHH:MM)
    // nel formato timestamp compatibile con PostgreSQL.
    $dataSql = null;
    if ($errore === '') {
        // input datetime-local: YYYY-MM-DDTHH:MM
        $dataSql = str_replace('T', ' ', $val['data_evento']) . ':00';
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $dataSql);
        if (!$dt) {
            $errore = "Data/ora evento non valida.";
        }
    }

    // ---- Prezzo ----
    // Accetta virgola o punto come separatore decimale.
    $prezzo = '0.00';
    if ($errore === '') {
        $tmp = str_replace(',', '.', $val['prezzo']);
        if ($tmp === '') $tmp = '0.00';
        if (!is_numeric($tmp) || (float)$tmp < 0) {
            $errore = "Prezzo non valido.";
        } else {
            // Normalizzazione a due decimali (formato standard).
            $prezzo = number_format((float)$tmp, 2, '.', '');
        }
    }

    // ---- Posti totali (opzionale: se vuoto => 0) ----
    // 0 indica evento informativo → non prenotabile.
    $postiTotali = 0;
    if ($errore === '') {
        if ($val['posti_totali'] !== '') {
            if (!ctype_digit($val['posti_totali']) || (int)$val['posti_totali'] < 0) {
                $errore = "Posti totali deve essere un intero ≥ 0 (0 = informativo).";
            } else {
                $postiTotali = (int)$val['posti_totali'];
            }
        }
    }

    // ---- Prenotazione obbligatoria coerente con i posti ----
    // Se l'evento ha 0 posti → forzatura a "prenotazione non obbligatoria"
    // per evitare eventi prenotabili ma senza capienza.
    $pren_bool = ($val['prenotazione_obbligatoria'] === '1') ? 't' : 'f';
    if ($postiTotali === 0) {
        // Evento informativo: niente prenotazioni
        $pren_bool = 'f';
        $val['prenotazione_obbligatoria'] = '0';
    }

    // ---- Latitudine/Longitudine (geolocalizzazione) ----
    // Campi opzionali, ma se compilati devono essere:
    // - entrambi presenti (lat + lon)
    // - numerici
    // - nei range geografici corretti
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

    // ---- Upload immagine (opzionale) ----
    // Se l'admin non carica alcun file, il campo resta vuoto.
    // In caso di upload:
    // - controllo errori PHP
    // - controllo dimensione (max 2MB)
    // - controllo MIME tramite finfo (sicurezza, non solo estensione)
    // - creazione directory se non esiste
    // - salvataggio con nome univoco pseudo-random
    $imgPath = ''; // se non carico, resta vuoto
    if (
        $errore === '' &&
        isset($_FILES['immagine']) &&
        $_FILES['immagine']['error'] !== UPLOAD_ERR_NO_FILE
    ) {

        if ($_FILES['immagine']['error'] !== UPLOAD_ERR_OK) {
            $errore = "Errore nel caricamento dell'immagine.";
        } else {
            $tmpFile = $_FILES['immagine']['tmp_name'];
            $size = (int)$_FILES['immagine']['size'];

            if ($size > 2 * 1024 * 1024) {
                $errore = "Immagine troppo grande (max 2MB).";
            } else {
                // Analisi MIME reale del file (maggiore sicurezza).
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($tmpFile);

                // Formati immagine consentiti.
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

                    // Creazione cartella se non esiste.
                    if (!is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }

                    // Generazione nome file univoco (timestamp + random bytes).
                    $filename = 'ev_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                    $dest = $dir . '/' . $filename;

                    // Spostamento file dalla cartella temporanea a quella definitiva.
                    if (!move_uploaded_file($tmpFile, $dest)) {
                        $errore = "Impossibile salvare l'immagine caricata.";
                    } else {
                        // Salvo solo il path relativo da memorizzare nel DB.
                        $imgPath = 'uploads/eventi/' . $filename;
                    }
                }
            }
        }
    }

    // ---- INSERT nel database ----
    // Se non sono stati rilevati errori fino a questo punto,
    // preparo la query parametrizzata di inserimento.
    if ($errore === '') {

        $sqlIns = "
            INSERT INTO eventi
                (titolo, descrizione_breve, descrizione_lunga, immagine,
                 data_evento, luogo, latitudine, longitudine,
                 prezzo, posti_totali, posti_prenotati,
                 prenotazione_obbligatoria, categoria_id, stato)
            VALUES
                ($1,$2,$3,$4,
                 $5,$6,$7,$8,
                 $9,$10,0,
                 $11,$12,$13)
            RETURNING id;
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
            $postiTotali,
            $pren_bool,
            $categoria_id,
            $stato
        ];

        // Uso di pg_query_params per prevenire SQL injection.
        $resIns = pg_query_params($conn, $sqlIns, $params);
        if (!$resIns) {
            $errore = "Errore DB: " . pg_last_error($conn);
        } else {
            $row = pg_fetch_assoc($resIns);
            $newId = (int)($row['id'] ?? 0);

            // Flash message di conferma per la pagina di lista eventi.
            $_SESSION['flash_ok'] = "Evento creato correttamente (ID: $newId).";

            db_close($conn);

            // Redirect alla pagina di gestione eventi (pattern PRG).
            header("Location: " . base_url("admin/admin_eventi.php"));
            exit;
        }
    }
}

// Chiusura connessione se non è già stata chiusa sopra (in caso di errori).
db_close($conn);

// Inclusione header area admin (layout, menu, ecc.).
require_once __DIR__ . '/../includes/admin_header.php';
?>

<section class="card">
    <header class="card-head">
        <h2>Aggiungi evento</h2>
        <p class="muted">0 posti = evento informativo (prenotazioni disattivate).</p>
    </header>

    <?php if ($errore !== ""): ?>
        <!-- Messaggio di errore globale mostrato sopra il form -->
        <div class="alert alert-error" role="alert"><?= e($errore) ?></div>
    <?php endif; ?>

    <!--
        id = formProponiEvento:
        viene riutilizzato lo stesso JavaScript di validazione lato client
        usato per il form di proposta evento dell'utente autenticato.
    -->
    <form id="formProponiEvento" class="auth-form"
        action="<?= e(base_url('admin/admin_event_add.php')) ?>"
        method="POST" enctype="multipart/form-data" novalidate>

        <!-- Titolo evento -->
        <div class="field">
            <label for="titolo">Titolo *</label>
            <input type="text" id="titolo" name="titolo" maxlength="100" required
                value="<?= e($val['titolo']) ?>">
            <small class="hint" id="titoloHint"></small>
        </div>

        <!-- Descrizione breve (usata spesso nelle card riassuntive) -->
        <div class="field">
            <label for="descrizione_breve">Descrizione breve *</label>
            <input type="text" id="descrizione_breve" name="descrizione_breve"
                maxlength="255" required
                value="<?= e($val['descrizione_breve']) ?>">
            <small class="hint" id="breveHint"></small>
        </div>

        <!-- Descrizione lunga (testo completo dell'evento) -->
        <div class="field">
            <label for="descrizione_lunga">Descrizione lunga *</label>
            <textarea id="descrizione_lunga" name="descrizione_lunga" rows="6" required><?= e($val['descrizione_lunga']) ?></textarea>
            <small class="hint" id="lungaHint"></small>
        </div>

        <!-- Categoria (select popolata dal DB) -->
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

        <!-- Stato evento (approvato / in attesa / rifiutato) -->
        <div class="field">
            <label for="stato">Stato *</label>
            <select id="stato" name="stato" required>
                <?php foreach (['approvato', 'in_attesa', 'rifiutato'] as $s): ?>
                    <option value="<?= e($s) ?>" <?= ($val['stato'] === $s) ? 'selected' : '' ?>>
                        <?= e(ucfirst(str_replace('_', ' ', $s))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Data e ora dell'evento (datetime-local HTML5) -->
        <div class="field">
            <label for="data_evento">Data e ora evento *</label>
            <input type="datetime-local" id="data_evento" name="data_evento" required
                value="<?= e($val['data_evento']) ?>">
            <small class="hint" id="dataHint"></small>
        </div>

        <!-- Luogo di svolgimento -->
        <div class="field">
            <label for="luogo">Luogo *</label>
            <input type="text" id="luogo" name="luogo" maxlength="100" required
                value="<?= e($val['luogo']) ?>">
            <small class="hint" id="luogoHint"></small>
        </div>

        <!-- Prezzo in euro (0 = gratuito) -->
        <div class="field">
            <label for="prezzo">Prezzo (€)</label>
            <input type="text" id="prezzo" name="prezzo" inputmode="decimal"
                value="<?= e($val['prezzo']) ?>" placeholder="0.00">
            <small class="hint" id="prezzoHint">Lascia 0 per gratuito.</small>
        </div>

        <!-- Posti totali (0 = evento informativo, non prenotabile) -->
        <div class="field">
            <label for="posti_totali">Posti totali</label>
            <input type="number" id="posti_totali" name="posti_totali" min="0"
                value="<?= e($val['posti_totali']) ?>">
            <small class="hint" id="postiHint">0 = evento informativo.</small>
        </div>

        <!-- Checkbox Prenotazione obbligatoria -->
        <!-- Se posti_totali = 0, la checkbox viene disabilitata dal server -->
        <div class="field checkbox-row">
            <label for="prenotazione_obbligatoria">Prenotazione obbligatoria</label>

            <div class="checkbox-control">
                <input type="checkbox"
                    id="prenotazione_obbligatoria"
                    name="prenotazione_obbligatoria"
                    <?= ($val['prenotazione_obbligatoria'] === '1') ? 'checked' : '' ?>
                    <?= ((int)$val['posti_totali'] === 0) ? 'disabled' : '' ?>>
                <span>Attiva prenotazione</span>
            </div>

            <small class="hint">Se posti = 0, la prenotazione viene disattivata.</small>
        </div>

        <!-- Geolocalizzazione opzionale: latitudine/longitudine + pulsante HTML5 -->
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

                <!-- Pulsante che usa la Geolocation API via JS per compilare lat/lon -->
                <button type="button" class="btn-search" id="btn-geo-evento">
                    Usa la mia posizione
                </button>
            </div>
        </div>

        <!-- Upload immagine evento (opzionale) -->
        <div class="field">
            <label for="immagine">Immagine (opzionale)</label>
            <input type="file" id="immagine" name="immagine" accept=".jpg,.jpeg,.png,.webp">
            <small class="hint" id="imgHint">JPG/PNG/WEBP — max 2MB.</small>
        </div>

        <!-- Pulsanti di invio/annulla -->
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">
            <button type="submit" class="btn btn-admin">Crea evento</button>
            <a class="btn btn-ghost" href="<?= e(base_url('admin/admin_eventi.php')) ?>">Annulla</a>
        </div>
    </form>
</section>

<!--
    JS riusato:
    - gestisce geolocalizzazione (navigator.geolocation)
    - fornisce controlli UX lato client su lunghezze, formati, ecc.
    - non sostituisce, ma affianca le validazioni server-side.
-->
<script defer src="<?= e(base_url('assets/js/proponi_evento.js')) ?>"></script>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>