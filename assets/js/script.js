// assets/js/script.js
document.addEventListener("DOMContentLoaded", () => {
    // ---------------------------
    // TICKER (tuo codice)
    // ---------------------------
    const track = document.querySelector(".ticker-track");
    if (track) {
      const items = Array.from(track.children);
      if (items.length >= 2) {
        items.forEach(node => track.appendChild(node.cloneNode(true)));
  
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
    // LOGIN VALIDATION (NUOVO)
    // ---------------------------
    const form = document.getElementById("loginForm");
    if (!form) return; // se non siamo in login.php, esci
  
    const email = document.getElementById("email");
    const password = document.getElementById("password");
    const emailHint = document.getElementById("emailHint");
    const passwordHint = document.getElementById("passwordHint");
  
    const isValidEmail = (value) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
  
    const setError = (input, hintEl, msg) => {
      input.classList.add("is-invalid");
      input.setAttribute("aria-invalid", "true");
      if (hintEl) hintEl.textContent = msg;
    };
  
    const clearError = (input, hintEl) => {
      input.classList.remove("is-invalid");
      input.removeAttribute("aria-invalid");
      if (hintEl) hintEl.textContent = "";
    };
  
    const validate = () => {
      let ok = true;
  
      const e = (email.value || "").trim();
      const p = password.value || "";
  
      if (e === "") { ok = false; setError(email, emailHint, "Inserisci l'email."); }
      else if (!isValidEmail(e)) { ok = false; setError(email, emailHint, "Email non valida."); }
      else clearError(email, emailHint);
  
      if (p === "") { ok = false; setError(password, passwordHint, "Inserisci la password."); }
      else if (p.length < 8) { ok = false; setError(password, passwordHint, "Minimo 8 caratteri."); }
      else clearError(password, passwordHint);
  
      return ok;
    };
  
    email.addEventListener("blur", validate);
    password.addEventListener("blur", validate);
  
    form.addEventListener("submit", (ev) => {
      if (!validate()) ev.preventDefault();
    });
  });

  // ---------------------------
// REGISTER VALIDATION
// ---------------------------
document.addEventListener("DOMContentLoaded", () => {
    const form = document.getElementById("registerForm");
    if (!form) return;
  
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
      input.classList.add("is-invalid");
      input.setAttribute("aria-invalid", "true");
      if (hintEl) hintEl.textContent = msg;
    };
  
    const clearError = (input, hintEl) => {
      input.classList.remove("is-invalid");
      input.removeAttribute("aria-invalid");
      if (hintEl) hintEl.textContent = "";
    };
  
    const validate = () => {
      let ok = true;
  
      const n = (nome.value || "").trim();
      const c = (cognome.value || "").trim();
      const e = (email.value || "").trim();
      const p = password.value || "";
      const conf = conferma.value || "";
  
      if (n === "") { ok = false; setError(nome, nomeHint, "Inserisci il nome."); }
      else clearError(nome, nomeHint);
  
      if (c === "") { ok = false; setError(cognome, cognomeHint, "Inserisci il cognome."); }
      else clearError(cognome, cognomeHint);
  
      if (e === "") { ok = false; setError(email, emailHint, "Inserisci l'email."); }
      else if (!isValidEmail(e)) { ok = false; setError(email, emailHint, "Email non valida."); }
      else clearError(email, emailHint);
  
      if (p === "") { ok = false; setError(password, passwordHint, "Inserisci la password."); }
      else if (p.length < 8) { ok = false; setError(password, passwordHint, "Minimo 8 caratteri."); }
      else clearError(password, passwordHint);
  
      if (conf === "") { ok = false; setError(conferma, confermaHint, "Conferma la password."); }
      else if (conf !== p) { ok = false; setError(conferma, confermaHint, "Le password non coincidono."); }
      else clearError(conferma, confermaHint);
  
      return ok;
    };
  
    [nome, cognome, email, password, conferma].forEach(el => {
      el.addEventListener("blur", validate);
      el.addEventListener("input", () => {
        // opzionale: feedback più “live” su password/conferma
        if (el === password || el === conferma) validate();
      });
    });
  
    form.addEventListener("submit", (ev) => {
      if (!validate()) ev.preventDefault();
    });
  });
  
  