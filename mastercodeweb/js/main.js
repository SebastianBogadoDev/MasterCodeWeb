/* =====================================================
   MASTER CODE WEB
   JavaScript Core System
   Versión: 3.0 Profesional
   Arquitectura modular interna
===================================================== */

"use strict";

/* =====================================================
   01. UTILIDADES GLOBALES
   Helpers reutilizables
===================================================== */

const MCW = {

  /* Selector corto */
  qs: (selector, scope = document) => scope.querySelector(selector),

  /* Selector múltiple */
  qsa: (selector, scope = document) => [...scope.querySelectorAll(selector)],

  /* LocalStorage seguro */
  storage: {
    get(key) {
      try {
        return JSON.parse(localStorage.getItem(key));
      } catch {
        return null;
      }
    },
    set(key, value) {
      localStorage.setItem(key, JSON.stringify(value));
    },
    remove(key) {
      localStorage.removeItem(key);
    }
  }

};


/* =====================================================
   02. NAVEGACIÓN RESPONSIVE
   Menú móvil accesible
===================================================== */

const Navigation = (() => {

  function init() {

    const toggle = MCW.qs(".menu-toggle");
    const nav = MCW.qs(".nav");

    if (!toggle || !nav) return;

    toggle.addEventListener("click", () => {
      const isOpen = nav.classList.toggle("active");
      toggle.setAttribute("aria-expanded", isOpen);
    });

    /* Cierre automático al hacer click en enlace */
    MCW.qsa(".nav__list a").forEach(link => {
      link.addEventListener("click", () => {
        nav.classList.remove("active");
        toggle.setAttribute("aria-expanded", false);
      });
    });

  }

  return { init };

})();


/* =====================================================
   03. SISTEMA DE PRESUPUESTO
   Cálculo dinámico + validación avanzada
===================================================== */

const BudgetSystem = (() => {

  let currentMode = "one";

  function init() {

    const body = document.body;
    const modeButtons = MCW.qsa(".budget-mode__btn");
    const items = MCW.qsa(".budget-item");
    const totalEl = MCW.qs("#budgetTotal");
    const serviciosTextarea = MCW.qs("#serviciosElegidos");
    const form = MCW.qs(".budget-form");

    if (!modeButtons.length || !items.length || !form) return;

    /* ===============================
       03.1 CAMBIO DE MODO
    =============================== */

    modeButtons.forEach(btn => {
      btn.addEventListener("click", () => {

        modeButtons.forEach(b => b.classList.remove("is-active"));
        btn.classList.add("is-active");

        currentMode = btn.dataset.mode;
        body.setAttribute("data-budget-mode", currentMode);

        calculateTotal();
      });
    });

    /* ===============================
       03.2 CÁLCULO DE TOTAL
    =============================== */

    function calculateTotal() {

      let total = 0;
      let selectedServices = [];

      items.forEach(item => {

        if (!item.checked) return;

        const name = item.dataset.name;
        const onePrice = parseFloat(item.dataset.price);
        const monthlyPrice = parseFloat(item.dataset.monthly);

        if (currentMode === "one") {
          total += onePrice;
          selectedServices.push(`${name} (${onePrice}€ pago único)`);
        } else {
          total += monthlyPrice;
          selectedServices.push(`${name} (${monthlyPrice}€/mes)`);
        }

      });

      totalEl.textContent = currentMode === "one"
        ? `${total}€`
        : `${total}€/mes`;

      serviciosTextarea.value = selectedServices.join("\n");

    }

    items.forEach(item => {
      item.addEventListener("change", calculateTotal);
    });

    /* ===============================
       03.3 VALIDACIÓN AVANZADA
    =============================== */

    form.addEventListener("submit", (e) => {

      const name = form.querySelector("input[type='text']");
      const email = form.querySelector("input[type='email']");
      const phone = form.querySelector("input[type='tel']");
      const privacy = form.querySelector("input[type='checkbox']");

      const phoneRegex = /^\+\d{1,3}\s?\d{6,14}$/;

      if (!name.value.trim()) {
        alert("Introduce tu nombre completo.");
        e.preventDefault();
        return;
      }

      if (!email.validity.valid) {
        alert("Introduce un correo electrónico válido.");
        e.preventDefault();
        return;
      }

      if (!phoneRegex.test(phone.value.trim())) {
        alert("Introduce un teléfono válido. Ej: +34 680 760 047");
        e.preventDefault();
        return;
      }

      if (!privacy.checked) {
        alert("Debes aceptar la política de privacidad.");
        e.preventDefault();
        return;
      }

      if (serviciosTextarea.value.trim() === "") {
        alert("Selecciona al menos un servicio.");
        e.preventDefault();
        return;
      }

    });

  }

  return { init };

})();


/* =====================================================
   04. COOKIE CONSENT SYSTEM RGPD
   Versión profesional controlada
===================================================== */

const CookieSystem = (() => {

  const CONSENT_KEY = "mcw_cookie_consent_v3";
  const VERSION = "3.0";

  function init() {

    const banner = MCW.qs("#cookieBanner");
    const modal = MCW.qs("#cookieModal");

    if (!banner) return;

    const consent = MCW.storage.get(CONSENT_KEY);

    if (!consent || consent.version !== VERSION) {
      banner.classList.add("is-visible");
    } else {
      applyConsent(consent);
    }

    /* Eventos */
    MCW.qs("#acceptCookies")?.addEventListener("click", () => save(true, true));
    MCW.qs("#rejectCookies")?.addEventListener("click", () => save(false, false));
    MCW.qs("#configCookies")?.addEventListener("click", () => modal.classList.add("is-visible"));
    MCW.qs("#openCookieSettings")?.addEventListener("click", () => modal.classList.add("is-visible"));

    MCW.qs("#saveCookieSettings")?.addEventListener("click", () => {
      const analytics = MCW.qs("#analyticsCookies")?.checked || false;
      const marketing = MCW.qs("#marketingCookies")?.checked || false;
      save(analytics, marketing);
      modal.classList.remove("is-visible");
    });

  }

  function save(analytics, marketing) {

    const data = {
      analytics,
      marketing,
      version: VERSION,
      date: new Date().toISOString()
    };

    MCW.storage.set(CONSENT_KEY, data);
    applyConsent(data);
    MCW.qs("#cookieBanner")?.classList.remove("is-visible");
  }

  function applyConsent(data) {

    if (data.analytics) loadGA();
    if (data.marketing) loadMeta();

  }

  function loadGA() {
    if (window.__gaLoaded) return;
    window.__gaLoaded = true;
    console.log("Google Analytics activado");
  }

  function loadMeta() {
    if (window.__metaLoaded) return;
    window.__metaLoaded = true;
    console.log("Meta Pixel activado");
  }

  return { init };

})();


/* =====================================================
   05. ACCESIBILIDAD GLOBAL
===================================================== */

const Accessibility = (() => {

  function init() {

    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape") {
        MCW.qs("#cookieModal")?.classList.remove("is-visible");
      }
    });

  }

  return { init };

})();


/* =====================================================
   06. INICIALIZACIÓN GLOBAL
===================================================== */

document.addEventListener("DOMContentLoaded", () => {

  Navigation.init();
  BudgetSystem.init();
  CookieSystem.init();
  Accessibility.init();

});







/* =========================
   WHATSAPP WIDGET — Burbuja flotante
   Gestiona: apertura automática · cierre con × · toggle con el botón ?
   sessionStorage: recuerda que el usuario cerró la burbuja
   hasta que cierre el navegador (no molesta en cada recarga)
========================== */
(function () {

  const bubble = document.getElementById('wa-bubble');
  const closeBtn = document.getElementById('wa-bubble-close');
  const helpBtn = document.getElementById('wa-help');
  const CLOSED_KEY = 'wa_bubble_closed'; /* clave en sessionStorage */

  /* Muestra la burbuja con la clase CSS que activa la animación */
  function openBubble() {
    bubble.classList.add('wa-visible');
  }

  /* Oculta la burbuja
     remember = true → guarda en sessionStorage (no vuelve a aparecer
     hasta que el usuario cierre el navegador) */
  function closeBubble(remember) {
    bubble.classList.remove('wa-visible');
    if (remember) sessionStorage.setItem(CLOSED_KEY, '1');
  }

  /* Apertura automática a los 3 s — solo si el usuario
     no la cerró anteriormente en esta sesión */
  if (!sessionStorage.getItem(CLOSED_KEY)) {
    setTimeout(openBubble, 3000);
  }

  /* Botón × — cierra y recuerda la decisión */
  closeBtn.addEventListener('click', function () {
    closeBubble(true);
  });

  /* Botón ? — alterna la visibilidad de la burbuja */
  helpBtn.addEventListener('click', function () {
    if (bubble.classList.contains('wa-visible')) {
      closeBubble(false);   /* cierra sin guardar en sessionStorage */
    } else {
      openBubble();
    }
  });

})();







