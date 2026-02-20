// FILE: assets/js/admin.js
// JS per Area Admin (EnjoyCity)
//
// Funzioni:
// 1) Conferma azioni pericolose (data-confirm)
// 2) Ricerca live client-side su liste/tabelle (data-filter + data-filter-scope + data-filter-row)
//    - Esempio utenti:
//        <input data-filter="utenti">
//        <div data-filter-scope="utenti">
//          <article data-filter-row>...</article>
//        </div>
//    - Esempio eventi:
//        <input data-filter="eventi">
//        <div data-filter-scope="eventi">
//          <article data-filter-row>...</article>
//        </div>
// 3) Piccole migliorie UX (tooltip, reset ricerca con ESC)
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
});