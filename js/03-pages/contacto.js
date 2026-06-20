/* =====================================================
   CONTACTO PAGE
===================================================== */

export function initContacto() {
  const form = document.getElementById('contactForm');
  if (!form) return;

  // Lazy-load Turnstile on first user interaction with the form.
  // The token check in forms.js handles the case where the user submits
  // before Turnstile renders (shows error + scrolls to widget).
  let turnstileLoaded = false;
  const loadTurnstile = () => {
    if (turnstileLoaded) return;
    turnstileLoaded = true;
    const s = document.createElement('script');
    s.src   = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
    s.async = true;
    s.defer = true;
    document.body.appendChild(s);
  };
  form.addEventListener('focusin',   loadTurnstile, { once: true });
  form.addEventListener('touchstart', loadTurnstile, { once: true, passive: true });
}
