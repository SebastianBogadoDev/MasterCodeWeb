/* =====================================================
   INIT GLOBAL · MASTERCODEWEB (LEVEL ENTERPRISE)
===================================================== */

/* ========================
IMPORTS · MODULES
======================== */

import { initMenu } from "../02-modules/menu.js";
import { initBreadcrumbs } from "../02-modules/breadcrumbs.js";
import { initCookies, restoreConsent } from "../02-modules/cookies.js";
import { initSearch } from "../02-modules/search.js";
import { initWhatsapp } from "../02-modules/whatsapp.js?v=20261011";
import { initForms } from "../02-modules/forms.js";
import { initPayments } from "../02-modules/payments.js";
import { initFaq } from "../02-modules/faq.js";
import { initAnalytics } from "../02-modules/analytics.js";

import { initScrollReveal } from "../02-modules/scroll-reveal.js";

/* ========================
IMPORTS · PAGES
   Cargados dinámicamente por ruta en initPage() — ver loadFeature().
======================== */

/* ========================
APP INIT
======================== */

export function initApp() {

  /* ========================
     CONSENT RESTORE (PRIMERO)
     Aplica gtag consent update antes de que
     analytics.js lance cualquier evento.
  ======================== */

  safeInit(restoreConsent, "ConsentRestore");

  /* ========================
     GLOBAL MODULES
  ======================== */

  initModules();

  /* ========================
     PAGE ROUTING
  ======================== */

  initPage();

}


/* =====================================================
   MODULES INIT
===================================================== */

function initModules() {

  safeInit(initMenu, "Menu");              
  safeInit(initBreadcrumbs, "Breadcrumbs");
  safeInit(initCookies, "Cookies");
  safeInit(initSearch, "Search");
  safeInit(initWhatsapp, "WhatsApp");
  safeInit(initForms, "Forms");
  safeInit(initPayments, "Payments");
  safeInit(initScrollReveal, "ScrollReveal");
  safeInit(initFaq, "Faq");
  safeInit(initAnalytics, "Analytics");


}


/* =====================================================
   PAGE DETECTION (SCALABLE)
===================================================== */

function initPage() {

  const path = window.location.pathname.toLowerCase();

  const isHome = path === "/" || path === "/index.html";

  const isServicios =
    path === "/pages/servicios.html" ||
    path.startsWith("/servicios/");

  const isPresupuesto = path === "/pages/presupuesto.html";

  const isBlog =
    path === "/pages/blog.html" ||
    path.startsWith("/blog/") ||
    path.startsWith("/guias/");

  const isContacto = path === "/pages/contacto.html";

  const isCheckout =
    path.startsWith("/pages/checkout-") &&
    path.endsWith(".html");

  const routes = [
    { active: isHome,        name: "Home",        load: () => import("../03-pages/home.js"),        fn: "initHome" },
    { active: isServicios,   name: "Servicios",   load: () => import("../03-pages/servicios.js"),   fn: "initServicios" },
    { active: isPresupuesto, name: "Presupuesto", load: () => import("../03-pages/presupuesto.js"), fn: "initPresupuesto" },
    { active: isBlog,        name: "Blog",        load: () => import("../03-pages/blog.js"),        fn: "initBlog" },
    { active: isContacto,    name: "Contacto",    load: () => import("../03-pages/contacto.js"),    fn: "initContacto" },
    { active: isCheckout,    name: "Checkout",    load: () => import("../03-pages/checkout.js"),    fn: "initCheckout" }
  ];

  routes.forEach(route => {
    if (route.active) loadFeature(route.load, route.fn, route.name);
  });

}


/* =====================================================
   DYNAMIC MODULE LOADER (PAGE-SPECIFIC)
   Aísla fallos de red/parseo de un módulo de página para que
   no afecten a los módulos globales ni a otras rutas.
===================================================== */

function loadFeature(importFn, exportName, label) {
  return importFn()
    .then(mod => {
      const fn = mod[exportName];
      if (typeof fn !== "function") {
        throw new Error(`export "${exportName}" no encontrado`);
      }
      safeInit(fn, label);
    })
    .catch(error => {
      if (typeof process === "undefined" || process.env?.NODE_ENV !== "production") {
        console.warn(`[MCW] No se pudo cargar el módulo de página "${label}":`, error);
      }
    });
}


/* =====================================================
   SAFE INIT (ANTI-ERRORS)
===================================================== */

function safeInit(fn, name) {
  try {
    fn();
  } catch (error) {
    // Silencioso en producción — evita romper otros módulos
    if (typeof process === "undefined" || process.env?.NODE_ENV !== "production") {
      console.warn(`[MCW] Error en ${name}:`, error);
    }
  }
}

