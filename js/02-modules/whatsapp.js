/* =====================================================
   WHATSAPP PRO MODULE
   Widget tarjeta + lógica inteligente
===================================================== */

export function initWhatsapp() {

  const card = document.getElementById("wa-card");
  const closeBtn = document.getElementById("wa-close");
  const waButton = document.getElementById("wa-button");

  if (!card) return;

  const STORAGE_KEY = "wa-seen";

  /* ========================
     MOSTRAR AUTOMÁTICO (1 sola vez)
  ======================== */

  const alreadySeen = localStorage.getItem(STORAGE_KEY);

  if (!alreadySeen) {
    setTimeout(() => {
      card.style.display = "block";
      card.style.opacity = "1";
    }, 2000);

    localStorage.setItem(STORAGE_KEY, "true");
  }

  /* ========================
     BOTÓN WHATSAPP
  ======================== */

  waButton?.addEventListener("click", () => {

    const phone = "34680762047"; // 🔥 TU NÚMERO REAL

    const message = encodeURIComponent(
      "Hola, quiero información sobre tus servicios web"
    );

    const url = `https://wa.me/${phone}?text=${message}`;

    window.open(url, "_blank");

  });

  /* ========================
     CERRAR WIDGET
  ======================== */

  closeBtn?.addEventListener("click", () => {
    card.style.display = "none";
  });

}