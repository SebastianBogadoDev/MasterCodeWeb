/* =====================================================
   DEMO CONTROLS — Palette switcher · Viewport preview
   MasterCodeWeb · js/demo-controls.js
===================================================== */

export function initDemoControls({ demoId, palettes, presupuestoUrl = '/pages/presupuesto.html' }) {
  const bar = buildControlsBar(demoId, palettes, presupuestoUrl);

  // Wrap .demo-banner + controls bar in one sticky container at the top
  const topBar = document.createElement('div');
  topBar.id = 'demoTopBar';

  const banner = document.querySelector('.demo-banner');
  if (banner) {
    document.body.insertBefore(topBar, banner);
    topBar.appendChild(banner);
  } else {
    document.body.insertBefore(topBar, document.body.firstChild);
  }
  topBar.appendChild(bar);

  // Measure combined height so each demo's own sticky header can offset below it
  const syncHeight = () =>
    document.documentElement.style.setProperty('--demo-top-h', topBar.offsetHeight + 'px');

  requestAnimationFrame(() => {
    syncHeight();
    document.body.classList.add('demo-has-controls');
    new ResizeObserver(syncHeight).observe(topBar);
  });

  // Wrap everything else inside #demoPreviewWrap
  const wrap = buildPreviewWrap();

  const savedPaletteId = localStorage.getItem(`mcw-dp-${demoId}`);
  const initPalette = palettes.find(p => p.id === savedPaletteId) || palettes[0];
  setPalette(bar, demoId, initPalette, presupuestoUrl);

  const savedVp = localStorage.getItem(`mcw-dvp-${demoId}`) || 'desktop';
  setViewport(bar, wrap, savedVp);
}

/* ─── Preview Wrap ─────────────────────────────── */

function buildPreviewWrap() {
  let wrap = document.getElementById('demoPreviewWrap');
  if (wrap) return wrap;

  wrap = document.createElement('div');
  wrap.id = 'demoPreviewWrap';
  wrap.dataset.vp = 'desktop';

  // Wrap every body child except #demoTopBar
  const toWrap = [...document.body.children].filter(el => el.id !== 'demoTopBar');
  if (!toWrap.length) return wrap;

  document.body.insertBefore(wrap, toWrap[0]);
  toWrap.forEach(el => wrap.appendChild(el));
  return wrap;
}

/* ─── Controls Bar ─────────────────────────────── */

function buildControlsBar(demoId, palettes, presupuestoUrl) {
  const bar = document.createElement('div');
  bar.className = 'demo-controls-bar';
  bar.setAttribute('role', 'region');
  bar.setAttribute('aria-label', 'Controles interactivos de la demo');

  bar.innerHTML = `
<div class="dcb__inner">

  <div class="dcb__section">
    <span class="dcb__label" id="dcb-pal-lbl-${demoId}">Paleta</span>
    <div class="dcb__palettes" role="radiogroup" aria-labelledby="dcb-pal-lbl-${demoId}">
      ${palettes.map((p, i) => `
        <button class="dcb__pal${i === 0 ? ' dcb__pal--active' : ''}"
                data-palette-id="${p.id}"
                type="button"
                aria-pressed="${i === 0}"
                aria-label="Paleta ${p.name}"
                title="${p.name}">
          <span class="dcb__swatches" aria-hidden="true">
            ${p.swatches.map(c => `<span class="dcb__swatch" style="background:${c}"></span>`).join('')}
          </span>
          <span class="dcb__pal-name">${p.name}</span>
        </button>`).join('')}
    </div>
  </div>

  <div class="dcb__divider" aria-hidden="true"></div>

  <div class="dcb__section">
    <span class="dcb__label" id="dcb-vp-lbl-${demoId}">Vista</span>
    <div class="dcb__viewports" role="radiogroup" aria-labelledby="dcb-vp-lbl-${demoId}">
      <button class="dcb__vp" data-vp="mobile" type="button"
              aria-pressed="false" aria-label="Vista móvil — 390 px">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="2" width="14" height="20" rx="2"/><circle cx="12" cy="18" r="0.5" fill="currentColor"/></svg>
        <span>Móvil</span>
      </button>
      <button class="dcb__vp" data-vp="tablet" type="button"
              aria-pressed="false" aria-label="Vista tablet — 768 px">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="2" width="16" height="20" rx="2"/><circle cx="12" cy="18" r="0.5" fill="currentColor"/></svg>
        <span>Tablet</span>
      </button>
      <button class="dcb__vp dcb__vp--active" data-vp="desktop" type="button"
              aria-pressed="true" aria-label="Vista escritorio — ancho completo">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="3" width="20" height="14" rx="2"/><polyline points="8 21 12 17 16 21"/></svg>
        <span>Escritorio</span>
      </button>
    </div>
  </div>

  <a id="dcb-cta-${demoId}"
     class="dcb__cta"
     href="${buildCtaUrl(presupuestoUrl, demoId, palettes[0])}"
     aria-label="Quiero esta estética para mi web — ir al formulario de presupuesto">
    <span class="dcb__cta-text">Quiero esta estética</span>
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><polyline points="12 5 19 12 12 19"/></svg>
  </a>

</div>`;

  bar.querySelectorAll('.dcb__pal').forEach(btn => {
    btn.addEventListener('click', () => {
      const palette = palettes.find(p => p.id === btn.dataset.paletteId);
      if (!palette) return;
      setPalette(bar, demoId, palette, presupuestoUrl);
      localStorage.setItem(`mcw-dp-${demoId}`, palette.id);
    });
  });

  bar.querySelectorAll('.dcb__vp').forEach(btn => {
    btn.addEventListener('click', () => {
      const wrap = document.getElementById('demoPreviewWrap');
      setViewport(bar, wrap, btn.dataset.vp);
      localStorage.setItem(`mcw-dvp-${demoId}`, btn.dataset.vp);
    });
  });

  return bar;
}

/* ─── Palette ──────────────────────────────────── */

function setPalette(bar, demoId, palette, presupuestoUrl) {
  const root = document.documentElement;
  Object.entries(palette.vars).forEach(([prop, val]) => root.style.setProperty(prop, val));

  bar.querySelectorAll('.dcb__pal').forEach(btn => {
    const active = btn.dataset.paletteId === palette.id;
    btn.classList.toggle('dcb__pal--active', active);
    btn.setAttribute('aria-pressed', String(active));
  });

  const cta = document.getElementById(`dcb-cta-${demoId}`);
  if (cta) cta.href = buildCtaUrl(presupuestoUrl, demoId, palette);
}

/* ─── Viewport ─────────────────────────────────── */

function setViewport(bar, wrap, vp) {
  if (!wrap) return;
  wrap.dataset.vp = vp;
  document.body.dataset.demoVp = vp;

  bar.querySelectorAll('.dcb__vp').forEach(btn => {
    const active = btn.dataset.vp === vp;
    btn.classList.toggle('dcb__vp--active', active);
    btn.setAttribute('aria-pressed', String(active));
  });
}

/* ─── CTA URL ──────────────────────────────────── */

function buildCtaUrl(presupuestoUrl, demoId, palette) {
  const params = new URLSearchParams({
    demo:    demoId,
    paleta:  palette.id,
    colores: palette.swatches.join(','),
  });
  return `${presupuestoUrl}?${params}`;
}
