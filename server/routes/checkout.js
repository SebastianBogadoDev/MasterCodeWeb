/* =====================================================
   POST /api/create-checkout-session
   ─────────────────────────────────────────────────
   Endpoint único para pago único y cuotas.

   Body:     { plan: "basico"|"profesional"|"premium",
               tipo: "unico"|"cuotas" }
   Response: { url: string }
===================================================== */

import express             from "express";
import Stripe              from "stripe";
import { resolvePrice, PLANES, TIPOS } from "../config/plans.js";

const router = express.Router();
const stripe = new Stripe(process.env.STRIPE_SECRET_KEY);

router.post("/", async (req, res) => {

  /* ── 1. Validar input ──────────────────────── */
  const { plan, tipo } = req.body ?? {};

  if (!plan || typeof plan !== "string") {
    return res.status(400).json({ error: "Campo 'plan' requerido" });
  }

  if (!tipo || typeof tipo !== "string") {
    return res.status(400).json({ error: "Campo 'tipo' requerido: 'unico' o 'cuotas'" });
  }

  if (!PLANES[plan]) {
    return res.status(400).json({
      error: `Plan no válido: "${plan}". Opciones: ${Object.keys(PLANES).join(", ")}`
    });
  }

  if (!TIPOS[tipo]) {
    return res.status(400).json({
      error: `Tipo no válido: "${tipo}". Opciones: unico, cuotas`
    });
  }

  /* ── 2. Resolver price_id y mode ───────────── */
  const resolved = resolvePrice(plan, tipo);

  if (!resolved) {
    console.error(`[MCW checkout] Price ID no configurado: ${plan} / ${tipo}`);
    return res.status(500).json({ error: "Plan no configurado en el servidor" });
  }

  const { priceId, mode, plan: planCfg } = resolved;

  /* ── 3. Construir parámetros de sesión ─────── */
  const BASE_URL = process.env.BASE_URL || "http://127.0.0.1:5500";

  const sessionParams = {

    mode,

    payment_method_types: ["card"],

    line_items: [{ price: priceId, quantity: 1 }],

    // Customer con email → disponible en webhook
    customer_creation: "always",

    metadata: { plan, tipo },

    success_url: `${BASE_URL}/success.html`,
    cancel_url:  `${BASE_URL}/cancel.html`

  };

  /* ── 4. Parámetros solo para suscripciones ─── */
  if (mode === "subscription") {

    const cancelAt =
      Math.floor(Date.now() / 1000) + planCfg.cancelDias * 24 * 60 * 60;

    sessionParams.subscription_data = {
      cancel_at: cancelAt,
      metadata: {
        cuotas_plan:  "true",
        cuotas_total: String(planCfg.cuotasTotal),
        producto:     planCfg.label,
        plan_key:     plan
      }
    };

    sessionParams.custom_text = {
      submit: {
        message:
          `Se realizarán ${planCfg.cuotasTotal} cobros automáticos mensuales. ` +
          `La suscripción se cancela automáticamente tras el último cobro.`
      }
    };

  }

  /* ── 5. Crear sesión en Stripe ─────────────── */
  try {

    const session = await stripe.checkout.sessions.create(sessionParams);

    console.log(
      `[MCW checkout] ✅ ${planCfg.label} · tipo: ${tipo}` +
      ` · mode: ${mode} · session: ${session.id}`
    );

    res.json({ url: session.url });

  } catch (err) {
    console.error(
      "[MCW checkout] ❌",
      err.type    ?? "—",
      err.code    ?? "—",
      err.message
    );
    res.status(500).json({ error: "No se pudo crear la sesión de pago" });
  }

});

export default router;
