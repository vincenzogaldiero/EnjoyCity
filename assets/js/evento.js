// assets/js/evento.js
"use strict";

/**
 * Validazione form di prenotazione evento:
 * - quantità obbligatoria
 * - solo numeri interi
 * - range consentito: 1–10
 */
(function initBookingValidation() {

  // Riferimenti agli elementi del form
  const form = document.getElementById("bookingForm");
  if (!form) return;

  const qty = document.getElementById("quantita");
  const hint = document.getElementById("quantitaHint");

  if (!qty || !hint) return;

  /**
   * Mostra messaggio di errore e aggiunge classe CSS
   */
  function setError(msg) {
    hint.textContent = msg;
    qty.classList.add("is-invalid");
  }

  /**
   * Ripristina lo stato normale del campo
   */
  function clearError() {
    hint.textContent = "";
    qty.classList.remove("is-invalid");
  }

  /**
   * Controlla se la quantità è un intero valido tra 1 e 10
   */
  function isValidQuantity(value) {
    const v = Number(value);
    return Number.isInteger(v) && v >= 1 && v <= 10;
  }

  // Validazione in tempo reale durante la digitazione
  qty.addEventListener("input", () => {
    if (!isValidQuantity(qty.value)) {
      setError("Inserisci un numero intero tra 1 e 10.");
    } else {
      clearError();
    }
  });

  // Validazione finale al submit
  form.addEventListener("submit", (e) => {
    if (!isValidQuantity(qty.value)) {
      e.preventDefault();
      setError("Inserisci un numero intero tra 1 e 10.");
      qty.focus();
    }
  });
})();