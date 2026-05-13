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

async function checkoutMaint(btn, _body) {
  // DESACTIVADO TEMPORALMENTE — redirige a presupuesto hasta activar backend
  setLoading(btn, false);
  window.location.href = "/pages/presupuesto.html";
}

/* ── Lógica compartida ───────────────────────── */

async function checkout(btn, _body) {
  // DESACTIVADO TEMPORALMENTE — redirige a presupuesto hasta activar backend
  setLoading(btn, false);
  window.location.href = "/pages/presupuesto.html";
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
