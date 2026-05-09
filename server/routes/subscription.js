/* =====================================================
   POST /api/create-subscription — Pago en cuotas
   Body: { plan: "basico_cuotas" | "profesional_cuotas" | "premium_cuotas" }
===================================================== */

import express       from "express";
import Stripe        from "stripe";
import { getPlan }   from "../config/plans.js";

const router = express.Router();
const stripe = new Stripe(process.env.STRIPE_SECRET_KEY);

router.post("/", async (req, res) => {

  const { plan } = req.body ?? {};

  if (!plan || typeof plan !== "string") {
    return res.status(400).json({ error: "Campo 'plan' requerido" });
  }

  const cfg = getPlan(plan.trim().toLowerCase());

  if (!cfg || cfg.mode !== "subscription") {
    return res.status(400).json({ error: `Plan no válido: "${plan}"` });
  }

  if (!cfg.priceId) {
    console.error(`[MCW subscription] Price ID no configurado: ${plan}`);
    return res.status(500).json({ error: "Plan no configurado en servidor" });
  }

  try {

    const BASE_URL = process.env.BASE_URL || "http://127.0.0.1:5500";

    const cancelAt = Math.floor(Date.now() / 1000) + cfg.cancelDias * 24 * 60 * 60;

    const session = await stripe.checkout.sessions.create({

      mode:       "subscription",
      line_items: [{ price: cfg.priceId, quantity: 1 }],

      subscription_data: {
        cancel_at: cancelAt,
        metadata: {
          cuotas_plan:  "true",
          cuotas_total: String(cfg.cuotas),
          producto:     cfg.label,
          plan_key:     plan
        }
      },

      custom_text: {
        submit: {
          message: `Se realizarán ${cfg.cuotas} cobros automáticos mensuales. La suscripción se cancela automáticamente.`
        }
      },

      customer_creation: "always",
      metadata:          { plan },
      success_url:       `${BASE_URL}/success.html`,
      cancel_url:        `${BASE_URL}/cancel.html`

    });

    console.log(`[MCW subscription] ✅ ${cfg.label} · ${session.id}`);
    res.json({ url: session.url });

  } catch (err) {
    console.error("[MCW subscription] ❌", err.type ?? "", err.code ?? "", err.message);
    res.status(500).json({ error: "No se pudo iniciar el pago en cuotas" });
  }

});

export default router;
