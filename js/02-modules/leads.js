/* =====================================================
   LEADS MODULE
   Envía un lead silencioso al servidor cuando el
   usuario interactúa con un formulario de contacto.
   Los botones de pago (.mcw-pago-btn) son gestionados
   exclusivamente por payments.js.
===================================================== */

const API_BASE = "/api";

/**
 * Envía un lead al servidor en background.
 * No lanza excepciones — fallo silencioso intencional.
 */
export async function sendLead({ nombre = "", email = "", plan = "", precio = "", origen = "web" }) {
  try {
    await fetch(`${API_BASE}/leads`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ nombre, email, plan, precio, origen })
    });
  } catch {
    // Fallo silencioso — el lead no es bloqueante para el usuario
  }
}

export function initLeads() {
  // Este módulo no registra eventos propios.
  // sendLead() es llamado desde forms.js y payments.js.
}
