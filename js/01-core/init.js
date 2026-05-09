/* =====================================================
   INIT GLOBAL · MASTERCODEWEB (LEVEL ENTERPRISE)
===================================================== */

/* ========================
IMPORTS · MODULES
======================== */

import { initMenu } from "../02-modules/menu.js";
import { initBreadcrumbs } from "../02-modules/breadcrumbs.js";
import { initCookies } from "../02-modules/cookies.js";
import { initSearch } from "../02-modules/search.js";
import { initWhatsapp } from "../02-modules/whatsapp.js";
import { initForms } from "../02-modules/forms.js";
import { initPayments } from "../02-modules/payments.js";
import { initFaq } from "../02-modules/faq.js";

/* ========================
IMPORTS · PAGES
======================== */

import { initHome } from "../03-pages/home.js";
import { initServicios } from "../03-pages/servicios.js";
import { initPresupuesto } from "../03-pages/presupuesto.js";
import { initBlog } from "../03-pages/blog.js";
import { initContacto } from "../03-pages/contacto.js";
import { initLeads } from "../02-modules/leads.js";
import { initCheckout } from "../03-pages/checkout.js";
import { initScrollReveal } from "../02-modules/scroll-reveal.js";

/* ========================
APP INIT
======================== */

export function initApp() {

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
  safeInit(initLeads, "Leads");
  safeInit(initPayments, "Payments");
  safeInit(initScrollReveal, "ScrollReveal");
  safeInit(initFaq, "Faq");


}


/* =====================================================
   PAGE DETECTION (SCALABLE)
===================================================== */

function initPage() {

  const path = window.location.pathname.toLowerCase();

  const routes = [
    { match: ["index", "/"], fn: initHome, name: "Home" },
    { match: ["servicios"], fn: initServicios, name: "Servicios" },
    { match: ["presupuesto"], fn: initPresupuesto, name: "Presupuesto" },
    { match: ["blog", "guias"], fn: initBlog, name: "Blog" },
    { match: ["contacto"], fn: initContacto, name: "Contacto" },
    { match: ["checkout"], fn: initCheckout, name: "Checkout" }
  ];

  routes.forEach(route => {
    if (route.match.some(m => path.includes(m))) {
      safeInit(route.fn, route.name);
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

