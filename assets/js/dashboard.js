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
  
  // validazione ricerca
  document.getElementById("searchForm")?.addEventListener("submit", (e) => {
    const dove = document.getElementById("dove")?.value.trim() ?? "";
    if (dove && dove.length < 2) {
      e.preventDefault();
      alert("Inserisci un luogo valido (almeno 2 caratteri).");
    }
  });
  
  // validazione recensione
  document.getElementById("reviewForm")?.addEventListener("submit", (e) => {
    const voto = document.getElementById("voto")?.value ?? "";
    const testo = document.getElementById("testo")?.value.trim() ?? "";
  
    if (!voto) {
      e.preventDefault();
      alert("Seleziona un voto (1-5).");
      return;
    }
    if (testo.length < 10) {
      e.preventDefault();
      alert("Scrivi almeno 10 caratteri.");
      return;
    }
  });
  
  // conferme (annulla prenotazione / logout / altri)
  document.querySelectorAll("[data-confirm]")?.forEach((el) => {
    const eventType = el.tagName.toLowerCase() === "form" ? "submit" : "click";
    el.addEventListener(eventType, (e) => {
      const msg = el.getAttribute("data-confirm") || "Sei sicuro?";
      if (!window.confirm(msg)) e.preventDefault();
    });
  });
  