/* =====================================================
   PLAN REGISTRY
   ─────────────────────────────────────────────────
   Fuente de verdad del backend.
   Pega tus price_id de Stripe en los campos "unico"
   y "cuotas" de cada plan, o léelos desde .env.

   Los price_id NUNCA salen del servidor.
===================================================== */

export const PLANES = {

  basico: {
    label:  "Plan Básico",
    unico:  process.env.STRIPE_PRICE_BASICO,         // price_xxx — pago único
    cuotas: process.env.STRIPE_PRICE_BASICO_CUOTAS,  // price_xxx — suscripción mensual
    cuotasTotal: 3,
    cancelDias:  63   // cancel_at: buffer tras el 3.er cobro (día 0, 30, 60)
  },

  profesional: {
    label:  "Plan Profesional",
    unico:  process.env.STRIPE_PRICE_PROFESIONAL,
    cuotas: process.env.STRIPE_PRICE_PROFESIONAL_CUOTAS,
    cuotasTotal: 3,
    cancelDias:  63
  },

  premium: {
    label:  "Plan Premium",
    unico:  process.env.STRIPE_PRICE_PREMIUM,
    cuotas: process.env.STRIPE_PRICE_PREMIUM_CUOTAS,
    cuotasTotal: 3,
    cancelDias:  63
  }

};

// Tipos válidos y su modo Stripe equivalente
export const TIPOS = {
  unico:  "payment",
  cuotas: "subscription"
};

/**
 * Devuelve { priceId, mode, plan } o null si plan/tipo no existe.
 * @param {string} planKey  "basico" | "profesional" | "premium"
 * @param {string} tipo     "unico" | "cuotas"
 */
export function resolvePrice(planKey, tipo) {

  const plan = PLANES[planKey];
  if (!plan) return null;

  const mode    = TIPOS[tipo];
  if (!mode) return null;

  const priceId = plan[tipo];   // plan.unico | plan.cuotas
  if (!priceId) return null;

  return { priceId, mode, plan };
}
