// FILE: assets/js/script.js
// Scopo: effetti UI globali (ticker eventi, FAQ accordion)
//        + validazione client-side per login e registrazione.
// Nota: la validazione lato client migliora la UX, ma la
//       sicurezza resta in carico alla validazione PHP server-side.

"use strict";

// ============================================================
// HELPERS GENERICI PER I FORM (email + errori)
// ============================================================

/**
 * Controlla se una stringa ha un formato email accettabile.
 * Non è una regex "perfetta", ma è sufficiente per il contesto web.
 */
function isValidEmail(value) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
}

/**
 * Imposta lo stato di errore su un input:
 * - aggiunge la classe .is-invalid
 * - imposta aria-invalid="true" per accessibilità
 * - scrive il messaggio nell'elemento hint associato
 */
function setFieldError(input, hintEl, msg) {
  if (!input) return;
  input.classList.add("is-invalid");
  input.setAttribute("aria-invalid", "true");
  if (hintEl) {
    hintEl.textContent = msg;
  }
}

/**
 * Rimuove lo stato di errore da un input:
 * - rimuove .is-invalid
 * - rimuove aria-invalid
 * - pulisce il testo di hint
 */
function clearFieldError(input, hintEl) {
  if (!input) return;
  input.classList.remove("is-invalid");
  input.removeAttribute("aria-invalid");
  if (hintEl) {
    hintEl.textContent = "";
  }
}

// ============================================================
// INIT SU DOM READY
// ============================================================
document.addEventListener("DOMContentLoaded", () => {
  initTicker();
  initLoginValidation();
  initRegisterValidation();
});

// ============================================================
// TICKER (marquee eventi in homepage)
// ============================================================

/**
 * Crea un ticker orizzontale infinito clonando gli item esistenti
 * e traslandoli con requestAnimationFrame.
 */
function initTicker() {
  const track = document.querySelector(".ticker-track");
  if (!track) return;

  const items = Array.from(track.children);
  if (items.length < 2) return;

  // Cloniamo gli elementi per simulare un loop continuo
  items.forEach((node) => track.appendChild(node.cloneNode(true)));

  let x = 0;
  const speed = 0.6; // px per frame

  function animate() {
    x -= speed;

    // quando abbiamo traslato metà della lunghezza, resettiamo
    const half = track.scrollWidth / 2;
    if (Math.abs(x) >= half) {
      x = 0;
    }

    track.style.transform = `translateX(${x}px)`;
    requestAnimationFrame(animate);
  }

  animate();
}

// ============================================================
// LOGIN VALIDATION
// ============================================================

/**
 * Validazione form di login:
 * - email obbligatoria e in formato valido
 * - password obbligatoria, minimo 8 caratteri
 */
function initLoginValidation() {
  const loginForm   = document.getElementById("loginForm");
  if (!loginForm) return;

  const email        = document.getElementById("email");
  const password     = document.getElementById("password");
  const emailHint    = document.getElementById("emailHint");
  const passwordHint = document.getElementById("passwordHint");

  function validateLogin() {
    let ok = true;

    const e = (email?.value || "").trim();
    const p = password?.value || "";

    // Email
    if (!e) {
      ok = false;
      setFieldError(email, emailHint, "Inserisci l'email.");
    } else if (!isValidEmail(e)) {
      ok = false;
      setFieldError(email, emailHint, "Email non valida.");
    } else {
      clearFieldError(email, emailHint);
    }

    // Password
    if (!p) {
      ok = false;
      setFieldError(password, passwordHint, "Inserisci la password.");
    } else if (p.length < 8) {
      ok = false;
      setFieldError(password, passwordHint, "Minimo 8 caratteri.");
    } else {
      clearFieldError(password, passwordHint);
    }

    return ok;
  }

  // Validazione al blur (quando il campo perde il focus)
  email?.addEventListener("blur", validateLogin);
  password?.addEventListener("blur", validateLogin);

  // Validazione finale al submit
  loginForm.addEventListener("submit", (ev) => {
    if (!validateLogin()) {
      ev.preventDefault();
    }
  });
}

// ============================================================
// REGISTER VALIDATION
// ============================================================

/**
 * Validazione form di registrazione:
 * - nome e cognome obbligatori
 * - email obbligatoria e valida
 * - password obbligatoria, minimo 8 caratteri
 * - conferma password obbligatoria e uguale alla password
 */
function initRegisterValidation() {
  const registerForm = document.getElementById("registerForm");
  if (!registerForm) return;

  const nome     = document.getElementById("nome");
  const cognome  = document.getElementById("cognome");
  const email    = document.getElementById("email");
  const password = document.getElementById("password");
  const conferma = document.getElementById("conferma");

  const nomeHint     = document.getElementById("nomeHint");
  const cognomeHint  = document.getElementById("cognomeHint");
  const emailHint    = document.getElementById("emailHint");
  const passwordHint = document.getElementById("passwordHint");
  const confHint     = document.getElementById("confermaHint");

  function validateRegister() {
    let ok = true;

    const n    = (nome?.value || "").trim();
    const c    = (cognome?.value || "").trim();
    const e    = (email?.value || "").trim();
    const p    = password?.value || "";
    const conf = conferma?.value || "";

    // Nome
    if (!n) {
      ok = false;
      setFieldError(nome, nomeHint, "Inserisci il nome.");
    } else {
      clearFieldError(nome, nomeHint);
    }

    // Cognome
    if (!c) {
      ok = false;
      setFieldError(cognome, cognomeHint, "Inserisci il cognome.");
    } else {
      clearFieldError(cognome, cognomeHint);
    }

    // Email
    if (!e) {
      ok = false;
      setFieldError(email, emailHint, "Inserisci l'email.");
    } else if (!isValidEmail(e)) {
      ok = false;
      setFieldError(email, emailHint, "Email non valida.");
    } else {
      clearFieldError(email, emailHint);
    }

    // Password
    if (!p) {
      ok = false;
      setFieldError(password, passwordHint, "Inserisci la password.");
    } else if (p.length < 8) {
      ok = false;
      setFieldError(password, passwordHint, "Minimo 8 caratteri.");
    } else {
      clearFieldError(password, passwordHint);
    }

    // Conferma password
    if (!conf) {
      ok = false;
      setFieldError(conferma, confHint, "Conferma la password.");
    } else if (conf !== p) {
      ok = false;
      setFieldError(conferma, confHint, "Le password non coincidono.");
    } else {
      clearFieldError(conferma, confHint);
    }

    return ok;
  }

  // Validazione al blur e input (per feedback più "live")
  [nome, cognome, email, password, conferma].forEach((el) => {
    if (!el) return;
    el.addEventListener("blur", validateRegister);

    // su password/conferma aggiorno in tempo reale mentre l'utente scrive
    if (el === password || el === conferma) {
      el.addEventListener("input", validateRegister);
    }
  });

  registerForm.addEventListener("submit", (ev) => {
    if (!validateRegister()) {
      ev.preventDefault();
    }
  });
}

// ============================================================
// FAQ Accordion
// ============================================================

/**
 * Gestione accordion FAQ:
 * - usa event delegation sul document
 * - ogni bottone .accordion-btn controlla un pannello via aria-controls
 * - lo stato aperto/chiuso è indicato da aria-expanded
 */
document.addEventListener("click", (e) => {
  const btn = e.target.closest(".accordion-btn");
  if (!btn) return;

  const panelId = btn.getAttribute("aria-controls");
  const panel   = document.getElementById(panelId);
  if (!panel) return;

  const isOpen = btn.getAttribute("aria-expanded") === "true";

  if (isOpen) {
    // Chiudi
    btn.setAttribute("aria-expanded", "false");
    btn.classList.remove("is-open");

    // transizione altezza: dallo stato attuale a 0
    panel.style.maxHeight = panel.scrollHeight + "px";
    requestAnimationFrame(() => {
      panel.style.maxHeight = "0px";
    });

    // nasconde il contenuto dopo la transizione
    setTimeout(() => {
      panel.hidden = true;
    }, 220);
  } else {
    // Apri
    btn.setAttribute("aria-expanded", "true");
    btn.classList.add("is-open");

    panel.hidden = false;
    panel.style.maxHeight = "0px";
    requestAnimationFrame(() => {
      panel.style.maxHeight = panel.scrollHeight + "px";
    });
  }
});