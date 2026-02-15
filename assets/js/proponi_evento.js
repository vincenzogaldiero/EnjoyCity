// FILE: assets/js/proponi_evento.js
// Validazioni client-side + UX prenotazione + geolocalizzazione.
// NOTA: le validazioni server-side in PHP restano SEMPRE la fonte di verità.

document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("formProponiEvento");
  if (!form) return;

  // helper id -> element
  const $ = (id) => document.getElementById(id);

  // campi principali
  const postiInput = $("posti_totali");
  const prenCheck = $("prenotazione_obbligatoria");
  const geoBtn = $("btn-geo-evento");
  const latEl = $("latitudine");
  const lonEl = $("longitudine");

  // elenco (inputId, hintId) usato per pulizia errori
  const fields = [
    ["titolo", "titoloHint"],
    ["descrizione_breve", "breveHint"],
    ["descrizione_lunga", "lungaHint"],
    ["categoria_id", "catHint"],
    ["data_evento", "dataHint"],
    ["luogo", "luogoHint"],
    ["prezzo", "prezzoHint"],
    ["posti_totali", "postiHint"],
    ["latitudine", "latHint"],
    ["longitudine", "lonHint"],
    ["immagine", "imgHint"],
    ["btn-geo-evento", "geoHint"],
  ];

  /* =========================================================
     1) HELPERS: errori e info
  ========================================================= */
  function clearHints() {
    fields.forEach(([inputId, hintId]) => {
      const hint = $(hintId);
      const input = $(inputId);
      if (hint) hint.textContent = "";
      if (input && input.classList) input.classList.remove("is-invalid");
    });
  }

  function setError(inputId, hintId, msg) {
    const input = $(inputId);
    const hint = $(hintId);

    if (hint) {
      hint.textContent = msg;
      hint.classList.add("is-error");
    }
    if (input && input.classList) {
      input.classList.add("is-invalid");
      if (typeof input.focus === "function") input.focus();
    }
  }

  function setInfo(hintId, msg) {
    const hint = $(hintId);
    if (hint) {
      hint.textContent = msg;
      hint.classList.remove("is-error");
    }
  }

  function isNumberLike(v) {
    const x = String(v).replace(",", ".").trim();
    if (x === "") return false;
    return !isNaN(x) && isFinite(Number(x));
  }

  /* =========================================================
     2) EVENTO INFORMATIVO:
        - DB: posti_totali NOT NULL
        - Regola progetto: vuoto oppure 0 => informativo
        => prenotazione deve essere OFF
  ========================================================= */
  function syncPrenotazioneToPosti() {
    if (!postiInput || !prenCheck) return;

    const raw = postiInput.value.trim();

    // vuoto => informativo
    if (raw === "") {
      prenCheck.checked = false;
      prenCheck.disabled = true;
      setInfo("postiHint", "Evento informativo: posti vuoti → prenotazione disattivata.");
      return;
    }

    // se compilato, deve essere intero >= 0
    const n = Number(raw);

    if (!Number.isInteger(n) || n < 0) {
      // non blocco qui: lo farà submit, ma do feedback UX
      prenCheck.checked = false;
      prenCheck.disabled = true;
      setInfo("postiHint", "Posti totali non validi. Inserisci un intero ≥ 0.");
      return;
    }

    // 0 => informativo
    if (n === 0) {
      prenCheck.checked = false;
      prenCheck.disabled = true;
      setInfo("postiHint", "Evento informativo: posti 0 → prenotazione disattivata.");
      return;
    }

    // n > 0 => prenotazione può essere attivata
    prenCheck.disabled = false;
    setInfo("postiHint", "Inserisci un intero ≥ 0. (0 o vuoto = evento informativo).");
  }

  syncPrenotazioneToPosti();
  postiInput?.addEventListener("input", syncPrenotazioneToPosti);
  postiInput?.addEventListener("change", syncPrenotazioneToPosti);

  /* =========================================================
     3) GEOLOCATION BUTTON:
        click => compila latitudine/longitudine
  ========================================================= */
  function setGeoMsg(msg, isErr = false) {
    const hint = $("geoHint");
    if (hint) hint.textContent = msg;

    if (latEl && lonEl) {
      if (isErr) {
        latEl.classList.add("is-invalid");
        lonEl.classList.add("is-invalid");
      } else {
        latEl.classList.remove("is-invalid");
        lonEl.classList.remove("is-invalid");
      }
    }
  }

  geoBtn?.addEventListener("click", () => {
    setGeoMsg("");

    if (!navigator.geolocation) {
      setGeoMsg("Geolocalizzazione non supportata dal browser.", true);
      return;
    }

    setGeoMsg("Richiesta posizione…");

    navigator.geolocation.getCurrentPosition(
      (pos) => {
        const lat = pos.coords.latitude;
        const lon = pos.coords.longitude;

        if (latEl) latEl.value = String(lat.toFixed(6));
        if (lonEl) lonEl.value = String(lon.toFixed(6));

        setGeoMsg("Posizione inserita ✅");
      },
      () => setGeoMsg("Permesso posizione negato o non disponibile.", true),
      { enableHighAccuracy: true, timeout: 8000 }
    );
  });

  /* =========================================================
     4) VALIDAZIONE SUBMIT (client-side)
        - non sostituisce la validazione server-side
  ========================================================= */
  form.addEventListener("submit", (e) => {
    clearHints();

    const titolo = $("titolo")?.value.trim() ?? "";
    if (!titolo) return e.preventDefault(), setError("titolo", "titoloHint", "Inserisci il titolo.");
    if (titolo.length > 100) return e.preventDefault(), setError("titolo", "titoloHint", "Max 100 caratteri.");

    const breve = $("descrizione_breve")?.value.trim() ?? "";
    if (!breve) return e.preventDefault(), setError("descrizione_breve", "breveHint", "Inserisci la descrizione breve.");
    if (breve.length > 255) return e.preventDefault(), setError("descrizione_breve", "breveHint", "Max 255 caratteri.");

    const lunga = $("descrizione_lunga")?.value.trim() ?? "";
    if (!lunga) return e.preventDefault(), setError("descrizione_lunga", "lungaHint", "Inserisci la descrizione lunga.");

    const cat = $("categoria_id")?.value.trim() ?? "";
    if (!cat) return e.preventDefault(), setError("categoria_id", "catHint", "Seleziona una categoria.");

    const dataEvento = $("data_evento")?.value.trim() ?? "";
    if (!dataEvento) return e.preventDefault(), setError("data_evento", "dataHint", "Inserisci data e ora.");
    const dt = new Date(dataEvento);
    if (isNaN(dt.getTime())) return e.preventDefault(), setError("data_evento", "dataHint", "Data/ora non valida.");
    if (dt <= new Date()) return e.preventDefault(), setError("data_evento", "dataHint", "La data/ora deve essere futura.");

    const luogo = $("luogo")?.value.trim() ?? "";
    if (!luogo) return e.preventDefault(), setError("luogo", "luogoHint", "Inserisci il luogo.");
    if (luogo.length > 100) return e.preventDefault(), setError("luogo", "luogoHint", "Max 100 caratteri.");

    // posti_totali: vuoto ok; se compilato => intero >= 0
    const postiRaw = $("posti_totali")?.value.trim() ?? "";
    if (postiRaw !== "") {
      const posti = Number(postiRaw);
      if (!Number.isInteger(posti) || posti < 0) {
        return e.preventDefault(), setError("posti_totali", "postiHint", "Inserisci un intero ≥ 0 (0 = informativo).");
      }
    }

    // prezzo: vuoto ok; se compilato => numero >= 0
    const prezzoRaw = $("prezzo")?.value.trim() ?? "";
    if (prezzoRaw !== "") {
      if (!isNumberLike(prezzoRaw)) return e.preventDefault(), setError("prezzo", "prezzoHint", "Prezzo non valido.");
      const p = Number(prezzoRaw.replace(",", "."));
      if (p < 0) return e.preventDefault(), setError("prezzo", "prezzoHint", "Il prezzo non può essere negativo.");
    }

    // lat/lon: o entrambi vuoti, o entrambi compilati e validi
    const latRaw = $("latitudine")?.value.trim() ?? "";
    const lonRaw = $("longitudine")?.value.trim() ?? "";

    if ((latRaw !== "" && lonRaw === "") || (latRaw === "" && lonRaw !== "")) {
      return e.preventDefault(),
        setError(latRaw ? "longitudine" : "latitudine", latRaw ? "lonHint" : "latHint", "Compila sia latitudine che longitudine.");
    }

    if (latRaw !== "" && lonRaw !== "") {
      if (!isNumberLike(latRaw)) return e.preventDefault(), setError("latitudine", "latHint", "Latitudine non valida.");
      if (!isNumberLike(lonRaw)) return e.preventDefault(), setError("longitudine", "lonHint", "Longitudine non valida.");

      const lat = Number(latRaw.replace(",", "."));
      const lon = Number(lonRaw.replace(",", "."));
      if (lat < -90 || lat > 90) return e.preventDefault(), setError("latitudine", "latHint", "Range: -90 .. 90.");
      if (lon < -180 || lon > 180) return e.preventDefault(), setError("longitudine", "lonHint", "Range: -180 .. 180.");
    }

    // immagine: opzionale, max 2MB, tipi consentiti
    const img = $("immagine");
    if (img && img.files && img.files.length === 1) {
      const f = img.files[0];
      const okTypes = ["image/jpeg", "image/png", "image/webp"];
      if (!okTypes.includes(f.type)) return e.preventDefault(), setError("immagine", "imgHint", "Solo JPG/PNG/WEBP.");
      if (f.size > 2 * 1024 * 1024) return e.preventDefault(), setError("immagine", "imgHint", "Max 2MB.");
    }
  });
});
