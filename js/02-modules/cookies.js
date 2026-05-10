/* =====================================================
   COOKIES MODULE
   Gestión de consentimiento (localStorage)
===================================================== */

export function initCookies() {
  const banner = document.getElementById("cookieBanner");
  const modal = document.getElementById("cookieModal");

  if (!banner) return;

  const STORAGE_KEY = "cookie-consent";

  let savedConsent = null;
  try {
    savedConsent = localStorage.getItem(STORAGE_KEY);
  } catch {
    // localStorage no disponible (modo privado, iframe, etc.)
  }

  if (!savedConsent) {
    banner.classList.add("is-visible");
  }

  /* ========================
     BOTONES DEL BANNER
  ======================== */

  const acceptBtn = document.getElementById("acceptCookies");
  const rejectBtn = document.getElementById("rejectCookies");
  const configBtn = document.getElementById("configCookies");

  acceptBtn?.addEventListener("click", () => {
    saveConsent({ necessary: true, analytics: true, marketing: true });
  });

  rejectBtn?.addEventListener("click", () => {
    saveConsent({ necessary: true, analytics: false, marketing: false });
  });

  configBtn?.addEventListener("click", () => {
    openModal();
  });

  /* ========================
     MODAL
  ======================== */

  const saveBtn = document.getElementById("saveCookies");
  const closeBtn = document.getElementById("closeCookieModal");

  saveBtn?.addEventListener("click", () => {
    const analytics = document.getElementById("cookieAnalytics")?.checked;
    const marketing = document.getElementById("cookieMarketing")?.checked;
    saveConsent({ necessary: true, analytics, marketing });
  });

  closeBtn?.addEventListener("click", closeModal);

  /* ========================
     FUNCIONES
  ======================== */

  function saveConsent(consent) {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(consent));
    } catch {
      // No se pudo guardar el consentimiento
    }

    banner.classList.remove("is-visible");
    closeModal();
  }

  function openModal() {
    modal?.classList.add("is-visible");
  }

  function closeModal() {
    modal?.classList.remove("is-visible");
  }
}
