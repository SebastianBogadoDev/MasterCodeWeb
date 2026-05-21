/* =====================================================
   COOKIES MODULE
   Gestión de consentimiento + Google Consent Mode v2
===================================================== */

const STORAGE_KEY = "cookie-consent";

/* ========================
   LEER CONSENTIMIENTO
======================== */

function readConsent() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    return raw ? JSON.parse(raw) : null;
  } catch {
    return null;
  }
}

/* ========================
   APLICAR A GTAG
======================== */

function applyConsentToGtag(consent) {
  if (typeof gtag !== "function") return;
  gtag("consent", "update", {
    analytics_storage:   consent.analytics  ? "granted" : "denied",
    ad_storage:          consent.marketing  ? "granted" : "denied",
    ad_user_data:        consent.marketing  ? "granted" : "denied",
    ad_personalization:  consent.marketing  ? "granted" : "denied",
  });
}

/* ========================
   RESTAURAR EN RECARGA
======================== */

export function restoreConsent() {
  const saved = readConsent();
  if (saved) applyConsentToGtag(saved);
}

/* ========================
   INIT BANNER
======================== */

export function initCookies() {
  const banner = document.getElementById("cookieBanner");

  if (!banner) return;

  const saved = readConsent();

  if (saved) {
    applyConsentToGtag(saved);
  } else {
    banner.classList.add("is-visible");
  }

  /* ========================
     BOTONES DEL BANNER
  ======================== */

  const acceptBtn = document.getElementById("acceptCookies");
  const rejectBtn  = document.getElementById("rejectCookies");
  const configBtn  = document.getElementById("configCookies");

  acceptBtn?.addEventListener("click", () => {
    saveConsent({ necessary: true, analytics: true, marketing: true });
  });

  rejectBtn?.addEventListener("click", () => {
    saveConsent({ necessary: true, analytics: false, marketing: false });
  });

  configBtn?.addEventListener("click", openModal);

  /* ========================
     MODAL (GRANULAR)
  ======================== */

  const modal    = document.getElementById("cookieModal");
  const saveBtn  = document.getElementById("saveCookies");
  const closeBtn = document.getElementById("closeCookieModal");

  saveBtn?.addEventListener("click", () => {
    const analytics = document.getElementById("cookieAnalytics")?.checked ?? false;
    const marketing = document.getElementById("cookieMarketing")?.checked ?? false;
    saveConsent({ necessary: true, analytics, marketing });
  });

  closeBtn?.addEventListener("click", closeModal);

  /* ========================
     FUNCIONES INTERNAS
  ======================== */

  function saveConsent(consent) {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(consent));
    } catch {
      // localStorage no disponible (modo privado, iframe, etc.)
    }
    applyConsentToGtag(consent);
    banner.classList.remove("is-visible");
    closeModal();
  }

  function openModal()  { modal?.classList.add("is-visible"); }
  function closeModal() { modal?.classList.remove("is-visible"); }
}
