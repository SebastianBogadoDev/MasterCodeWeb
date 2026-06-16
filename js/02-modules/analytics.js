/* =====================================================
   ANALYTICS MODULE — GA4 EVENT TRACKING
===================================================== */

// ── Microsoft Clarity ─────────────────────────────
// 1. Ve a clarity.microsoft.com → New project → copia tu Project ID
// 2. Reemplaza 'PENDIENTE' por ese ID (ej: 'abc123xyz')
const CLARITY_PROJECT_ID = 'PENDIENTE';

// ── Landing attribution ───────────────────────────
const LANDING_STORE_KEY = 'mcw_landing';

const LANDING_MAP = [
  { pattern: /diseno-web-benalmadena/,   ciudad: 'benalmadena',  servicio: 'diseno-web'        },
  { pattern: /diseno-web-nerja/,         ciudad: 'nerja',        servicio: 'diseno-web'        },
  { pattern: /diseno-web-fuengirola/,    ciudad: 'fuengirola',   servicio: 'diseno-web'        },
  { pattern: /diseno-web-torremolinos/,  ciudad: 'torremolinos', servicio: 'diseno-web'        },
  { pattern: /diseno-web-velez-malaga/,  ciudad: 'velez-malaga', servicio: 'diseno-web'        },
  { pattern: /diseno-web-marbella/,      ciudad: 'marbella',     servicio: 'diseno-web'        },
  { pattern: /diseno-web-malaga/,        ciudad: 'malaga',       servicio: 'diseno-web'        },
  { pattern: /desarrollo-web-malaga/,    ciudad: 'malaga',       servicio: 'desarrollo-web'    },
  { pattern: /tienda-online-malaga/,     ciudad: 'malaga',       servicio: 'tienda-online'     },
  { pattern: /mantenimiento-web-malaga/, ciudad: 'malaga',       servicio: 'mantenimiento-web' },
  { pattern: /seo-malaga/,               ciudad: 'malaga',       servicio: 'seo'               },
];

// href fragment → plan / tipo
const CHECKOUT_PLAN_MAP = {
  'checkout-basico':                { plan: 'basico',       tipo: 'unico'   },
  'checkout-pro':                   { plan: 'profesional',  tipo: 'unico'   },
  'checkout-premium':               { plan: 'premium',      tipo: 'unico'   },
  'checkout-mantenimiento-basico':  { plan: 'mant-basico',  tipo: 'mensual' },
  'checkout-mantenimiento-pro':     { plan: 'mant-pro',     tipo: 'mensual' },
  'checkout-mantenimiento-premium': { plan: 'mant-premium', tipo: 'mensual' },
};

/* ========================
   CONSENT CHECK
======================== */

function hasAnalyticsConsent() {
  try {
    const c = JSON.parse(localStorage.getItem('cookie-consent') || 'null');
    return c?.analytics === true;
  } catch {
    return false;
  }
}

/* ========================
   TRACK HELPER
======================== */

function track(name, params = {}) {
  if (typeof gtag !== 'function') return;
  if (!hasAnalyticsConsent()) return;
  gtag('event', name, params);
}

/* ========================
   LANDING ATTRIBUTION
======================== */

function storeLandingAttribution() {
  const path  = location.pathname;
  const match = LANDING_MAP.find(m => m.pattern.test(path));
  if (!match) return;
  try {
    sessionStorage.setItem(LANDING_STORE_KEY, JSON.stringify({
      origen:   path,
      ciudad:   match.ciudad,
      servicio: match.servicio,
    }));
  } catch { /* storage unavailable */ }
}

export function getLandingAttribution() {
  try {
    return JSON.parse(sessionStorage.getItem(LANDING_STORE_KEY) || 'null') || {};
  } catch {
    return {};
  }
}

/* ========================
   MICROSOFT CLARITY
======================== */

let _clarityLoaded = false;

function initClarity() {
  if (_clarityLoaded) return;
  if (!CLARITY_PROJECT_ID || CLARITY_PROJECT_ID === 'PENDIENTE') return;
  if (!hasAnalyticsConsent()) return;

  _clarityLoaded = true;
  /* eslint-disable */
  (function(c,l,a,r,i,t,y){
    c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
    t=l.createElement(r);t.async=1;t.src='https://www.clarity.ms/tag/'+i;
    y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
  })(window,document,'clarity','script',CLARITY_PROJECT_ID);
  /* eslint-enable */
}

/* ========================
   INIT
======================== */

export function initAnalytics() {
  storeLandingAttribution();
  trackCTAClicks();
  trackPricingButtons();
  trackCheckoutLinks();
  trackWhatsAppClicks();
  trackPhoneClicks();
  trackScrollDepth();
  trackFAQInteractions();
  initClarity();

  // Init Clarity cuando el usuario acepta cookies por primera vez (misma sesión)
  window.addEventListener('mcw:consent-update', (e) => {
    if (e.detail?.analytics) initClarity();
  }, { passive: true });
}

/* ========================
   CTA CLICKS
======================== */

function trackCTAClicks() {
  const attr = getLandingAttribution();
  document.querySelectorAll('.btn--primary, .btn--cta').forEach(btn => {
    btn.addEventListener('click', () => {
      track('cta_click', {
        button_text:    btn.textContent.trim().slice(0, 50),
        section:        sectionName(btn),
        page_path:      location.pathname,
        landing_origen: attr.origen   || '',
        ciudad:         attr.ciudad   || '',
        servicio:       attr.servicio || '',
      });
    }, { passive: true });
  });
}

/* ========================
   PRICING BUTTONS (.mcw-pago-btn / .contratar-btn)
   Solo en precios.html y checkout-*.html
======================== */

function trackPricingButtons() {
  const attr = getLandingAttribution();

  document.querySelectorAll('.mcw-pago-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const plan = btn.dataset.nombre || btn.dataset.plan || 'unknown';
      track('begin_checkout', {
        currency:       'EUR',
        landing_origen: attr.origen   || '',
        ciudad:         attr.ciudad   || '',
        servicio:       attr.servicio || '',
        items: [{ item_name: plan, item_category: 'unico' }],
      });
    }, { passive: true });
  });

  document.querySelectorAll('.contratar-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const plan = btn.dataset.plan || 'unknown';
      track('begin_checkout', {
        currency:       'EUR',
        landing_origen: attr.origen   || '',
        ciudad:         attr.ciudad   || '',
        servicio:       attr.servicio || '',
        items: [{ item_name: plan, item_category: 'mantenimiento' }],
      });
    }, { passive: true });
  });
}

/* ========================
   CHECKOUT LINKS (landings de ciudad)
   <a href="/pages/checkout-basico.html"> → begin_checkout
======================== */

function trackCheckoutLinks() {
  const attr = getLandingAttribution();

  document.querySelectorAll('a[href*="/pages/checkout-"]').forEach(link => {
    link.addEventListener('click', () => {
      const href = link.getAttribute('href') || '';
      // extrae 'checkout-basico', 'checkout-mantenimiento-pro', etc.
      const fragment = href.match(/\/(checkout-[a-z-]+)\.html/)?.[1] || '';
      const meta     = CHECKOUT_PLAN_MAP[fragment] || { plan: fragment || 'unknown', tipo: 'unico' };

      track('begin_checkout', {
        currency:       'EUR',
        landing_origen: attr.origen   || location.pathname,
        ciudad:         attr.ciudad   || '',
        servicio:       attr.servicio || '',
        items: [{ item_name: meta.plan, item_category: meta.tipo }],
      });
    }, { passive: true });
  });
}

/* ========================
   WHATSAPP CLICKS
======================== */

function trackWhatsAppClicks() {
  const attr = getLandingAttribution();
  document.querySelectorAll('a[href*="wa.me"]').forEach(link => {
    link.addEventListener('click', () => {
      track('whatsapp_click', {
        section:        sectionName(link),
        page_path:      location.pathname,
        landing_origen: attr.origen   || '',
        ciudad:         attr.ciudad   || '',
        servicio:       attr.servicio || '',
      });
    }, { passive: true });
  });
}

/* ========================
   PHONE CLICKS
======================== */

function trackPhoneClicks() {
  const attr = getLandingAttribution();
  document.querySelectorAll('a[href^="tel:"]').forEach(link => {
    link.addEventListener('click', () => {
      track('phone_click', {
        phone_number:   link.href.replace('tel:', ''),
        section:        sectionName(link),
        page_path:      location.pathname,
        landing_origen: attr.origen   || '',
        ciudad:         attr.ciudad   || '',
        servicio:       attr.servicio || '',
      });
    }, { passive: true });
  });
}

/* ========================
   SCROLL DEPTH
======================== */

function trackScrollDepth() {
  const thresholds = [25, 50, 75, 90];
  const fired      = new Set();

  window.addEventListener('scroll', () => {
    const total = document.documentElement.scrollHeight - window.innerHeight;
    if (total <= 0) return;
    const pct = Math.round((window.scrollY / total) * 100);
    thresholds.forEach(t => {
      if (pct >= t && !fired.has(t)) {
        fired.add(t);
        track('scroll_depth', { percent: t, page_path: location.pathname });
      }
    });
  }, { passive: true });
}

/* ========================
   FAQ INTERACTIONS
======================== */

function trackFAQInteractions() {
  document.querySelectorAll('details.faq__item').forEach(el => {
    el.addEventListener('toggle', () => {
      if (el.open) {
        const q = el.querySelector('.faq__q')?.textContent.trim().slice(0, 80) ?? '';
        track('faq_open', { question: q, page_path: location.pathname });
      }
    });
  });

  document.querySelectorAll('.faq__trigger').forEach(btn => {
    btn.addEventListener('click', () => {
      track('faq_open', {
        question:  btn.textContent.trim().slice(0, 80),
        page_path: location.pathname,
      });
    }, { passive: true });
  });
}

/* ========================
   PURCHASE (stripe_success.html)
======================== */

export function trackPurchase({ transactionId, value, currency, plan, service, items }) {
  if (transactionId) {
    const key = 'purchase_fired_' + transactionId;
    if (sessionStorage.getItem(key)) return;
    sessionStorage.setItem(key, '1');
  }
  track('purchase', {
    transaction_id: transactionId || undefined,
    value,
    currency,
    plan:    plan    || undefined,
    service: service || undefined,
    items,
  });
}

/* ========================
   CHECKOUT ABANDONED (cancel.html)
======================== */

export function trackCheckoutAbandoned() {
  const p    = new URLSearchParams(location.search);
  const plan = p.get('plan') || 'unknown';
  const key  = 'mcw_cancel_fired_' + plan;
  if (sessionStorage.getItem(key)) return;
  sessionStorage.setItem(key, '1');

  const attr = getLandingAttribution();
  track('checkout_abandoned', {
    plan,
    page_path:      location.pathname,
    landing_origen: attr.origen   || '',
    ciudad:         attr.ciudad   || '',
    servicio:       attr.servicio || '',
  });
}

/* ========================
   HELPERS
======================== */

function sectionName(el) {
  const s = el.closest('section, header, footer, .hero, .checkout-main, nav');
  return s?.id || s?.className?.split(/\s+/)[0] || 'unknown';
}
