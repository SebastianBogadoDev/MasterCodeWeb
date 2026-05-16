/* =====================================================
   PAYMENTS MODULE
   Botones de pago redirigen a presupuesto.html.
   Integración de pasarela pendiente de backend.
===================================================== */

export function initPayments() {
  initPagoButtons();
  initCuotasButtons();
  initMaintButtons();
}

/* ── Pago único · .mcw-pago-btn ─────────────── */

function initPagoButtons() {
  document.querySelectorAll(".mcw-pago-btn").forEach((btn) => {
    btn.addEventListener("click", () => {

      // data-nombre="profesional" → plan: "profesional", tipo: "unico"
      const plan = btn.dataset.nombre?.trim();
      if (!plan) return showError(btn, "Error de configuración.");

      checkout(btn, { plan, tipo: "unico" });

    });
  });
}

/* ── Cuotas · .mcw-cuotas-btn ───────────────── */

function initCuotasButtons() {
  document.querySelectorAll(".mcw-cuotas-btn").forEach((btn) => {
    btn.addEventListener("click", () => {

      // data-nombre="profesional-cuotas" → extraer "profesional"
      const raw  = btn.dataset.nombre?.trim() ?? "";
      const plan = raw.replace(/-?cuotas$/i, "").replace(/_?cuotas$/i, "").trim();

      if (!plan) return showError(btn, "Error de configuración.");

      checkout(btn, { plan, tipo: "cuotas" });

    });
  });
}

/* ── Mantenimiento · .contratar-btn ─────────── */

function initMaintButtons() {
  document.querySelectorAll(".contratar-btn").forEach(btn => {
    btn.addEventListener("click", () => {
      const plan = btn.dataset.plan?.trim();
      const tipo = btn.dataset.tipo?.trim() || "mensual";
      if (!plan) return showError(btn, "Error de configuración.");
      checkoutMaint(btn, { plan, tipo });
    });
  });
}

async function checkoutMaint(btn, { plan, tipo }) {
  setLoading(btn, true);

  try {
    const res  = await fetch('/api/checkout.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ plan, tipo }),
    });
    const data = await res.json();

    if (data.url) {
      window.location.href = data.url;
    } else {
      setLoading(btn, false);
      showError(btn, data.error ?? 'Error al procesar el pago. Inténtalo de nuevo.');
    }
  } catch {
    setLoading(btn, false);
    showError(btn, 'Error de conexión. Inténtalo de nuevo o usa WhatsApp.');
  }
}

/* ── Lógica compartida ───────────────────────── */

async function checkout(btn, { plan, tipo }) {
  if (!isTermsAccepted(btn)) return;
  setLoading(btn, true);

  try {
    const res  = await fetch('/api/checkout.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ plan, tipo }),
    });
    const data = await res.json();

    if (data.url) {
      window.location.href = data.url;
    } else {
      setLoading(btn, false);
      showError(btn, data.error ?? 'Error al procesar el pago. Inténtalo de nuevo.');
    }
  } catch {
    setLoading(btn, false);
    showError(btn, 'Error de conexión. Inténtalo de nuevo o usa WhatsApp.');
  }
}

/* ── Terms check ─────────────────────────────── */

function isTermsAccepted(btn) {
  const cb = document.getElementById('termsAccepted');
  if (!cb) return true; // not on a checkout page
  if (cb.checked) return true;

  const label = cb.closest('.checkout-terms');
  if (label) {
    label.classList.add('checkout-terms--error');
    label.scrollIntoView({ behavior: 'smooth', block: 'center' });
    setTimeout(() => label.classList.remove('checkout-terms--error'), 3500);
  }
  return false;
}


/* ── Helpers UX ──────────────────────────────── */

function setLoading(btn, on) {
  if (on) {
    btn.dataset.orig = btn.innerHTML;
    btn.innerHTML    = "Procesando...";
    btn.disabled     = true;
    btn.classList.add("is-loading");
  } else {
    btn.innerHTML = btn.dataset.orig ?? btn.innerHTML;
    btn.disabled  = false;
    btn.classList.remove("is-loading");
  }
}

function showError(btn, msg) {
  btn.parentElement.querySelector(".payment-error")?.remove();
  const p = Object.assign(document.createElement("p"), {
    className:   "payment-error",
    textContent: msg
  });
  p.setAttribute("role", "alert");
  btn.parentElement.appendChild(p);
  setTimeout(() => p.remove(), 6000);
}
