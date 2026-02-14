// assets/js/area_personale.js

function refreshHiddenInput() {
  const prefList = document.getElementById("list-preferite");
  const input = document.getElementById("ordine_input");
  if (!prefList || !input) return;

  const ids = Array.from(prefList.querySelectorAll(".sortable-item"))
    .map((li) => li.getAttribute("data-id"))
    .filter(Boolean);

  input.value = ids.join(",");
}

function ensureEmptyPlaceholders() {
  const left = document.getElementById("list-disponibili");
  const right = document.getElementById("list-preferite");

  function toggleEmpty(list, msg) {
    if (!list) return;
    const items = list.querySelectorAll(".sortable-item").length;
    const empty = list.querySelector(".drop-empty");

    if (items === 0 && !empty) {
      const li = document.createElement("li");
      li.className = "drop-empty";
      li.textContent = msg;
      list.appendChild(li);
    }
    if (items > 0 && empty) empty.remove();
  }

  toggleEmpty(left, "Nessuna categoria disponibile.");
  toggleEmpty(right, "Trascina qui le categorie che ti interessano.");
}

let dragEl = null;

function onDragStart(e) {
  const li = e.target.closest(".sortable-item");
  if (!li) return;

  dragEl = li;
  li.classList.add("dragging");

  e.dataTransfer.effectAllowed = "move";
  e.dataTransfer.setData("text/plain", li.getAttribute("data-id") || "");
}

function onDragEnd(e) {
  const li = e.target.closest(".sortable-item");
  if (!li) return;

  li.classList.remove("dragging");
  dragEl = null;

  // pulizia stato hover
  document.querySelectorAll(".dropzone.is-over").forEach(z => z.classList.remove("is-over"));

  ensureEmptyPlaceholders();
  refreshHiddenInput();
}

function onDragOver(e) {
  const zone = e.currentTarget;
  if (!zone) return;

  e.preventDefault(); // fondamentale per consentire drop
  zone.classList.add("is-over");

  if (!dragEl) return;

  const afterEl = getDragAfterElement(zone, e.clientY);

  // riordino dentro lista (o append in fondo)
  if (afterEl == null) {
    zone.appendChild(dragEl);
  } else {
    zone.insertBefore(dragEl, afterEl);
  }
}

function onDragLeave(e) {
  const zone = e.currentTarget;
  if (!zone) return;
  zone.classList.remove("is-over");
}

function onDrop(e) {
  const zone = e.currentTarget;
  if (!zone) return;
  e.preventDefault();
  zone.classList.remove("is-over");

  // qui il nodo è già stato inserito con dragover, quindi basta aggiornare
  ensureEmptyPlaceholders();
  refreshHiddenInput();
}

function getDragAfterElement(container, y) {
  const els = [...container.querySelectorAll(".sortable-item:not(.dragging)")];
  return els.reduce((closest, child) => {
    const box = child.getBoundingClientRect();
    const offset = y - box.top - box.height / 2;
    if (offset < 0 && offset > closest.offset) {
      return { offset, element: child };
    }
    return closest;
  }, { offset: Number.NEGATIVE_INFINITY, element: null }).element;
}

// Click-to-move (UX top)
// - se clicchi un item a sinistra -> va a destra
// - se clicchi un item a destra -> torna a sinistra
function enableClickMove() {
  const left = document.getElementById("list-disponibili");
  const right = document.getElementById("list-preferite");

  document.addEventListener("click", (e) => {
    const item = e.target.closest(".sortable-item");
    if (!item) return;

    const parent = item.parentElement;
    if (!parent || (!left && !right)) return;

    if (parent.id === "list-disponibili" && right) {
      right.appendChild(item);
    } else if (parent.id === "list-preferite" && left) {
      left.appendChild(item);
    }

    ensureEmptyPlaceholders();
    refreshHiddenInput();
  });
}

document.addEventListener("DOMContentLoaded", () => {
  const left = document.getElementById("list-disponibili");
  const right = document.getElementById("list-preferite");
  const form = document.getElementById("pref-form");

  // delegation drag start/end
  document.addEventListener("dragstart", onDragStart);
  document.addEventListener("dragend", onDragEnd);

  [left, right].forEach((zone) => {
    if (!zone) return;
    zone.addEventListener("dragover", onDragOver);
    zone.addEventListener("dragleave", onDragLeave);
    zone.addEventListener("drop", onDrop);
  });

  enableClickMove();

  ensureEmptyPlaceholders();
  refreshHiddenInput();

  // prima di submit aggiorno hidden input
  form?.addEventListener("submit", () => {
    refreshHiddenInput();
  });
});
