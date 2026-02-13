// FILE: assets/js/validazione_proponi_evento.js
document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("formProponiEvento");
  if (!form) return;

  const $ = (id) => document.getElementById(id);

  const fields = [
    ["titolo", "titoloHint"],
    ["descrizione_breve", "breveHint"],
    ["descrizione_lunga", "lungaHint"],
    ["data_evento", "dataHint"],
    ["luogo", "luogoHint"],
    ["prezzo", "prezzoHint"],
    ["posti_totali", "postiHint"],
    ["latitudine", "latHint"],
    ["longitudine", "lonHint"],
    ["immagine", "imgHint"],
  ];

  function clearHints() {
    fields.forEach(([_, hintId]) => {
      const hint = $(hintId);
      if (hint) hint.textContent = "";
    });
  }

  function setError(inputId, hintId, msg) {
    const input = $(inputId);
    const hint = $(hintId);
    if (hint) hint.textContent = msg;
    if (input) input.focus();
  }

  function isNumberLike(v) {
    const x = String(v).replace(",", ".").trim();
    if (x === "") return false;
    return !isNaN(x) && isFinite(Number(x));
  }

  form.addEventListener("submit", (e) => {
    clearHints();

    const titolo = $("titolo").value.trim();
    if (!titolo) return e.preventDefault(), setError("titolo", "titoloHint", "Inserisci il titolo.");
    if (titolo.length > 100) return e.preventDefault(), setError("titolo", "titoloHint", "Max 100 caratteri.");

    const breve = $("descrizione_breve").value.trim();
    if (!breve) return e.preventDefault(), setError("descrizione_breve", "breveHint", "Inserisci la descrizione breve.");
    if (breve.length > 255) return e.preventDefault(), setError("descrizione_breve", "breveHint", "Max 255 caratteri.");

    const lunga = $("descrizione_lunga").value.trim();
    if (!lunga) return e.preventDefault(), setError("descrizione_lunga", "lungaHint", "Inserisci la descrizione lunga.");

    const dataEvento = $("data_evento").value.trim();
    if (!dataEvento) return e.preventDefault(), setError("data_evento", "dataHint", "Inserisci data e ora.");
    const dt = new Date(dataEvento);
    if (isNaN(dt.getTime())) return e.preventDefault(), setError("data_evento", "dataHint", "Data/ora non valida.");

    const luogo = $("luogo").value.trim();
    if (!luogo) return e.preventDefault(), setError("luogo", "luogoHint", "Inserisci il luogo.");
    if (luogo.length > 100) return e.preventDefault(), setError("luogo", "luogoHint", "Max 100 caratteri.");

    const postiRaw = $("posti_totali").value.trim();
    if (postiRaw !== "") {
    const posti = Number(postiRaw);
    if (!Number.isInteger(posti) || posti <= 0) {
        return e.preventDefault(), setError("posti_totali", "postiHint", "Inserisci un intero > 0 oppure lascia vuoto.");
    }
    }


    const prezzoRaw = $("prezzo").value.trim();
    if (prezzoRaw !== "") {
      if (!isNumberLike(prezzoRaw)) return e.preventDefault(), setError("prezzo", "prezzoHint", "Prezzo non valido.");
      const p = Number(prezzoRaw.replace(",", "."));
      if (p < 0) return e.preventDefault(), setError("prezzo", "prezzoHint", "Il prezzo non può essere negativo.");
    }

    const latRaw = $("latitudine").value.trim();
    const lonRaw = $("longitudine").value.trim();

    if ((latRaw !== "" && lonRaw === "") || (latRaw === "" && lonRaw !== "")) {
      return e.preventDefault(), setError(latRaw ? "longitudine" : "latitudine", latRaw ? "lonHint" : "latHint", "Compila sia latitudine che longitudine.");
    }

    if (latRaw !== "" && lonRaw !== "") {
      if (!isNumberLike(latRaw)) return e.preventDefault(), setError("latitudine", "latHint", "Latitudine non valida.");
      if (!isNumberLike(lonRaw)) return e.preventDefault(), setError("longitudine", "lonHint", "Longitudine non valida.");

      const lat = Number(latRaw.replace(",", "."));
      const lon = Number(lonRaw.replace(",", "."));
      if (lat < -90 || lat > 90) return e.preventDefault(), setError("latitudine", "latHint", "Range: -90 .. 90.");
      if (lon < -180 || lon > 180) return e.preventDefault(), setError("longitudine", "lonHint", "Range: -180 .. 180.");
    }

    const img = $("immagine");
    if (img && img.files && img.files.length === 1) {
      const f = img.files[0];
      const okTypes = ["image/jpeg", "image/png", "image/webp"];
      if (!okTypes.includes(f.type)) return e.preventDefault(), setError("immagine", "imgHint", "Solo JPG/PNG/WEBP.");
      if (f.size > 2 * 1024 * 1024) return e.preventDefault(), setError("immagine", "imgHint", "Max 2MB.");
    }
  });
});
