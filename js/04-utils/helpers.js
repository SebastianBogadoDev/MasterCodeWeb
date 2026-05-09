/* =====================================================
   HELPERS UTILS
===================================================== */

/* Capitalizar texto */
export function capitalize(text) {
  return text.charAt(0).toUpperCase() + text.slice(1);
}

/* Formatear precio */
export function formatPrice(value) {
  return `${value}€`;
}

/* Debounce (para search) */
export function debounce(func, delay = 300) {
  let timeout;

  return (...args) => {
    clearTimeout(timeout);
    timeout = setTimeout(() => func(...args), delay);
  };
}

/* Generar ID único */
export function uid() {
  return "_" + Math.random().toString(36).substr(2, 9);
}