// assets/js/dashboard.js
"use strict";

/**
 * Converte un delta in millisecondi in una stringa tipo:
 * - "Tra 2g 3h 10m"
 * - "Tra 3h 20m 15s"
 * - "Tra 45s"
 */
function formatCountdown(ms) {
  if (ms <= 0) return "È iniziato!";

  const sec = Math.floor(ms / 1000);
  const d = Math.floor(sec / 86400);
  const h = Math.floor((sec % 86400) / 3600);
  const m = Math.floor((sec % 3600) / 60);
  const s = sec % 60;

  if (d > 0) return `Tra ${d}g ${h}h ${m}m`;
  if (h > 0) return `Tra ${h}h ${m}m ${s}s`;
  if (m > 0) return `Tra ${m}m ${s}s`;
  return `Tra ${s}s`;
}

/**
 * Inizializza i countdown sugli elementi che hanno l’attributo data-countdown
 * (es. <span data-countdown="2025-02-10T20:00:00"></span>)
 */
function initCountdown() {
  const nodes = document.querySelectorAll("[data-countdown]");
  if (!nodes.length) return;

  function tick() {
    const now = Date.now();

    nodes.forEach((el) => {
      const raw = el.getAttribute("data-countdown");
      const target = new Date(raw).getTime();

      if (Number.isNaN(target)) {
        // In caso di data non valida, evito NaN a schermo
        el.textContent = "Data non valida";
        return;
      }

      const diff = target - now;
      el.textContent = formatCountdown(diff);
    });
  }

  // Primo aggiornamento immediato
  tick();
  // Aggiorno ogni secondo
  setInterval(tick, 1000);
}

/**
 * Validazione form di ricerca:
 * - campo q opzionale
 * - se presente, deve avere almeno 2 caratteri
 */
function initSearchValidation() {
  const form = document.getElementById("searchForm");
  if (!form) return;

  form.addEventListener("submit", (e) => {
    const input = form.querySelector('input[name="q"]');
    const q = input?.value.trim() ?? "";

    if (q && q.length < 2) {
      e.preventDefault();
      alert("Inserisci almeno 2 caratteri nella ricerca.");
    }
  });
}

/**
 * Validazione form recensione:
 * - voto obbligatorio (select o input con id="voto")
 * - testo obbligatorio tra 10 e 250 caratteri
 * Aggiunge classe .is-invalid in caso di errore.
 */
function initReviewValidation() {
  const form = document.getElementById("reviewForm");
  if (!form) return;

  form.addEventListener("submit", (e) => {
    const votoEl = document.getElementById("voto");
    const testoEl = document.getElementById("testo");

    const voto = votoEl?.value ?? "";
    const testo = (testoEl?.value ?? "").trim();

    // reset classi
    votoEl?.classList.remove("is-invalid");
    testoEl?.classList.remove("is-invalid");

    // voto obbligatorio
    if (!voto) {
      e.preventDefault();
      votoEl?.classList.add("is-invalid");
      alert("Seleziona un voto (1-5).");
      return;
    }

    // lunghezza testo 10–250 caratteri
    if (testo.length < 10 || testo.length > 250) {
      e.preventDefault();
      testoEl?.classList.add("is-invalid");
      alert("Scrivi una recensione tra 10 e 250 caratteri.");
      return;
    }
  });
}

/**
 * Conferme generiche:
 * - per tutti gli elementi che hanno data-confirm
 * - se è un form → intercetto submit
 * - altrimenti intercetto click
 */
function initConfirmDialogs() {
  const confirmables = document.querySelectorAll("[data-confirm]");
  if (!confirmables.length) return;

  confirmables.forEach((el) => {
    const eventType = el.tagName.toLowerCase() === "form" ? "submit" : "click";

    el.addEventListener(eventType, (e) => {
      const msg = el.getAttribute("data-confirm") || "Sei sicuro?";
      if (!window.confirm(msg)) {
        e.preventDefault();
      }
    });
  });
}

// ===============================
// BOOTSTRAP SCRIPT
// ===============================
document.addEventListener("DOMContentLoaded", () => {
  initCountdown();
  initSearchValidation();
  initReviewValidation();
  initConfirmDialogs();
});