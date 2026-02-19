// FILE: assets/js/proponi_evento.js
// Scopo: validazioni client-side + UX prenotazione + geolocalizzazione
// Contesto:
//  - Form pubblico "Proponi evento"
//  - Form admin "Aggiungi evento"
// Entrambi usano id="formProponiEvento"
// Nota: la validazione SERVER-SIDE in PHP resta sempre l'unica verità.

document.addEventListener("DOMContentLoaded", () => {
  // Può esserci sia nel pubblico che in admin
  const form = document.getElementById("formProponiEvento");
  if (!form) return;

  // helper id -> element
  const $ = (id) => document.getElementById(id);

  // campi principali (se mancano in una pagina, semplicemente saranno null)
  const postiInput = $("posti_totali");
  const prenCheck = $("prenotazione_obbligatoria");
  const geoBtn = $("btn-geo-evento");
  const latEl = $("latitudine");
  const lonEl = $("longitudine");

  // elenco (inputId, hintId) usato per pulizia errori
  // NB: per longitudine usiamo geoHint (è quello presente nel form admin)
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
    ["longitudine", "geoHint"],
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
      if (hint) {
        hint.textContent = "";
        hint.classList.remove("is-error");
      }
      if (input && input.classList) {
        input.classList.remove("is-invalid");
      }
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
      if (typeof input.focus === "function") {
        input.focus();
      }
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

    // se compilato, deve essere intero ≥ 0
    const n = Number(raw);

    if (!Number.isInteger(n) || n < 0) {
      // non blocco qui: lo farà la validazione al submit, ma do feedback UX
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

  // attivo subito e al cambio
  syncPrenotazioneToPosti();
  postiInput?.addEventListener("input", syncPrenotazioneToPosti);
  postiInput?.addEventListener("change", syncPrenotazioneToPosti);

  /* =========================================================
     3) GEOLOCATION BUTTON:
        click => compila latitudine/longitudine
  ========================================================= */
  function setGeoMsg(msg, isErr = false) {
    const hint = $("geoHint");
    if (hint) {
      hint.textContent = msg;
      if (isErr) hint.classList.add("is-error");
      else hint.classList.remove("is-error");
    }

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
      (err) => {
        setGeoMsg("Permesso posizione negato o non disponibile (" + err.message + ").", true);
      },
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
    if (!titolo) {
      e.preventDefault();
      setError("titolo", "titoloHint", "Inserisci il titolo.");
      return;
    }
    if (titolo.length > 100) {
      e.preventDefault();
      setError("titolo", "titoloHint", "Max 100 caratteri.");
      return;
    }

    const breve = $("descrizione_breve")?.value.trim() ?? "";
    if (!breve) {
      e.preventDefault();
      setError("descrizione_breve", "breveHint", "Inserisci la descrizione breve.");
      return;
    }
    if (breve.length > 255) {
      e.preventDefault();
      setError("descrizione_breve", "breveHint", "Max 255 caratteri.");
      return;
    }

    const lunga = $("descrizione_lunga")?.value.trim() ?? "";
    if (!lunga) {
      e.preventDefault();
      setError("descrizione_lunga", "lungaHint", "Inserisci la descrizione lunga.");
      return;
    }

    const cat = $("categoria_id")?.value.trim() ?? "";
    if (!cat) {
      e.preventDefault();
      setError("categoria_id", "catHint", "Seleziona una categoria.");
      return;
    }

    const dataEvento = $("data_evento")?.value.trim() ?? "";
    if (!dataEvento) {
      e.preventDefault();
      setError("data_evento", "dataHint", "Inserisci data e ora.");
      return;
    }
    const dt = new Date(dataEvento);
    if (isNaN(dt.getTime())) {
      e.preventDefault();
      setError("data_evento", "dataHint", "Data/ora non valida.");
      return;
    }
    if (dt <= new Date()) {
      e.preventDefault();
      setError("data_evento", "dataHint", "La data/ora deve essere futura.");
      return;
    }

    const luogo = $("luogo")?.value.trim() ?? "";
    if (!luogo) {
      e.preventDefault();
      setError("luogo", "luogoHint", "Inserisci il luogo.");
      return;
    }
    if (luogo.length > 100) {
      e.preventDefault();
      setError("luogo", "luogoHint", "Max 100 caratteri.");
      return;
    }

    // posti_totali: vuoto ok; se compilato => intero ≥ 0
    const postiRaw = $("posti_totali")?.value.trim() ?? "";
    if (postiRaw !== "") {
      const posti = Number(postiRaw);
      if (!Number.isInteger(posti) || posti < 0) {
        e.preventDefault();
        setError("posti_totali", "postiHint", "Inserisci un intero ≥ 0 (0 = informativo).");
        return;
      }
    }

    // prezzo: vuoto ok; se compilato => numero ≥ 0
    const prezzoRaw = $("prezzo")?.value.trim() ?? "";
    if (prezzoRaw !== "") {
      if (!isNumberLike(prezzoRaw)) {
        e.preventDefault();
        setError("prezzo", "prezzoHint", "Prezzo non valido.");
        return;
      }
      const p = Number(prezzoRaw.replace(",", "."));
      if (p < 0) {
        e.preventDefault();
        setError("prezzo", "prezzoHint", "Il prezzo non può essere negativo.");
        return;
      }
    }

    // lat/lon: o entrambi vuoti, o entrambi compilati e validi
    const latRaw = $("latitudine")?.value.trim() ?? "";
    const lonRaw = $("longitudine")?.value.trim() ?? "";

    if ((latRaw !== "" && lonRaw === "") || (latRaw === "" && lonRaw !== "")) {
      e.preventDefault();
      if (latRaw && !lonRaw) {
        setError("longitudine", "geoHint", "Compila anche la longitudine.");
      } else {
        setError("latitudine", "latHint", "Compila anche la latitudine.");
      }
      return;
    }

    if (latRaw !== "" && lonRaw !== "") {
      if (!isNumberLike(latRaw)) {
        e.preventDefault();
        setError("latitudine", "latHint", "Latitudine non valida.");
        return;
      }
      if (!isNumberLike(lonRaw)) {
        e.preventDefault();
        setError("longitudine", "geoHint", "Longitudine non valida.");
        return;
      }

      const lat = Number(latRaw.replace(",", "."));
      const lon = Number(lonRaw.replace(",", "."));
      if (lat < -90 || lat > 90) {
        e.preventDefault();
        setError("latitudine", "latHint", "Range: -90 .. 90.");
        return;
      }
      if (lon < -180 || lon > 180) {
        e.preventDefault();
        setError("longitudine", "geoHint", "Range: -180 .. 180.");
        return;
      }
    }

    // immagine: opzionale, max 2MB, tipi consentiti
    const img = $("immagine");
    if (img && img.files && img.files.length === 1) {
      const f = img.files[0];
      const okTypes = ["image/jpeg", "image/png", "image/webp"];
      if (!okTypes.includes(f.type)) {
        e.preventDefault();
        setError("immagine", "imgHint", "Solo JPG/PNG/WEBP.");
        return;
      }
      if (f.size > 2 * 1024 * 1024) {
        e.preventDefault();
        setError("immagine", "imgHint", "Max 2MB.");
        return;
      }
    }

    // Se arrivo qui, lato client è tutto ok;
    // passa la palla al server PHP.
  });
});
