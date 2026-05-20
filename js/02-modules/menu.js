/* =====================================================
   MENU MODULE
   El overlay (#mobileOverlay) vive fuera del <header>
   para evitar ser atrapado por su stacking context.
===================================================== */

export function initMenu() {

  const toggle  = document.getElementById('menuToggle');
  const overlay = document.getElementById('mobileOverlay');
  const closeBtn = document.getElementById('mobileClose');

  if (!toggle || !overlay) return;

  /* Ensure aria-modal is set (enhances screen-reader UX) */
  overlay.setAttribute('aria-modal', 'true');

  /* ── Focusable elements inside overlay ─────────── */
  function getFocusable() {
    return [...overlay.querySelectorAll(
      'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])'
    )];
  }

  /* ── Open ───────────────────────────────────────── */
  function open() {
    overlay.classList.add('is-open');
    overlay.setAttribute('aria-hidden', 'false');
    toggle.classList.add('is-open');
    toggle.setAttribute('aria-expanded', 'true');
    toggle.setAttribute('aria-label', 'Cerrar menú');
    document.body.classList.add('mob-open');

    /* Move focus to first nav link after CSS transition starts */
    requestAnimationFrame(() => {
      const first = getFocusable()[0];
      if (first) first.focus();
    });
  }

  /* ── Close ──────────────────────────────────────── */
  function close() {
    overlay.classList.remove('is-open');
    overlay.setAttribute('aria-hidden', 'true');
    toggle.classList.remove('is-open');
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-label', 'Abrir menú');
    document.body.classList.remove('mob-open');

    /* Return focus to the toggle that opened the menu */
    toggle.focus();
  }

  /* ── Keyboard: Escape + focus trap ─────────────── */
  document.addEventListener('keydown', (e) => {
    if (!overlay.classList.contains('is-open')) return;

    if (e.key === 'Escape') {
      close();
      return;
    }

    if (e.key === 'Tab') {
      const focusable = getFocusable();
      if (!focusable.length) return;
      const first = focusable[0];
      const last  = focusable[focusable.length - 1];

      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    }
  });

  /* ── Toggle click ───────────────────────────────── */
  toggle.addEventListener('click', () =>
    overlay.classList.contains('is-open') ? close() : open()
  );

  /* ── Close button ───────────────────────────────── */
  closeBtn?.addEventListener('click', close);

  /* ── Nav link clicks auto-close ─────────────────── */
  overlay.querySelectorAll('a').forEach((link) =>
    link.addEventListener('click', close)
  );

  /* ── Auto-close when viewport widens to desktop ── */
  let resizeTimer;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
      if (window.innerWidth > 768) close();
    }, 100);
  });

}
