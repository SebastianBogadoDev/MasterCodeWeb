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

  /* ── helpers ────────────────────────────────────── */
  function open() {
    overlay.classList.add('is-open');
    overlay.setAttribute('aria-hidden', 'false');
    toggle.classList.add('is-open');
    toggle.setAttribute('aria-expanded', 'true');
    toggle.setAttribute('aria-label', 'Cerrar menú');
    document.body.style.overflow = 'hidden';
  }

  function close() {
    overlay.classList.remove('is-open');
    overlay.setAttribute('aria-hidden', 'true');
    toggle.classList.remove('is-open');
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-label', 'Abrir menú');
    document.body.style.overflow = '';
  }

  /* ── events ─────────────────────────────────────── */
  toggle.addEventListener('click', () =>
    overlay.classList.contains('is-open') ? close() : open()
  );

  closeBtn?.addEventListener('click', close);

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') close();
  });

  overlay.querySelectorAll('a').forEach((link) =>
    link.addEventListener('click', close)
  );

  let resizeTimer;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
      if (window.innerWidth > 768) close();
    }, 100);
  });

}
