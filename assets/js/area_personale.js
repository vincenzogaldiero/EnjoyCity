// assets/js/area_personale.js
"use strict";

/**
 * Aggiorna il campo hidden con l'ordine delle categorie preferite
 * (lista di data-id separati da virgola).
 *
 * Viene chiamata:
 * - dopo ogni operazione di drag&drop
 * - dopo il click-to-move (sposta con click)
 * - prima del submit del form
 */
function refreshHiddenInput() {
  const prefList = document.getElementById("list-preferite");
  const input = document.getElementById("ordine_input");
  if (!prefList || !input) return;

  const ids = Array.from(prefList.querySelectorAll(".sortable-item"))
    .map((li) => li.getAttribute("data-id"))
    .filter(Boolean);

  input.value = ids.join(",");
}

/**
 * Gestisce i placeholder quando una lista è vuota.
 * Se non ci sono .sortable-item, mostra un <li> con testo informativo.
 */
function ensureEmptyPlaceholders() {
  const left = document.getElementById("list-disponibili");
  const right = document.getElementById("list-preferite");

  function toggleEmpty(list, msg) {
    if (!list) return;

    const itemsCount = list.querySelectorAll(".sortable-item").length;
    const empty = list.querySelector(".drop-empty");

    // Lista vuota → mostra placeholder
    if (itemsCount === 0 && !empty) {
      const li = document.createElement("li");
      li.className = "drop-empty";
      li.textContent = msg;
      list.appendChild(li);
    }

    // Lista con elementi → rimuovi eventuale placeholder
    if (itemsCount > 0 && empty) {
      empty.remove();
    }
  }

  toggleEmpty(left, "Nessuna categoria disponibile.");
  toggleEmpty(right, "Trascina qui le categorie che ti interessano.");
}

// Stato globale per il drag&drop
let dragEl = null;

/**
 * Drag start: salvo l’elemento trascinato e aggiungo la classe .dragging
 */
function onDragStart(e) {
  const li = e.target.closest(".sortable-item");
  if (!li) return;

  dragEl = li;
  li.classList.add("dragging");

  if (e.dataTransfer) {
    e.dataTransfer.effectAllowed = "move";
    e.dataTransfer.setData("text/plain", li.getAttribute("data-id") || "");
  }
}

/**
 * Drag end: pulisco lo stato e rimuovo classi di hover
 */
function onDragEnd(e) {
  const li = e.target.closest(".sortable-item");
  if (!li) return;

  li.classList.remove("dragging");
  dragEl = null;

  // pulizia stato hover su tutte le dropzone
  document
    .querySelectorAll(".dropzone.is-over")
    .forEach((z) => z.classList.remove("is-over"));

  ensureEmptyPlaceholders();
  refreshHiddenInput();
}

/**
 * Drag over: permette il drop e calcola la posizione di inserimento
 * all'interno della lista (riordino verticale).
 */
function onDragOver(e) {
  const zone = e.currentTarget;
  if (!zone) return;

  e.preventDefault(); // fondamentale per consentire il drop
  zone.classList.add("is-over");

  if (!dragEl) return;

  const afterEl = getDragAfterElement(zone, e.clientY);

  // Riordino dentro la lista (o append in fondo)
  if (afterEl == null) {
    zone.appendChild(dragEl);
  } else {
    zone.insertBefore(dragEl, afterEl);
  }
}

/**
 * Drag leave: rimuove lo stato di hover quando si esce dalla dropzone
 */
function onDragLeave(e) {
  const zone = e.currentTarget;
  if (!zone) return;
  zone.classList.remove("is-over");
}

/**
 * Drop: il nodo è già stato inserito in onDragOver, qui aggiorno solo stato
 */
function onDrop(e) {
  const zone = e.currentTarget;
  if (!zone) return;

  e.preventDefault();
  zone.classList.remove("is-over");

  ensureEmptyPlaceholders();
  refreshHiddenInput();
}

/**
 * Restituisce l’elemento dopo il quale inserire l’item trascinato,
 * in base alla coordinata Y del mouse.
 */
function getDragAfterElement(container, y) {
  const els = Array.from(
    container.querySelectorAll(".sortable-item:not(.dragging)")
  );

  return els.reduce(
    (closest, child) => {
      const box = child.getBoundingClientRect();
      const offset = y - box.top - box.height / 2;

      // offset < 0 → siamo sopra la metà verticale dell'elemento
      if (offset < 0 && offset > closest.offset) {
        return { offset, element: child };
      }
      return closest;
    },
    { offset: Number.NEGATIVE_INFINITY, element: null }
  ).element;
}

/**
 * Click-to-move (UX più comoda):
 * - clic su item a sinistra → lo sposta a destra
 * - clic su item a destra → lo sposta a sinistra
 *
 * Questo si somma al drag&drop: l'utente può scegliere
 * se trascinare o semplicemente cliccare.
 */
function enableClickMove() {
  const left = document.getElementById("list-disponibili");
  const right = document.getElementById("list-preferite");

  if (!left || !right) return;

  document.addEventListener("click", (e) => {
    const item = e.target.closest(".sortable-item");
    if (!item) return;

    const parent = item.parentElement;
    if (!parent) return;

    // evita click su elementi trascinati in quel momento
    if (item.classList.contains("dragging")) return;

    if (parent.id === "list-disponibili") {
      right.appendChild(item);
    } else if (parent.id === "list-preferite") {
      left.appendChild(item);
    } else {
      // Non è in nessuna delle due liste gestite
      return;
    }

    ensureEmptyPlaceholders();
    refreshHiddenInput();
  });
}

// ===============================
// COUNTDOWN (area personale)
// ===============================

/**
 * Inizializza i countdown per gli eventi futuri.
 *
 * NOTA IMPORTANTE (coerenza con PHP):
 * - In area_personale.php SOLO il primo evento ATTIVO
 *   riceve l'attributo data-countdown.
 * - Gli eventi ANNULLATI hanno solo .countdown SENZA data-countdown,
 *   quindi NON vengono selezionati e non hanno timer.
 */
function initCountdowns() {
  // Seleziona SOLO gli elementi con attributo data-countdown
  const nodes = document.querySelectorAll(".countdown[data-countdown]");
  if (!nodes.length) return;

  const pad = (n) => String(n).padStart(2, "0");

  function tick() {
    const now = Date.now();

    nodes.forEach((el) => {
      const raw = el.getAttribute("data-countdown");
      if (!raw) {
        return;
      }

      const targetTime = new Date(raw).getTime();

      if (Number.isNaN(targetTime)) {
        el.textContent = "Data non valida";
        return;
      }

      const diff = targetTime - now;

      if (diff <= 0) {
        // Evento iniziato o concluso: messaggio statico
        el.textContent = "In corso / concluso";
        return;
      }

      const totalSec = Math.floor(diff / 1000);
      const days = Math.floor(totalSec / 86400);
      const hours = Math.floor((totalSec % 86400) / 3600);
      const mins = Math.floor((totalSec % 3600) / 60);
      const secs = totalSec % 60;

      el.textContent = `${days}g ${pad(hours)}h ${pad(mins)}m ${pad(secs)}s`;
    });
  }

  tick();
  // Aggiorno tutti i countdown ogni secondo
  setInterval(tick, 1000);
}

// ===============================
// BOOTSTRAP SCRIPT
// ===============================
document.addEventListener("DOMContentLoaded", () => {
  const left = document.getElementById("list-disponibili");
  const right = document.getElementById("list-preferite");
  const form = document.getElementById("pref-form");

  // Delegation per dragstart/dragend su tutto il documento
  document.addEventListener("dragstart", onDragStart);
  document.addEventListener("dragend", onDragEnd);

  // Attacco gli handler di drop alle due liste
  [left, right].forEach((zone) => {
    if (!zone) return;
    zone.classList.add("dropzone"); // opzionale: utile per styling
    zone.addEventListener("dragover", onDragOver);
    zone.addEventListener("dragleave", onDragLeave);
    zone.addEventListener("drop", onDrop);
  });

  // Spostamento via click (sinistra ↔ destra)
  enableClickMove();

  // Stato iniziale placeholder + hidden
  ensureEmptyPlaceholders();
  refreshHiddenInput();

  // Prima del submit assicuro di avere l'ordine aggiornato
  if (form) {
    form.addEventListener("submit", () => {
      refreshHiddenInput();
    });
  }

  // Countdown solo sugli elementi con data-countdown (quindi solo evento attivo)
  initCountdowns();
});