/* =====================================================
   DEMO CONTROLS — Premium visual configurator
   MasterCodeWeb · js/demo-controls.js
===================================================== */

export function initDemoControls({ demoId, palettes, presupuestoUrl = '/pages/presupuesto.html' }) {
  const panel = document.getElementById('demo-controls-panel');
  if (!panel) return;

  buildPanel(panel, demoId, palettes, presupuestoUrl);

  const wrap = buildPreviewWrap();

  const savedId = localStorage.getItem(`mcw-dp-${demoId}`);
  const initPal = palettes.find(p => p.id === savedId) || palettes[0];
  const savedVp = localStorage.getItem(`mcw-dvp-${demoId}`) || 'desktop';

  applyPalette(panel, demoId, initPal, presupuestoUrl, savedVp, false);
  setViewport(panel, wrap, savedVp, demoId, initPal, presupuestoUrl);
}

/* ─── GA4 tracking helper ───────────────────────── */

function trackEvent(name, params = {}) {
  if (typeof window.gtag === 'function') {
    window.gtag('event', name, params);
  }
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
  panel.innerHTML = `
<div class="dcp__card">

  <header class="dcp__head">
    <span class="dcp__badge">Configurador visual</span>
    <p class="dcp__tagline">Elige estilo y colores. Solicita presupuesto con tu selección.</p>
  </header>

  <div class="dcp__block">
    <h3 class="dcp__block-title">Paletas disponibles</h3>
    <div class="dcp__palettes" role="radiogroup" aria-label="Selector de paleta de colores">
      ${palettes.map((p, i) => `
        <button class="dcp__pal${i === 0 ? ' dcp__pal--active' : ''}"
                data-palette-id="${p.id}" type="button"
                aria-pressed="${i === 0}" aria-label="Paleta ${p.name}">
          <span class="dcp__pal-dots" aria-hidden="true">${
            p.swatches.map(c => `<i class="dcp__dot" style="background:${c}"></i>`).join('')
          }</span>
          <span class="dcp__pal-name">${p.name}</span>
        </button>`).join('')}
    </div>
  </div>

  <div class="dcp__block">
    <h3 class="dcp__block-title">Vista responsive</h3>
    <div class="dcp__viewports" role="radiogroup" aria-label="Selector de vista previa">
      <button class="dcp__vp" data-vp="mobile" type="button" aria-pressed="false" aria-label="Vista móvil — 390 px">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="2" width="14" height="20" rx="2.5"/><line x1="12" y1="18" x2="12.01" y2="18" stroke-width="2.5"/></svg>
        <span class="dcp__vp-label">Móvil</span>
        <span class="dcp__vp-px">390 px</span>
      </button>
      <button class="dcp__vp" data-vp="tablet" type="button" aria-pressed="false" aria-label="Vista tablet — 768 px">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="2" width="16" height="20" rx="2.5"/><line x1="12" y1="18" x2="12.01" y2="18" stroke-width="2.5"/></svg>
        <span class="dcp__vp-label">Tablet</span>
        <span class="dcp__vp-px">768 px</span>
      </button>
      <button class="dcp__vp dcp__vp--active" data-vp="desktop" type="button" aria-pressed="true" aria-label="Vista escritorio — ancho completo">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
        <span class="dcp__vp-label">Escritorio</span>
        <span class="dcp__vp-px">1200 px</span>
      </button>
    </div>
  </div>

  <div class="dcp__hr" role="separator" aria-hidden="true"></div>

  <div class="dcp__foot">
    <div class="dcp__foot-copy">
      <strong class="dcp__foot-q">¿Te gusta esta estética?</strong>
      <span class="dcp__foot-hint">Podemos usarla como base para tu web real.</span>
    </div>
    <div class="dcp__foot-ctas">
      <a id="dcp-cta-${demoId}" class="dcp__cta"
         href="${buildCtaUrl(presupuestoUrl, demoId, palettes[0], 'desktop')}"
         aria-label="Quiero esta estética — ir al formulario de presupuesto">
        Quiero esta estética
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><polyline points="12 5 19 12 12 19"/></svg>
      </a>
      <a id="dcp-wa-${demoId}" class="dcp__wa"
         href="${buildWaUrl(demoId, palettes[0])}"
         target="_blank" rel="noopener noreferrer"
         aria-label="Hablar por WhatsApp sobre esta demo">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.089.539 4.049 1.481 5.757L0 24l6.418-1.451A11.934 11.934 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.6a9.6 9.6 0 0 1-4.992-1.399l-.357-.213-3.706.84.877-3.603-.234-.37A9.6 9.6 0 1 1 12 21.6z"/></svg>
        WhatsApp
      </a>
    </div>
  </div>

</div>`;

  panel.querySelectorAll('.dcp__pal').forEach(btn => {
    btn.addEventListener('click', () => {
      const pal = palettes.find(p => p.id === btn.dataset.paletteId);
      if (!pal) return;
      const vp = document.body.dataset.demoVp || 'desktop';
      applyPalette(panel, demoId, pal, presupuestoUrl, vp, true);
      localStorage.setItem(`mcw-dp-${demoId}`, pal.id);
    });
  });

  panel.querySelectorAll('.dcp__vp').forEach(btn => {
    btn.addEventListener('click', () => {
      const wrap = document.getElementById('demoPreviewWrap');
      const active = panel.querySelector('.dcp__pal--active');
      const pal = palettes.find(p => p.id === active?.dataset.paletteId) || palettes[0];
      setViewport(panel, wrap, btn.dataset.vp, demoId, pal, presupuestoUrl);
      localStorage.setItem(`mcw-dvp-${demoId}`, btn.dataset.vp);
      trackEvent('cambio_viewport', { demo_id: demoId, vista: btn.dataset.vp });
    });
  });

  document.getElementById(`dcp-cta-${demoId}`)?.addEventListener('click', () => {
    const active = panel.querySelector('.dcp__pal--active');
    const pal = palettes.find(p => p.id === active?.dataset.paletteId) || palettes[0];
    trackEvent('click_demo_cta', {
      demo_id:    demoId,
      paleta_id:  pal.id,
      paleta_name: pal.name,
      vista:      document.body.dataset.demoVp || 'desktop',
    });
  });

  document.getElementById(`dcp-wa-${demoId}`)?.addEventListener('click', () => {
    const active = panel.querySelector('.dcp__pal--active');
    const pal = palettes.find(p => p.id === active?.dataset.paletteId) || palettes[0];
    trackEvent('click_whatsapp', {
      source:     'demo_panel',
      demo_id:    demoId,
      paleta_id:  pal.id,
    });
  });
}

/* ─── Palette ──────────────────────────────────── */

function applyPalette(panel, demoId, palette, presupuestoUrl, vp, animate) {
  const root = document.documentElement;

  if (animate) {
    root.classList.add('dcp-changing');
    setTimeout(() => root.classList.remove('dcp-changing'), 320);
    trackEvent('cambio_paleta', {
      demo_id:     demoId,
      paleta_id:   palette.id,
      paleta_name: palette.name,
    });
  }

  Object.entries(palette.vars).forEach(([k, v]) => root.style.setProperty(k, v));

  panel.querySelectorAll('.dcp__pal').forEach(btn => {
    const on = btn.dataset.paletteId === palette.id;
    btn.classList.toggle('dcp__pal--active', on);
    btn.setAttribute('aria-pressed', String(on));
  });

  const cta = document.getElementById(`dcp-cta-${demoId}`);
  if (cta) cta.href = buildCtaUrl(presupuestoUrl, demoId, palette, vp);

  const wa = document.getElementById(`dcp-wa-${demoId}`);
  if (wa) wa.href = buildWaUrl(demoId, palette);
}

/* ─── Viewport ─────────────────────────────────── */

function setViewport(panel, wrap, vp, demoId, palette, presupuestoUrl) {
  if (!wrap) return;
  wrap.dataset.vp = vp;
  document.body.dataset.demoVp = vp;

  panel.querySelectorAll('.dcp__vp').forEach(btn => {
    const on = btn.dataset.vp === vp;
    btn.classList.toggle('dcp__vp--active', on);
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
