// FILE: assets/js/admin.js
// JS per Area Admin
//
// Funzioni:
// 1) Conferma azioni pericolose (data-confirm)
// 2) Ricerca live client-side su liste/tabelle (data-filter + data-filter-scope + data-filter-row)
// 3) Piccole migliorie UX
// 4) Popup notifica moderazione (eventi/recensioni in attesa)
//
document.addEventListener("DOMContentLoaded", () => {
  /* =========================================================
     1) CONFERMA AZIONI PERICOLOSE
        Uso:
        - <button data-confirm="Sei sicuro?">...</button>
        - <a data-confirm="Sei sicuro?" href="...">...</a>
     ========================================================= */
  document.addEventListener("click", (e) => {
    const el = e.target.closest("[data-confirm]");
    if (!el) return;

    const msg = el.getAttribute("data-confirm") || "Confermi l’azione?";
    if (!window.confirm(msg)) {
      e.preventDefault();
      e.stopPropagation();
    }
  });

  /* =========================================================
     2) RICERCA LIVE (CLIENT-SIDE)
        - Input:  <input data-filter="utenti">
        - Scope:  <div data-filter-scope="utenti">
        - Row:    elementi figli con [data-filter-row]
        Supporta:
        - cards (.row)
        - righe tabella (<tr>)
     ========================================================= */
  const normalize = (s) =>
    String(s || "")
      .toLowerCase()
      .normalize("NFD")                 // separa lettere+accenti
      .replace(/[\u0300-\u036f]/g, "")  // rimuove accenti
      .trim();

  document.querySelectorAll("[data-filter]").forEach((input) => {
    const key = input.getAttribute("data-filter");
    if (!key) return;

    const scope = document.querySelector(`[data-filter-scope="${key}"]`);
    if (!scope) return;

    const rows = Array.from(scope.querySelectorAll("[data-filter-row]"));
    if (!rows.length) return;

    // Cache del testo normalizzato per performance
    const cache = rows.map((row) => normalize(row.textContent));

    const applyFilter = () => {
      const q = normalize(input.value);
      rows.forEach((row, idx) => {
        row.style.display = cache[idx].includes(q) ? "" : "none";
      });
    };

    // Filtra mentre scrivo
    input.addEventListener("input", applyFilter);

    // UX: ESC per svuotare rapidamente e mostrare tutto
    input.addEventListener("keydown", (ev) => {
      if (ev.key === "Escape") {
        input.value = "";
        applyFilter();
        input.blur();
      }
    });

    // Primo filtro (utile se input ha value precompilato, es. da ?q=)
    applyFilter();
  });

  /* =========================================================
     3) MICRO-UX (facoltativo)
        Aggiunge un title a righe marcate.
        - .is-blocked  => "Utente bloccato"
        - .is-pending  => "Elemento in attesa"
     ========================================================= */
  document.querySelectorAll(".is-blocked").forEach((el) => {
    if (!el.getAttribute("title")) el.setAttribute("title", "Utente bloccato");
  });

  document.querySelectorAll(".is-pending").forEach((el) => {
    if (!el.getAttribute("title")) el.setAttribute("title", "Elemento in attesa");
  });

  /* =========================================================
     4) POPUP NOTIFICA MODERAZIONE (eventi/recensioni in attesa)
        - Usa window.EC_ADMIN definito in admin_dashboard.php
        - Mostra un overlay solo se ci sono "nuovi" elementi
     ========================================================= */
  const cfg = window.EC_ADMIN || {};
  const pendingEvents  = Number(cfg.pendingEvents || 0);
  const pendingReviews = Number(cfg.pendingReviews || 0);
  const totalPending   = pendingEvents + pendingReviews;

  // Se showPopup è false o non c'è nulla in attesa, non faccio niente
  if (!cfg.showPopup || totalPending <= 0) {
    return;
  }

  const popup = document.createElement("div");
  popup.className = "admin-popup-moderazione";

  const parts = [];
  if (pendingEvents > 0) {
    parts.push(`${pendingEvents} event${pendingEvents > 1 ? "i" : ""} da approvare`);
  }
  if (pendingReviews > 0) {
    parts.push(`${pendingReviews} recension${pendingReviews > 1 ? "i" : ""} da moderare`);
  }

  const msg = "Hai " + parts.join(" e ") + ".";

  popup.innerHTML = `
    <div class="admin-popup-inner">
      <button type="button" class="admin-popup-close" aria-label="Chiudi">&times;</button>
      <h2>Moderazione in sospeso</h2>
      <p>${msg}</p>
      <div class="admin-popup-actions">
        ${pendingEvents > 0
          ? `<a href="${cfg.urlEventi || "#"}" class="btn btn-primary">Vai agli eventi</a>`
          : ""
        }
        ${pendingReviews > 0
          ? `<a href="${cfg.urlRecensioni || "#"}" class="btn btn-secondary">Vai alle recensioni</a>`
          : ""
        }
      </div>
    </div>
  `;

  document.body.appendChild(popup);

  const closeBtn = popup.querySelector(".admin-popup-close");
  if (closeBtn) {
    closeBtn.addEventListener("click", () => popup.remove());
  }

  // Chiudi cliccando sullo sfondo scuro
  popup.addEventListener("click", (e) => {
    if (e.target === popup) {
      popup.remove();
    }
  });

  // Chiudi il popup quando clicchi su uno dei bottoni "Vai a..."
  popup.querySelectorAll(".admin-popup-actions a").forEach((link) => {
    link.addEventListener("click", () => {
      popup.remove();
    });
  });
});