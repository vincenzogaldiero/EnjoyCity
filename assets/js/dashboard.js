// assets/js/dashboard.js

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

// countdown
(function initCountdown() {
  const nodes = document.querySelectorAll("[data-countdown]");
  if (!nodes.length) return;

  function tick() {
    const now = Date.now();
    nodes.forEach((el) => {
      const target = new Date(el.getAttribute("data-countdown")).getTime();
      el.textContent = formatCountdown(target - now);
    });
  }

  tick();
  setInterval(tick, 1000);
})();

// validazione ricerca (nuova search: q opzionale, se presente min 2 char)
document.getElementById("searchForm")?.addEventListener("submit", (e) => {
  const q = document.querySelector('input[name="q"]')?.value.trim() ?? "";
  if (q && q.length < 2) {
    e.preventDefault();
    alert("Inserisci almeno 2 caratteri nella ricerca.");
  }
});

// validazione recensione (voto obbligatorio + testo 10-250)
document.getElementById("reviewForm")?.addEventListener("submit", (e) => {
  const votoEl = document.getElementById("voto");
  const testoEl = document.getElementById("testo");

  const voto = votoEl?.value ?? "";
  const testo = (testoEl?.value ?? "").trim();

  // reset classi
  votoEl?.classList.remove("is-invalid");
  testoEl?.classList.remove("is-invalid");

  if (!voto) {
    e.preventDefault();
    votoEl?.classList.add("is-invalid");
    alert("Seleziona un voto (1-5).");
    return;
  }

  if (testo.length < 10 || testo.length > 250) {
    e.preventDefault();
    testoEl?.classList.add("is-invalid");
    alert("Scrivi una recensione tra 10 e 250 caratteri.");
    return;
  }
});

// conferme (annulla prenotazione / altri)
document.querySelectorAll("[data-confirm]")?.forEach((el) => {
  const eventType = el.tagName.toLowerCase() === "form" ? "submit" : "click";
  el.addEventListener(eventType, (e) => {
    const msg = el.getAttribute("data-confirm") || "Sei sicuro?";
    if (!window.confirm(msg)) e.preventDefault();
  });
});
