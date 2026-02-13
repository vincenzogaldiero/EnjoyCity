// assets/js/area_personale.js
(function () {
  // -----------------------
  // Countdown (come dashboard)
  // -----------------------
  document.querySelectorAll("[data-countdown]").forEach((el) => {
    const target = new Date(el.getAttribute("data-countdown")).getTime();
    function tick() {
      const now = Date.now();
      let diff = target - now;

      if (diff <= 0) {
        el.textContent = "Evento iniziato!";
        return;
      }

      const mins = Math.floor(diff / 60000);
      const days = Math.floor(mins / (60 * 24));
      const hours = Math.floor((mins - days * 24 * 60) / 60);
      const remMins = mins - days * 24 * 60 - hours * 60;

      if (days > 0) el.textContent = `${days}g ${hours}h ${remMins}m`;
      else if (hours > 0) el.textContent = `${hours}h ${remMins}m`;
      else el.textContent = `${remMins}m`;
    }
    tick();
    setInterval(tick, 1000);
  });

  // -----------------------
  // Drag & Drop preferenze
  // -----------------------
  const list = document.getElementById("sortable-list");
  const saveBtn = document.getElementById("savePrefsBtn");
  const input = document.getElementById("ordine_input");
  const form = document.getElementById("pref-form");

  if (!list || !saveBtn || !input || !form) return;

  let dragged = null;

  list.addEventListener("dragstart", (e) => {
    const li = e.target.closest(".sortable-item");
    if (!li) return;
    dragged = li;
    li.classList.add("dragging");
  });

  list.addEventListener("dragend", (e) => {
    const li = e.target.closest(".sortable-item");
    if (li) li.classList.remove("dragging");
    dragged = null;
  });

  list.addEventListener("dragover", (e) => {
    e.preventDefault();
    if (!dragged) return;

    const after = getAfterElement(list, e.clientY);
    if (after == null) list.appendChild(dragged);
    else list.insertBefore(dragged, after);
  });

  function getAfterElement(container, y) {
    const els = [...container.querySelectorAll(".sortable-item:not(.dragging)")];
    return els.reduce(
      (closest, child) => {
        const box = child.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;
        if (offset < 0 && offset > closest.offset) {
          return { offset, element: child };
        }
        return closest;
      },
      { offset: Number.NEGATIVE_INFINITY, element: null }
    ).element;
  }

  saveBtn.addEventListener("click", () => {
    const ids = [...list.querySelectorAll(".sortable-item")]
      .map((li) => li.getAttribute("data-id"))
      .filter(Boolean);

    input.value = ids.join(",");
    form.submit();
  });
})();
