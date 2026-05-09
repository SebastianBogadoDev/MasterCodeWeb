/* =====================================================
   EVENTS UTILS
===================================================== */

/* Evento simple */
export function on(element, event, callback) {
  element?.addEventListener(event, callback);
}

/* Evento múltiple */
export function onAll(elements, event, callback) {
  elements.forEach(el => {
    el.addEventListener(event, callback);
  });
}

/* Click fuera de un elemento */
export function onClickOutside(element, callback) {
  document.addEventListener("click", (e) => {
    if (!element.contains(e.target)) {
      callback();
    }
  });
}