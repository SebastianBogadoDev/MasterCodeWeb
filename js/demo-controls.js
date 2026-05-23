/* =====================================================
   DEMO CONTROLS — Palette switcher · Viewport preview
   MasterCodeWeb · js/demo-controls.js
===================================================== */

export function initDemoControls({ demoId, palettes, presupuestoUrl = '/pages/presupuesto.html' }) {
  const panel = document.getElementById('demo-controls-panel');
  if (!panel) return;

  buildPanel(panel, demoId, palettes, presupuestoUrl);

  // Viewport simulation wrapper (wraps everything except .demo-banner)
  const wrap = buildPreviewWrap();

  const savedPaletteId = localStorage.getItem(`mcw-dp-${demoId}`);
  const initPalette = palettes.find(p => p.id === savedPaletteId) || palettes[0];
  setPalette(panel, demoId, initPalette, presupuestoUrl, 'desktop');

  const savedVp = localStorage.getItem(`mcw-dvp-${demoId}`) || 'desktop';
  setViewport(panel, wrap, savedVp, demoId, initPalette, presupuestoUrl);
}

/* ─── Preview Wrap ─────────────────────────────── */

function buildPreviewWrap() {
  let wrap = document.getElementById('demoPreviewWrap');
  if (wrap) return wrap;

  wrap = document.createElement('div');
  wrap.id = 'demoPreviewWrap';
  wrap.dataset.vp = 'desktop';

  const toWrap = [...document.body.children].filter(
    el => !el.classList.contains('demo-banner')
  );
  if (!toWrap.length) return wrap;

  document.body.insertBefore(wrap, toWrap[0]);
  toWrap.forEach(el => wrap.appendChild(el));
  return wrap;
}

/* ─── Panel ────────────────────────────────────── */

function buildPanel(panel, demoId, palettes, presupuestoUrl) {
  panel.className = 'demo-controls-panel';

  panel.innerHTML = `
<div class="dcp__inner">

  <div class="dcp__header">
    <span class="dcp__eyebrow">Personaliza esta propuesta</span>
    <p class="dcp__sub">Cambia la paleta de colores o simula cómo se vería en distintos dispositivos.</p>
  </div>

  <div class="dcp__row">

    <div class="dcp__group">
      <span class="dcp__label" id="dcp-pal-lbl-${demoId}">Paleta de colores</span>
      <div class="dcp__palettes" role="radiogroup" aria-labelledby="dcp-pal-lbl-${demoId}">
        ${palettes.map((p, i) => `
          <button class="dcp__pal${i === 0 ? ' dcp__pal--active' : ''}"
                  data-palette-id="${p.id}" type="button"
                  aria-pressed="${i === 0}" aria-label="Paleta ${p.name}">
            <span class="dcp__swatches" aria-hidden="true">
              ${p.swatches.map(c => `<span class="dcp__swatch" style="background:${c}"></span>`).join('')}
            </span>
            <span class="dcp__pal-name">${p.name}</span>
            <span class="dcp__pal-hex" aria-hidden="true">${p.swatches[0]}</span>
          </button>`).join('')}
      </div>
    </div>

    <div class="dcp__sep" aria-hidden="true"></div>

    <div class="dcp__group">
      <span class="dcp__label" id="dcp-vp-lbl-${demoId}">Vista previa</span>
      <div class="dcp__viewports" role="radiogroup" aria-labelledby="dcp-vp-lbl-${demoId}">
        <button class="dcp__vp" data-vp="mobile" type="button"
                aria-pressed="false" aria-label="Simular vista móvil — 390 px">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="2" width="14" height="20" rx="2"/><circle cx="12" cy="18" r="0.6" fill="currentColor"/></svg>
          <span class="dcp__vp-name">Móvil</span>
          <span class="dcp__vp-size">390px</span>
        </button>
        <button class="dcp__vp" data-vp="tablet" type="button"
                aria-pressed="false" aria-label="Simular vista tablet — 768 px">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="2" width="16" height="20" rx="2"/><circle cx="12" cy="18" r="0.6" fill="currentColor"/></svg>
          <span class="dcp__vp-name">Tablet</span>
          <span class="dcp__vp-size">768px</span>
        </button>
        <button class="dcp__vp dcp__vp--active" data-vp="desktop" type="button"
                aria-pressed="true" aria-label="Vista escritorio — ancho completo">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="3" width="20" height="14" rx="2"/><polyline points="8 21 12 17 16 21"/></svg>
          <span class="dcp__vp-name">Escritorio</span>
          <span class="dcp__vp-size">1200px</span>
        </button>
      </div>
    </div>

    <a id="dcp-cta-${demoId}" class="dcp__cta"
       href="${buildCtaUrl(presupuestoUrl, demoId, palettes[0], 'desktop')}"
       aria-label="Quiero esta estética para mi web — ir al formulario de presupuesto">
      Quiero esta estética
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><polyline points="12 5 19 12 12 19"/></svg>
    </a>

  </div>
</div>`;

  panel.querySelectorAll('.dcp__pal').forEach(btn => {
    btn.addEventListener('click', () => {
      const palette = palettes.find(p => p.id === btn.dataset.paletteId);
      if (!palette) return;
      const vp = document.body.dataset.demoVp || 'desktop';
      setPalette(panel, demoId, palette, presupuestoUrl, vp);
      localStorage.setItem(`mcw-dp-${demoId}`, palette.id);
    });
  });

  panel.querySelectorAll('.dcp__vp').forEach(btn => {
    btn.addEventListener('click', () => {
      const wrap = document.getElementById('demoPreviewWrap');
      const activeBtn = panel.querySelector('.dcp__pal--active');
      const palette = palettes.find(p => p.id === activeBtn?.dataset.paletteId) || palettes[0];
      setViewport(panel, wrap, btn.dataset.vp, demoId, palette, presupuestoUrl);
      localStorage.setItem(`mcw-dvp-${demoId}`, btn.dataset.vp);
    });
  });
}

/* ─── Palette ──────────────────────────────────── */

function setPalette(panel, demoId, palette, presupuestoUrl, vp) {
  const root = document.documentElement;
  Object.entries(palette.vars).forEach(([prop, val]) => root.style.setProperty(prop, val));

  panel.querySelectorAll('.dcp__pal').forEach(btn => {
    const active = btn.dataset.paletteId === palette.id;
    btn.classList.toggle('dcp__pal--active', active);
    btn.setAttribute('aria-pressed', String(active));
  });

  const cta = document.getElementById(`dcp-cta-${demoId}`);
  if (cta) cta.href = buildCtaUrl(presupuestoUrl, demoId, palette, vp);
}

/* ─── Viewport ─────────────────────────────────── */

function setViewport(panel, wrap, vp, demoId, palette, presupuestoUrl) {
  if (!wrap) return;
  wrap.dataset.vp = vp;
  document.body.dataset.demoVp = vp;

  panel.querySelectorAll('.dcp__vp').forEach(btn => {
    const active = btn.dataset.vp === vp;
    btn.classList.toggle('dcp__vp--active', active);
    btn.setAttribute('aria-pressed', String(active));
  });

  const cta = document.getElementById(`dcp-cta-${demoId}`);
  if (cta) cta.href = buildCtaUrl(presupuestoUrl, demoId, palette, vp);
}

/* ─── CTA URL ──────────────────────────────────── */

function buildCtaUrl(presupuestoUrl, demoId, palette, vp = 'desktop') {
  const params = new URLSearchParams({
    demo:    demoId,
    paleta:  palette.id,
    colores: palette.swatches.join(','),
    vista:   vp,
  });
  return `${presupuestoUrl}?${params}`;
}
