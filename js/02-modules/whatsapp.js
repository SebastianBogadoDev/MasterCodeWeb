/* =====================================================
   WHATSAPP MODULE
   Oculta el FAB mientras el usuario hace scroll y lo
   restaura poco después de que el scroll se detenga.
   Solo gestiona la clase .is-scrolling: los demás estados
   de ocultación (banner de cookies, drawer móvil, CTA
   flotante de artículo) son reglas CSS independientes en
   whatsapp.css, así que quitar .is-scrolling nunca
   restaura el FAB si alguno de ellos sigue activo.
===================================================== */

const SCROLL_IDLE_MS = 250;

export function initWhatsapp() {
  const fab = document.querySelector(".wa-fab");
  if (!fab) return;

  let idleTimer = 0;

  window.addEventListener("scroll", () => {
    if (!idleTimer) fab.classList.add("is-scrolling");

    clearTimeout(idleTimer);
    idleTimer = setTimeout(() => {
      idleTimer = 0;
      fab.classList.remove("is-scrolling");
    }, SCROLL_IDLE_MS);
  }, { passive: true });
}
