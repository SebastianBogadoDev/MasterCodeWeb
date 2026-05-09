/* =====================================================
   GLOBAL SETTINGS
   Configuración central del proyecto
===================================================== */

export const SETTINGS = {

  /* ========================
     GENERAL
  ======================== */
  siteName: "MasterCodeWeb",
  baseUrl: "http://127.0.0.1:5500",

  /* ========================
     WHATSAPP
  ======================== */
  whatsapp: {
    phone: "34600000000", // 🔥 CAMBIA ESTO
    defaultMessage: "Hola, quiero información sobre tus servicios"
  },

  /* ========================
     COOKIES
  ======================== */
  cookies: {
    storageKey: "cookie-consent"
  },

  /* ========================
     BUDGET
  ======================== */
  budget: {
    defaultMode: "one", // one | monthly
    storageKey: "budget-mode"
  },

  /* ========================
     SEARCH
  ======================== */
  search: {
    minChars: 1
  }

};