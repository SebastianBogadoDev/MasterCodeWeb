/* =====================================================
   PAYMENTS MODULE
   ─────────────────────────────────────────────────
   Ambos tipos de botón llaman al mismo endpoint:
   POST http://localhost:3000/api/create-checkout-session

   Pago único:  { plan: "profesional", tipo: "unico"  }
   Cuotas:      { plan: "profesional", tipo: "cuotas" }
===================================================== */

import { sendLead } from "./leads.js";

// PAGOS DESACTIVADOS TEMPORALMENTE — sin backend activo
// const API_URL      = "http://localhost:3000";
// const API_ENDPOINT = `${API_URL}/api/checkout`;
// const API_PAYMENTS = `${API_URL}/api/payments/checkout`;

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

      sendLead({ plan, precio: btn.dataset.precio ?? "", origen: "pago-unico" });
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

      sendLead({ plan, precio: btn.dataset.total ?? "", origen: "cuotas" });
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
  window.location.href = "/presupuesto.html";
}

/* ── Lógica compartida ───────────────────────── */

async function checkout(btn, _body) {
  // DESACTIVADO TEMPORALMENTE — redirige a presupuesto hasta activar backend
  setLoading(btn, false);
  window.location.href = "/presupuesto.html";
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
