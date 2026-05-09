/* =====================================================
   POST /api/payments/checkout
   Body: { plan: string, tipo: string }
===================================================== */

import express from "express";
import Stripe from "stripe";

const router = express.Router();
const stripe = new Stripe(process.env.STRIPE_SECRET_KEY);

/* ─────────────────────────────────────────────────
   Mapeo de planes a price_id (desde .env)
───────────────────────────────────────────────── */
const priceMap = {
  basico: {
    unico: process.env.STRIPE_PRICE_BASICO,
    cuotas: process.env.STRIPE_PRICE_BASICO_CUOTAS
  },
  profesional: {
    unico: process.env.STRIPE_PRICE_PROFESIONAL,
    cuotas: process.env.STRIPE_PRICE_PROFESIONAL_CUOTAS
  },
  premium: {
    unico: process.env.STRIPE_PRICE_PREMIUM,
    cuotas: process.env.STRIPE_PRICE_PREMIUM_CUOTAS
  },
  // ── Mantenimiento mensual (suscripciones recurrentes) ──
  "mant-basico": {
    mensual: process.env.STRIPE_PRICE_MANT_BASICO
  },
  "mant-profesional": {
    mensual: process.env.STRIPE_PRICE_MANT_PROFESIONAL
  },
  "mant-premium": {
    mensual: process.env.STRIPE_PRICE_MANT_PREMIUM
  }
};

router.post("/checkout", async (req, res) => {

  const { plan, tipo } = req.body;

  /* ── VALIDACIÓN ───────────────────────────── */
  if (!plan || !tipo) {
    return res.status(400).json({ error: "Faltan datos: plan o tipo" });
  }

  console.log("📦 Plan recibido:", plan);
  console.log("📦 Tipo recibido:", tipo);

  /* ── OBTENER PRICE ID ─────────────────────── */
  const priceId = priceMap[plan]?.[tipo];

  console.log("💰 Price ID:", priceId);

  if (!priceId) {
    return res.status(400).json({ error: "Plan no configurado en el servidor" });
  }

  /* ── MODO STRIPE ─────────────────────────── */
  const mode = (tipo === "cuotas" || tipo === "mensual") ? "subscription" : "payment";

  console.log("⚙️ Mode:", mode);

  /* ── CREAR SESIÓN ────────────────────────── */
  try {

    const session = await stripe.checkout.sessions.create({
      payment_method_types: ["card"],
      mode,
      line_items: [
        {
          price: priceId,
          quantity: 1
        }
      ],
      success_url: `${process.env.BASE_URL}/success.html`,
      cancel_url: `${process.env.BASE_URL}/cancel.html`,
    });

    res.json({ url: session.url });

  } catch (err) {

    console.error("❌ Stripe error:", err.message);

    res.status(500).json({
      error: "No se pudo crear la sesión de pago",
      detalle: err.message
    });
  }

});

export default router;
