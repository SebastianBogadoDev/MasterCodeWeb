/* =====================================================
   REVIEWS MODULE — MasterCodeWeb
   Carga reseñas aprobadas y gestiona el formulario
   de envío de nuevas reseñas con moderación manual.
===================================================== */

export function initReviews() {
  initStarPicker();
  loadApprovedReviews();
  initReviewForm();
}


/* ── Star Picker (formulario) ──────────────────────── */

function initStarPicker() {
  const container = document.getElementById('starPicker');
  if (!container) return;

  const inputs   = container.querySelectorAll('input[type="radio"]');
  const labels   = container.querySelectorAll('label');
  const hint     = document.getElementById('starHint');
  const hintTexts = {
    1: 'Deficiente', 2: 'Regular', 3: 'Bueno', 4: 'Muy bueno', 5: 'Excelente'
  };

  // Escucha cambios en los radio buttons
  inputs.forEach(input => {
    input.addEventListener('change', () => {
      const val = +input.value;
      if (hint) hint.textContent = hintTexts[val] || '';
    });
  });
}


/* ── Cargar reseñas aprobadas ──────────────────────── */

async function loadApprovedReviews() {
  const container = document.getElementById('reviewsContainer');
  if (!container) return;

  const stats = document.getElementById('reviewsStats');

  try {
    const res  = await fetch('/api/get-reviews.php', { method: 'GET' });
    const data = await res.json();

    if (!data.ok) throw new Error('API error');

    updateStats(stats, data);
    renderReviews(container, data.reviews);
  } catch (err) {
    renderError(container);
  }
}

function updateStats(statsEl, data) {
  if (!statsEl) return;

  const totalEl   = statsEl.querySelector('[data-stat="total"]');
  const avgEl     = statsEl.querySelector('[data-stat="average"]');
  const starsEl   = statsEl.querySelector('[data-stat="stars"]');

  if (data.total === 0) {
    statsEl.style.display = 'none';
    return;
  }

  if (totalEl) totalEl.textContent = data.total;
  if (avgEl)   avgEl.textContent   = data.average ? data.average.toFixed(1) : '—';
  if (starsEl) starsEl.innerHTML   = buildStarsHTML(data.average || 0, 'stars-display--lg');
}

function renderReviews(container, reviews) {
  if (!reviews.length) {
    container.innerHTML = `
      <div class="reviews-empty">
        <div class="reviews-empty__icon" aria-hidden="true">⭐</div>
        <p class="reviews-empty__title">Todavía no hay reseñas publicadas</p>
        <p class="reviews-empty__text">
          Estamos recopilando las primeras reseñas verificadas de clientes reales.
          Si has trabajado con nosotros, sé el primero en compartir tu experiencia.
        </p>
      </div>`;
    return;
  }

  container.innerHTML = reviews.map(buildReviewCard).join('');
}

function buildReviewCard(review) {
  const initial  = (review.name || '?').charAt(0).toUpperCase();
  const name     = escapeHtml(review.name || 'Cliente');
  const comment  = escapeHtml(review.comment || '');
  const service  = escapeHtml(review.service || '');
  const date     = formatDate(review.project_date || review.created_at || '');
  const stars    = buildStarsHTML(review.rating || 0);

  return `
    <article class="review-card reveal">
      <div class="review-card__header">
        <div class="review-card__author">
          <div class="review-card__avatar" aria-hidden="true">${initial}</div>
          <div>
            <div class="review-card__name">${name}</div>
            <div class="review-card__meta">${stars}</div>
          </div>
        </div>
        ${service ? `<span class="review-card__service">${service}</span>` : ''}
      </div>
      <p class="review-card__comment">${comment}</p>
      <div class="review-card__footer">
        <span class="review-card__date">${date}</span>
        <span class="review-card__verified" aria-label="Reseña verificada">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
          Verificada
        </span>
      </div>
    </article>`;
}

function buildStarsHTML(rating, extraClass = '') {
  const filled = Math.round(rating);
  let html = `<span class="stars-display ${extraClass}" aria-label="${rating} de 5 estrellas">`;
  for (let i = 1; i <= 5; i++) {
    html += `<span class="${i <= filled ? 'star--filled' : 'star--empty'}" aria-hidden="true">★</span>`;
  }
  html += '</span>';
  return html;
}

function renderError(container) {
  container.innerHTML = `
    <div class="reviews-empty">
      <div class="reviews-empty__icon" aria-hidden="true">💬</div>
      <p class="reviews-empty__title">No se pudieron cargar las reseñas</p>
      <p class="reviews-empty__text">Intenta recargar la página.</p>
    </div>`;
}


/* ── Formulario de reseña ──────────────────────────── */

function initReviewForm() {
  const form = document.getElementById('reviewForm');
  if (!form) return;

  initCharCounter();
  initFormSubmit(form);
}

function initCharCounter() {
  const textarea = document.getElementById('reviewComment');
  const counter  = document.getElementById('charCount');
  if (!textarea || !counter) return;

  const MAX = 800;
  textarea.addEventListener('input', () => {
    const len = textarea.value.length;
    counter.textContent = `${len} / ${MAX}`;
    counter.classList.toggle('char-count--warn', len > MAX * 0.85);
  });
}

async function initFormSubmit(form) {
  const submitBtn  = form.querySelector('[type="submit"]');
  const successBox = document.getElementById('reviewSuccess');
  const errorBox   = document.getElementById('reviewError');

  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    if (errorBox)  { errorBox.textContent = ''; errorBox.hidden = true; }

    /* Validación básica client-side antes de enviar */
    const rating = form.querySelector('input[name="rating"]:checked');
    if (!rating) {
      showError(errorBox, 'Selecciona una valoración de estrellas antes de enviar.');
      return;
    }

    setLoading(submitBtn, true);

    /* Recoger datos del formulario */
    const formData = new FormData(form);
    const payload  = {
      name:                   formData.get('name')         || '',
      email:                  formData.get('email')        || '',
      rating:                 parseInt(formData.get('rating')) || 0,
      comment:                formData.get('comment')      || '',
      service:                formData.get('service')      || '',
      project_date:           formData.get('project_date') || '',
      consent:                !!formData.get('consent'),
      'cf-turnstile-response': formData.get('cf-turnstile-response') || '',
    };

    try {
      const res  = await fetch('/api/reviews.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(payload),
      });

      const data = await res.json();

      if (data.ok) {
        form.style.display     = 'none';
        if (successBox) {
          successBox.classList.add('is-visible');
          successBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
      } else {
        const msgs = data.errors
          ? data.errors.join('\n')
          : (data.error || 'Ha ocurrido un error. Inténtalo de nuevo.');
        showError(errorBox, msgs);
      }
    } catch (err) {
      showError(errorBox, 'Error de conexión. Comprueba tu internet e inténtalo de nuevo.');
    } finally {
      setLoading(submitBtn, false);
    }
  });
}

function showError(el, message) {
  if (!el) return;
  el.textContent = message;
  el.hidden      = false;
  el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function setLoading(btn, loading) {
  if (!btn) return;
  btn.disabled      = loading;
  btn.dataset.text  = btn.dataset.text || btn.textContent;
  btn.textContent   = loading ? 'Enviando…' : btn.dataset.text;
}


/* ── Utils ─────────────────────────────────────────── */

function escapeHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function formatDate(raw) {
  if (!raw) return '';
  // project_date: "2025-03" o "2025"
  if (/^\d{4}-\d{2}$/.test(raw)) {
    const [y, m] = raw.split('-');
    const names  = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    return `${names[parseInt(m) - 1]} ${y}`;
  }
  if (/^\d{4}$/.test(raw)) return raw;
  // created_at ISO date
  try {
    const d = new Date(raw);
    return d.toLocaleDateString('es-ES', { month: 'short', year: 'numeric' });
  } catch {
    return '';
  }
}
