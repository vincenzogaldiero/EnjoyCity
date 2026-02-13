<?php
// FILE: area_personale.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/config.php';

// Controllo Accesso
if (!isset($_SESSION['ruolo']) || $_SESSION['ruolo'] !== 'user') {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// 1. GESTIONE DRAG & DROP (Salvataggio Preferenze)
// Se arriva una POST con l'ordine delle categorie
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ordine_categorie'])) {
    $ordine = explode(',', $_POST['ordine_categorie']);

    // Puliamo le vecchie preferenze
    $pdo->prepare("DELETE FROM preferenze_utente WHERE utente_id = ?")->execute([$user_id]);

    // Inseriamo le nuove
    $stmt = $pdo->prepare("INSERT INTO preferenze_utente (utente_id, categoria_id, ordine) VALUES (?, ?, ?)");
    foreach ($ordine as $index => $cat_id) {
        $stmt->execute([$user_id, $cat_id, $index + 1]);
    }
    $msg = "Preferenze aggiornate!";
}

// 2. RECUPERO CATEGORIE (Ordinate per preferenza se esistono, altrimenti default)
// Questa query complessa fa un LEFT JOIN per vedere se l'utente ha già ordinato le categorie
$sql_cat = "
    SELECT c.id, c.nome 
    FROM categorie c
    LEFT JOIN preferenze_utente pu ON c.id = pu.categoria_id AND pu.utente_id = ?
    ORDER BY CASE WHEN pu.ordine IS NOT NULL THEN 0 ELSE 1 END, pu.ordine, c.nome
";
$stmt = $pdo->prepare($sql_cat);
$stmt->execute([$user_id]);
$categorie = $stmt->fetchAll();

// 3. RECUPERO I MIEI EVENTI (Futuri)
$sql_my_events = "
    SELECT e.*, p.quantita 
    FROM prenotazioni p
    JOIN eventi e ON p.evento_id = e.id
    WHERE p.utente_id = ? AND e.data_evento > NOW()
    ORDER BY e.data_evento ASC
";
$stmt = $pdo->prepare($sql_my_events);
$stmt->execute([$user_id]);
$miei_eventi = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="UTF-8">
    <title>Area Personale - Enjoy City</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .dashboard-container {
            display: flex;
            gap: 30px;
            margin-top: 20px;
        }

        .col {
            flex: 1;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        /* Stili Drag & Drop */
        #sortable-list {
            list-style: none;
            padding: 0;
        }

        #sortable-list li {
            padding: 10px;
            margin: 5px 0;
            background: #f9f9f9;
            border: 1px solid #ddd;
            cursor: grab;
            display: flex;
            justify-content: space-between;
        }

        #sortable-list li.dragging {
            opacity: 0.5;
            border: 2px dashed #2E7D32;
        }

        .save-btn {
            margin-top: 10px;
            width: 100%;
            background: #FF9800;
        }
    </style>
</head>

<body>

    <header>
        <h1>Area Personale</h1>
        <nav>
            <a href="index.php">Home</a>
            <a href="proponi_evento.php">Proponi Evento</a>
            <a href="logout.php">Esci</a>
        </nav>
    </header>

    <main style="max-width: 1000px; margin: 0 auto; padding: 20px;">

        <h2>Ciao, <?= htmlspecialchars($_SESSION['nome_utente']) ?>!</h2>
        <?php if (isset($msg)) echo "<p style='color:green'>$msg</p>"; ?>

        <div class="dashboard-container">

            <div class="col">
                <h3>🎫 I tuoi prossimi eventi</h3>
                <?php if (count($miei_eventi) > 0): ?>

                    <div style="background:#e8f5e9; padding:15px; border-radius:5px; margin-bottom:20px; border:1px solid #2E7D32;">
                        <h4>Countdown: <?= htmlspecialchars($miei_eventi[0]['titolo']) ?></h4>
                        <div id="countdown" data-date="<?= $miei_eventi[0]['data_evento'] ?>" style="font-size:1.5em; font-weight:bold; color:#2E7D32;">Calcolo...</div>
                    </div>

                    <ul>
                        <?php foreach ($miei_eventi as $ev): ?>
                            <li style="margin-bottom:10px; padding-bottom:10px; border-bottom:1px solid #eee;">
                                <strong><?= htmlspecialchars($ev['titolo']) ?></strong><br>
                                📅 <?= date('d/m/Y H:i', strtotime($ev['data_evento'])) ?><br>
                                🎟 Biglietti: <?= $ev['quantita'] ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p>Non hai prenotazioni attive.</p>
                    <a href="eventi.php" class="btn">Cerca Eventi</a>
                <?php endif; ?>
            </div>

            <div class="col">
                <h3>❤️ Le tue preferenze</h3>
                <p><small>Trascina le categorie per ordinare i tuoi interessi. I risultati nella home si adatteranno!</small></p>

                <form method="POST" id="pref-form">
                    <ul id="sortable-list">
                        <?php foreach ($categorie as $cat): ?>
                            <li draggable="true" data-id="<?= $cat['id'] ?>">
                                <span><?= htmlspecialchars($cat['nome']) ?></span>
                                <span>☰</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <input type="hidden" name="ordine_categorie" id="ordine_input">
                    <button type="button" onclick="salvaOrdine()" class="btn save-btn">Salva Preferenze</button>
                </form>
            </div>

        </div>

    </main>

    <script>
        // 1. Script Countdown
        const countdownEl = document.getElementById('countdown');
        if (countdownEl) {
            const targetDate = new Date(countdownEl.dataset.date).getTime();
            setInterval(() => {
                const now = new Date().getTime();
                const distance = targetDate - now;
                if (distance < 0) {
                    countdownEl.innerHTML = "Evento Iniziato!";
                    return;
                }
                const d = Math.floor(distance / (1000 * 60 * 60 * 24));
                const h = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                countdownEl.innerHTML = `${d}g ${h}h`;
            }, 1000);
        }

        // 2. Script Drag & Drop
        const list = document.getElementById('sortable-list');
        let draggedItem = null;

        list.addEventListener('dragstart', (e) => {
            draggedItem = e.target;
            e.target.classList.add('dragging');
        });

        list.addEventListener('dragend', (e) => {
            e.target.classList.remove('dragging');
            draggedItem = null;
        });

        list.addEventListener('dragover', (e) => {
            e.preventDefault();
            const afterElement = getDragAfterElement(list, e.clientY);
            if (afterElement == null) {
                list.appendChild(draggedItem);
            } else {
                list.insertBefore(draggedItem, afterElement);
            }
        });

        function getDragAfterElement(container, y) {
            const draggableElements = [...container.querySelectorAll('li:not(.dragging)')];
            return draggableElements.reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;
                if (offset < 0 && offset > closest.offset) {
                    return {
                        offset: offset,
                        element: child
                    };
                } else {
                    return closest;
                }
            }, {
                offset: Number.NEGATIVE_INFINITY
            }).element;
        }

        function salvaOrdine() {
            const items = list.querySelectorAll('li');
            let ids = [];
            items.forEach(item => ids.push(item.getAttribute('data-id')));
            document.getElementById('ordine_input').value = ids.join(',');
            document.getElementById('pref-form').submit();
        }
    </script>

</body>

</html>