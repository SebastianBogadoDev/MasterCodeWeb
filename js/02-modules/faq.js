/* =====================================================
   FAQ ACCORDION · MASTERCODEWEB
===================================================== */

export function initFaq() {
  const items = document.querySelectorAll(".faq__item");
  if (!items.length) return;

  items.forEach(item => {
    const trigger = item.querySelector(".faq__trigger");
    if (!trigger) return;

    trigger.addEventListener("click", () => {
      const isOpen = item.classList.contains("is-open");

      // Cierra todos
      items.forEach(el => {
        el.classList.remove("is-open");
        el.querySelector(".faq__trigger")?.setAttribute("aria-expanded", "false");
      });

      // Abre el clickeado (si estaba cerrado)
      if (!isOpen) {
        item.classList.add("is-open");
        trigger.setAttribute("aria-expanded", "true");
      }
    });

    // Soporte teclado
    trigger.addEventListener("keydown", e => {
      if (e.key === "Enter" || e.key === " ") {
        e.preventDefault();
        trigger.click();
      }
    });
  });
}
