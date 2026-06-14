/* =====================================================
   ANALYTICS MODULE — GA4 EVENT TRACKING
===================================================== */

function hasAnalyticsConsent() {
  try {
    const c = JSON.parse(localStorage.getItem("cookie-consent") || "null");
    return c?.analytics === true;
  } catch {
    return false;
  }
}

function track(name, params = {}) {
  if (typeof gtag !== 'function') return;
  if (!hasAnalyticsConsent()) return;
  gtag('event', name, params);
}

export function initAnalytics() {
  trackCTAClicks();
  trackPricingButtons();
  trackWhatsAppClicks();
  trackPhoneClicks();
  trackScrollDepth();
  trackFAQInteractions();
}

function trackCTAClicks() {
  document.querySelectorAll('.btn--primary, .btn--cta').forEach(btn => {
    btn.addEventListener('click', () => {
      track('cta_click', {
        button_text: btn.textContent.trim().slice(0, 50),
        section: sectionName(btn),
        page_path: location.pathname,
      });
    }, { passive: true });
  });
}

function trackPricingButtons() {
  document.querySelectorAll('.mcw-pago-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const plan = btn.dataset.nombre || btn.dataset.plan || 'unknown';
      track('begin_checkout', {
        currency: 'EUR',
        items: [{ item_name: plan, item_category: 'unico' }],
      });
    }, { passive: true });
  });

  document.querySelectorAll('.contratar-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const plan = btn.dataset.plan || 'unknown';
      track('begin_checkout', {
        currency: 'EUR',
        items: [{ item_name: plan, item_category: 'mantenimiento' }],
      });
    }, { passive: true });
  });
}

function trackWhatsAppClicks() {
  document.querySelectorAll('a[href*="wa.me"]').forEach(link => {
    link.addEventListener('click', () => {
      track('whatsapp_click', {
        section: sectionName(link),
        page_path: location.pathname,
      });
    }, { passive: true });
  });
}

function trackScrollDepth() {
  const thresholds = [25, 50, 75, 90];
  const fired = new Set();

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

function trackFAQInteractions() {
  // native <details> FAQs
  document.querySelectorAll('details.faq__item').forEach(el => {
    el.addEventListener('toggle', () => {
      if (el.open) {
        const q = el.querySelector('.faq__q')?.textContent.trim().slice(0, 80) ?? '';
        track('faq_open', { question: q, page_path: location.pathname });
      }
    });
  });

  // JS-driven FAQ (precios.html)
  document.querySelectorAll('.faq__trigger').forEach(btn => {
    btn.addEventListener('click', () => {
      track('faq_open', {
        question: btn.textContent.trim().slice(0, 80),
        page_path: location.pathname,
      });
    }, { passive: true });
  });
}

function trackPhoneClicks() {
  document.querySelectorAll('a[href^="tel:"]').forEach(link => {
    link.addEventListener('click', () => {
      track('phone_click', {
        phone_number: link.href.replace('tel:', ''),
        section:      sectionName(link),
        page_path:    location.pathname,
      });
    }, { passive: true });
  });
}

function sectionName(el) {
  const s = el.closest('section, header, footer, .hero, .checkout-main, nav');
  return s?.id || s?.className?.split(/\s+/)[0] || 'unknown';
}
