/* =====================================================
   DOM UTILS
===================================================== */

export const $ = (selector, parent = document) =>
  parent.querySelector(selector);

export const $$ = (selector, parent = document) =>
  parent.querySelectorAll(selector);

/* Crear elemento */
export function createElement(tag, className = "") {
  const el = document.createElement(tag);
  if (className) el.className = className;
  return el;
}

/* Añadir clase */
export function addClass(el, className) {
  el?.classList.add(className);
}

/* Quitar clase */
export function removeClass(el, className) {
  el?.classList.remove(className);
}

/* Toggle clase */
export function toggleClass(el, className) {
  el?.classList.toggle(className);
}