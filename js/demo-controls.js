/* =====================================================
   DEMO CONTROLS — Compact carousel configurator
   MasterCodeWeb · js/demo-controls.js
===================================================== */

export function initDemoControls({ demoId, palettes, presupuestoUrl = '/pages/presupuesto.html' }) {
  const panel = document.getElementById('demo-controls-panel');
  if (!panel) return;

  const urlPaletteId = new URLSearchParams(window.location.search).get('palette');
  const savedId = urlPaletteId || localStorage.getItem(`mcw-dp-${demoId}`);
  let currentIdx = Math.max(0, palettes.findIndex(p => p.id === savedId));

  buildPanel(panel, demoId, palettes, presupuestoUrl, currentIdx);

  const wrap = buildPreviewWrap();
  const savedVp = localStorage.getItem(`mcw-dvp-${demoId}`) || 'desktop';

  applyPalette(demoId, palettes[currentIdx], presupuestoUrl, savedVp, false);
  setViewport(panel, wrap, savedVp, demoId, palettes[currentIdx], presupuestoUrl);
  updateCarouselDisplay(panel, demoId, palettes[currentIdx], currentIdx, palettes.length, presupuestoUrl, savedVp);

  /* ── Carousel navigation ── */
  panel.querySelector('.dcp__nav--prev')?.addEventListener('click', () => {
    currentIdx = (currentIdx - 1 + palettes.length) % palettes.length;
    navigate(panel, demoId, palettes, currentIdx, presupuestoUrl);
  });

  panel.querySelector('.dcp__nav--next')?.addEventListener('click', () => {
    currentIdx = (currentIdx + 1) % palettes.length;
    navigate(panel, demoId, palettes, currentIdx, presupuestoUrl);
  });

  /* ── Viewport tabs ── */
  panel.querySelectorAll('.dcp__vp-tab').forEach(btn => {
    btn.addEventListener('click', () => {
      const vp = btn.dataset.vp;
      setViewport(panel, wrap, vp, demoId, palettes[currentIdx], presupuestoUrl);
      localStorage.setItem(`mcw-dvp-${demoId}`, vp);
      trackEvent('cambio_viewport', { demo_id: demoId, vista: vp });
    });
  });

  /* ── CTA tracking ── */
  document.getElementById(`dcp-cta-${demoId}`)?.addEventListener('click', () => {
    const pal = palettes[currentIdx];
    trackEvent('click_demo_cta', {
      demo_id: demoId, paleta_id: pal.id, paleta_name: pal.name,
      vista: document.body.dataset.demoVp || 'desktop',
    });
  });

  document.getElementById(`dcp-wa-${demoId}`)?.addEventListener('click', () => {
    trackEvent('click_whatsapp', { source: 'demo_panel', demo_id: demoId, paleta_id: palettes[currentIdx].id });
  });
}

/* ─── Navigate carousel ─────────────────────────── */

function navigate(panel, demoId, palettes, idx, presupuestoUrl) {
  const pal = palettes[idx];
  const vp = document.body.dataset.demoVp || 'desktop';
  applyPalette(demoId, pal, presupuestoUrl, vp, true);
  updateCarouselDisplay(panel, demoId, pal, idx, palettes.length, presupuestoUrl, vp);
  localStorage.setItem(`mcw-dp-${demoId}`, pal.id);
}

/* ─── GA4 tracking helper ───────────────────────── */

function trackEvent(name, params = {}) {
  if (typeof window.gtag === 'function') window.gtag('event', name, params);
}

/* ─── Preview Wrap ─────────────────────────────── */

function buildPreviewWrap() {
  let wrap = document.getElementById('demoPreviewWrap');
  if (wrap) return wrap;

  wrap = document.createElement('div');
  wrap.id = 'demoPreviewWrap';
  wrap.dataset.vp = 'desktop';

  const toWrap = [...document.body.children].filter(
    el => !el.classList.contains('demo-banner') && el.id !== 'demo-controls-panel'
  );
  if (!toWrap.length) return wrap;

  document.body.insertBefore(wrap, toWrap[0]);
  toWrap.forEach(el => wrap.appendChild(el));
  return wrap;
}

/* ─── Panel ────────────────────────────────────── */

function buildPanel(panel, demoId, palettes, presupuestoUrl, initIdx) {
  const pal = palettes[initIdx];
  panel.innerHTML = `
<div class="dcp__card">

  <header class="dcp__head">
    <span class="dcp__badge">Configurador visual</span>
    <h2 class="dcp__title">Personaliza esta propuesta</h2>
  </header>

  <div class="dcp__controls">
    <div class="dcp__block">
      <h3 class="dcp__block-title">Paleta de colores</h3>
      <div class="dcp__carousel" role="group" aria-label="Selector de paleta de colores">
        <button class="dcp__nav dcp__nav--prev" type="button" aria-label="Paleta anterior">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
        </button>
        <div class="dcp__carousel-center">
          <div class="dcp__carousel-card">
            <span class="dcp__carousel-strip" aria-hidden="true">${
              pal.swatches.map(c => `<i class="dcp__strip-seg" style="background:${c}"></i>`).join('')
            }</span>
            <div class="dcp__carousel-body">
              <span class="dcp__carousel-dots" aria-hidden="true">${
                pal.swatches.map(c => `<i class="dcp__dot" style="background:${c}"></i>`).join('')
              }</span>
              <span class="dcp__carousel-name">${pal.name}</span>
              <span class="dcp__pal-counter" aria-hidden="true">${initIdx + 1} / ${palettes.length}</span>
            </div>
          </div>
        </div>
        <button class="dcp__nav dcp__nav--next" type="button" aria-label="Paleta siguiente">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
      </div>
      <span id="dcp-live-${demoId}" class="sr-only" aria-live="polite" aria-atomic="true"></span>
    </div>

    <div class="dcp__block">
      <h3 class="dcp__block-title">Vista</h3>
      <div class="dcp__vp-seg" role="radiogroup" aria-label="Vista responsive">
        <button class="dcp__vp-tab" data-vp="mobile" type="button" aria-pressed="false" aria-label="Vista móvil">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="2" width="14" height="20" rx="2.5"/><line x1="12" y1="18" x2="12.01" y2="18" stroke-width="2.5"/></svg>
          Móvil
        </button>
        <button class="dcp__vp-tab" data-vp="tablet" type="button" aria-pressed="false" aria-label="Vista tablet">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="2" width="16" height="20" rx="2.5"/><line x1="12" y1="18" x2="12.01" y2="18" stroke-width="2.5"/></svg>
          Tablet
        </button>
        <button class="dcp__vp-tab dcp__vp-tab--active" data-vp="desktop" type="button" aria-pressed="true" aria-label="Vista escritorio">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
          Escritorio
        </button>
      </div>
    </div>
  </div>

  <div class="dcp__hr" role="separator" aria-hidden="true"></div>

  <div class="dcp__foot">
    <div class="dcp__foot-ctas">
      <a id="dcp-cta-${demoId}" class="dcp__cta"
         href="${buildCtaUrl(presupuestoUrl, demoId, pal, 'desktop')}"
         aria-label="Quiero esta estética — ir al formulario de presupuesto">
        Quiero esta estética
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><polyline points="12 5 19 12 12 19"/></svg>
      </a>
      <a id="dcp-wa-${demoId}" class="dcp__wa"
         href="${buildWaUrl(demoId, pal)}"
         target="_blank" rel="noopener noreferrer"
         aria-label="Hablar por WhatsApp sobre esta demo">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.089.539 4.049 1.481 5.757L0 24l6.418-1.451A11.934 11.934 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.6a9.6 9.6 0 0 1-4.992-1.399l-.357-.213-3.706.84.877-3.603-.234-.37A9.6 9.6 0 1 1 12 21.6z"/></svg>
        WhatsApp
      </a>
    </div>
  </div>

</div>`;
}

/* ─── Update carousel display (no re-render) ────── */

function updateCarouselDisplay(panel, demoId, palette, idx, total, presupuestoUrl, vp) {
  const strip = panel.querySelector('.dcp__carousel-strip');
  if (strip) strip.innerHTML = palette.swatches.map(c => `<i class="dcp__strip-seg" style="background:${c}"></i>`).join('');

  const dots = panel.querySelector('.dcp__carousel-dots');
  if (dots) dots.innerHTML = palette.swatches.map(c => `<i class="dcp__dot" style="background:${c}"></i>`).join('');

  const nameEl = panel.querySelector('.dcp__carousel-name');
  if (nameEl) nameEl.textContent = palette.name;

  const counter = panel.querySelector('.dcp__pal-counter');
  if (counter) counter.textContent = `${idx + 1} / ${total}`;

  const live = document.getElementById(`dcp-live-${demoId}`);
  if (live) live.textContent = `Paleta ${palette.name}, ${idx + 1} de ${total}`;

  const cta = document.getElementById(`dcp-cta-${demoId}`);
  if (cta) cta.href = buildCtaUrl(presupuestoUrl, demoId, palette, vp);

  const wa = document.getElementById(`dcp-wa-${demoId}`);
  if (wa) wa.href = buildWaUrl(demoId, palette);
}

/* ─── Palette ──────────────────────────────────── */

function applyPalette(demoId, palette, presupuestoUrl, vp, animate) {
  const root = document.documentElement;

  if (animate) {
    root.classList.add('dcp-changing');
    setTimeout(() => root.classList.remove('dcp-changing'), 320);
    trackEvent('cambio_paleta', { demo_id: demoId, paleta_id: palette.id, paleta_name: palette.name });
    try {
      const u = new URL(window.location.href);
      u.searchParams.set('palette', palette.id);
      window.history.replaceState(null, '', u);
    } catch (_) {}
  }

  Object.entries(palette.vars).forEach(([k, v]) => root.style.setProperty(k, v));
}

/* ─── Viewport ─────────────────────────────────── */

function setViewport(panel, wrap, vp, demoId, palette, presupuestoUrl) {
  if (!wrap) return;
  wrap.dataset.vp = vp;
  document.body.dataset.demoVp = vp;

  panel.querySelectorAll('.dcp__vp-tab').forEach(btn => {
    const on = btn.dataset.vp === vp;
    btn.classList.toggle('dcp__vp-tab--active', on);
    btn.setAttribute('aria-pressed', String(on));
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

/* ─── WhatsApp URL ─────────────────────────────── */

function buildWaUrl(demoId, palette) {
  const msg = `Hola, me gusta el estilo de la demo "${demoId}" con la paleta "${palette.name}". Me gustaría hablar sobre un proyecto web con esa estética.`;
  return `https://wa.me/34680762047?text=${encodeURIComponent(msg)}`;
}
