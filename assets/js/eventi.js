// assets/js/eventi.js
// Script per gestione geolocalizzazione e ordinamento "vicino a me"
// (pagina eventi.php)

document.addEventListener("DOMContentLoaded", function() {
    // Riferimenti agli elementi del form
    const btn          = document.getElementById("btn-vicino");
    const form         = document.getElementById("eventiFilterForm");
    const ordineSelect = document.getElementById("ordineSelect");
  
    // Se gli elementi non ci sono (es. utente non loggato), non faccio nulla
    if (!btn || !form || !ordineSelect) return;
  
    // Richiede la posizione all’utente e invia il form con lat/lon
    function requestGeoAndSubmit() {
      if (!navigator.geolocation) {
        alert("Geolocalizzazione non supportata dal browser.");
        return;
      }
  
      navigator.geolocation.getCurrentPosition(
        function (pos) {
          document.getElementById("geo-lat").value = pos.coords.latitude;
          document.getElementById("geo-lon").value = pos.coords.longitude;
          ordineSelect.value = "vicino";
          form.submit();
        },
        function () {
          alert("Permesso posizione negato o non disponibile.");
        },
        {
          enableHighAccuracy: true,
          timeout: 8000
        }
      );
    }
  
    // Click sul bottone "Vicino a me"
    btn.addEventListener("click", requestGeoAndSubmit);
  
    // Se seleziono "vicino" dal select senza coordinate → chiedo geo al submit
    form.addEventListener("submit", function(e) {
      if (ordineSelect.value !== "vicino") return;
  
      const lat = document.getElementById("geo-lat").value;
      const lon = document.getElementById("geo-lon").value;
  
      if (lat && lon) return;   // ho già le coordinate, ok
  
      e.preventDefault();
      requestGeoAndSubmit();
    });
  });