// assets/js/script.js

// ============================================================
// TICKER + LOGIN + REGISTER VALIDATION
// ============================================================
document.addEventListener("DOMContentLoaded", () => {
  // ---------------------------
  // TICKER
  // ---------------------------
  const track = document.querySelector(".ticker-track");
  if (track) {
    const items = Array.from(track.children);
    if (items.length >= 2) {
      // Cloniamo gli elementi per avere un loop infinito
      items.forEach((node) => track.appendChild(node.cloneNode(true)));

      let x = 0;
      const speed = 0.6;

      function animate() {
        x -= speed;
        const half = track.scrollWidth / 2;
        if (Math.abs(x) >= half) x = 0;
        track.style.transform = `translateX(${x}px)`;
        requestAnimationFrame(animate);
      }

      animate();
    }
  }

  // ---------------------------
  // LOGIN VALIDATION
  // ---------------------------
  const loginForm = document.getElementById("loginForm");
  if (loginForm) {
    const email = document.getElementById("email");
    const password = document.getElementById("password");
    const emailHint = document.getElementById("emailHint");
    const passwordHint = document.getElementById("passwordHint");

    const isValidEmail = (value) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);

    const setError = (input, hintEl, msg) => {
      if (!input) return;
      input.classList.add("is-invalid");
      input.setAttribute("aria-invalid", "true");
      if (hintEl) hintEl.textContent = msg;
    };

    const clearError = (input, hintEl) => {
      if (!input) return;
      input.classList.remove("is-invalid");
      input.removeAttribute("aria-invalid");
      if (hintEl) hintEl.textContent = "";
    };

    const validateLogin = () => {
      let ok = true;

      const e = (email.value || "").trim();
      const p = password.value || "";

      if (e === "") {
        ok = false;
        setError(email, emailHint, "Inserisci l'email.");
      } else if (!isValidEmail(e)) {
        ok = false;
        setError(email, emailHint, "Email non valida.");
      } else {
        clearError(email, emailHint);
      }

      if (p === "") {
        ok = false;
        setError(password, passwordHint, "Inserisci la password.");
      } else if (p.length < 8) {
        ok = false;
        setError(password, passwordHint, "Minimo 8 caratteri.");
      } else {
        clearError(password, passwordHint);
      }

      return ok;
    };

    email.addEventListener("blur", validateLogin);
    password.addEventListener("blur", validateLogin);

    loginForm.addEventListener("submit", (ev) => {
      if (!validateLogin()) ev.preventDefault();
    });
  }

  // ---------------------------
  // REGISTER VALIDATION
  // ---------------------------
  const registerForm = document.getElementById("registerForm");
  if (registerForm) {
    const nome = document.getElementById("nome");
    const cognome = document.getElementById("cognome");
    const email = document.getElementById("email");
    const password = document.getElementById("password");
    const conferma = document.getElementById("conferma");

    const nomeHint = document.getElementById("nomeHint");
    const cognomeHint = document.getElementById("cognomeHint");
    const emailHint = document.getElementById("emailHint");
    const passwordHint = document.getElementById("passwordHint");
    const confermaHint = document.getElementById("confermaHint");

    const isValidEmail = (value) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);

    const setError = (input, hintEl, msg) => {
      if (!input) return;
      input.classList.add("is-invalid");
      input.setAttribute("aria-invalid", "true");
      if (hintEl) hintEl.textContent = msg;
    };

    const clearError = (input, hintEl) => {
      if (!input) return;
      input.classList.remove("is-invalid");
      input.removeAttribute("aria-invalid");
      if (hintEl) hintEl.textContent = "";
    };

    const validateRegister = () => {
      let ok = true;

      const n = (nome.value || "").trim();
      const c = (cognome.value || "").trim();
      const e = (email.value || "").trim();
      const p = password.value || "";
      const conf = conferma.value || "";

      if (n === "") {
        ok = false;
        setError(nome, nomeHint, "Inserisci il nome.");
      } else {
        clearError(nome, nomeHint);
      }

      if (c === "") {
        ok = false;
        setError(cognome, cognomeHint, "Inserisci il cognome.");
      } else {
        clearError(cognome, cognomeHint);
      }

      if (e === "") {
        ok = false;
        setError(email, emailHint, "Inserisci l'email.");
      } else if (!isValidEmail(e)) {
        ok = false;
        setError(email, emailHint, "Email non valida.");
      } else {
        clearError(email, emailHint);
      }

      if (p === "") {
        ok = false;
        setError(password, passwordHint, "Inserisci la password.");
      } else if (p.length < 8) {
        ok = false;
        setError(password, passwordHint, "Minimo 8 caratteri.");
      } else {
        clearError(password, passwordHint);
      }

      if (conf === "") {
        ok = false;
        setError(conferma, confermaHint, "Conferma la password.");
      } else if (conf !== p) {
        ok = false;
        setError(conferma, confermaHint, "Le password non coincidono.");
      } else {
        clearError(conferma, confermaHint);
      }

      return ok;
    };

    [nome, cognome, email, password, conferma].forEach((el) => {
      if (!el) return;
      el.addEventListener("blur", validateRegister);
      el.addEventListener("input", () => {
        // feedback più “live” su password/conferma
        if (el === password || el === conferma) validateRegister();
      });
    });

    registerForm.addEventListener("submit", (ev) => {
      if (!validateRegister()) ev.preventDefault();
    });
  }
});

// ============================================================
// FAQ Accordion
// ============================================================
document.addEventListener("click", (e) => {
  const btn = e.target.closest(".accordion-btn");
  if (!btn) return;

  const panelId = btn.getAttribute("aria-controls");
  const panel = document.getElementById(panelId);
  if (!panel) return;

  const isOpen = btn.getAttribute("aria-expanded") === "true";

  if (isOpen) {
    // chiudi
    btn.setAttribute("aria-expanded", "false");
    btn.classList.remove("is-open");
    panel.style.maxHeight = panel.scrollHeight + "px";
    requestAnimationFrame(() => {
      panel.style.maxHeight = "0px";
    });
    setTimeout(() => {
      panel.hidden = true;
    }, 220);
  } else {
    // apri
    btn.setAttribute("aria-expanded", "true");
    btn.classList.add("is-open");
    panel.hidden = false;
    panel.style.maxHeight = "0px";
    requestAnimationFrame(() => {
      panel.style.maxHeight = panel.scrollHeight + "px";
    });
  }
});
