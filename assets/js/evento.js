// assets/js/evento.js
(function () {
  const form = document.getElementById("bookingForm");
  if (!form) return;

  const qty = document.getElementById("quantita");
  const hint = document.getElementById("quantitaHint");

  function setError(msg) {
    hint.textContent = msg;
    qty.classList.add("is-invalid");
  }

  function clearError() {
    hint.textContent = "";
    qty.classList.remove("is-invalid");
  }

  qty.addEventListener("input", () => {
    const v = Number(qty.value);
    if (!Number.isInteger(v) || v < 1 || v > 10) {
      setError("Inserisci un numero tra 1 e 10.");
    } else {
      clearError();
    }
  });

  form.addEventListener("submit", (e) => {
    const v = Number(qty.value);
    if (!Number.isInteger(v) || v < 1 || v > 10) {
      e.preventDefault();
      setError("Inserisci un numero tra 1 e 10.");
      qty.focus();
    }
  });
})();
