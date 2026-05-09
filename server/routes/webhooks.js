/* =====================================================
   WEBHOOKS · STRIPE
   ─────────────────────────────────────────────────
   IMPORTANTE: Esta ruta debe registrarse ANTES de
   express.json() en app.js — Stripe necesita el body
   crudo (raw) para verificar la firma HMAC-SHA256.
   Si express.json() se ejecutara primero, la firma
   no coincidiría y todos los webhooks serían rechazados.
===================================================== */

import express      from "express";
import Stripe       from "stripe";
import { savePayment } from "../services/store.js";

const router = express.Router();
const stripe = new Stripe(process.env.STRIPE_SECRET_KEY);

/* =====================================================
   POST /api/webhooks/stripe
===================================================== */

router.post(
  "/stripe",
  express.raw({ type: "application/json" }),
  async (req, res) => {

    const sig           = req.headers["stripe-signature"];
    const webhookSecret = process.env.STRIPE_WEBHOOK_SECRET;

    /* ── Guardia: secret no configurado ─────────── */
    if (!webhookSecret) {
      console.error("[MCW webhook] STRIPE_WEBHOOK_SECRET no configurado en .env");
      return res.status(500).json({ error: "Configuración de webhook incompleta" });
    }

    /* ── Verificar firma de Stripe ───────────────── */
    let event;
    try {
      event = stripe.webhooks.constructEvent(req.body, sig, webhookSecret);
    } catch (err) {
      // Firma inválida — posible petición maliciosa o secret incorrecto
      console.error("[MCW webhook] ❌ Firma inválida:", err.message);
      return res.status(400).send(`Webhook Error: ${err.message}`);
    }

    console.log(`[MCW webhook] 📩 Evento recibido: ${event.type} · ${event.id}`);

    /* ── Despachar evento ────────────────────────── */
    try {
      switch (event.type) {

        case "checkout.session.completed":
          await handleCheckoutCompleted(event.data.object);
          break;

        case "invoice.payment_succeeded":
          await handleInvoicePaid(event.data.object);
          break;

        case "invoice.payment_failed":
          await handleInvoiceFailed(event.data.object);
          break;

        default:
          // Ignorar silenciosamente eventos no gestionados
          break;
      }
    } catch (err) {
      // Loguear pero responder 200: si respondemos 4xx/5xx,
      // Stripe reintentará el evento indefinidamente.
      console.error(`[MCW webhook] ❌ Error procesando ${event.type}:`, err.message);
    }

    // Stripe espera 200 para considerar el webhook entregado
    res.json({ received: true });
  }
);


/* =====================================================
   HANDLER: checkout.session.completed
   ─────────────────────────────────────────────────
   Dispara al completar el checkout, tanto para pagos
   únicos (mode: "payment") como para el arranque de una
   suscripción (mode: "subscription").

   - mode "payment"      → guardar pago único
   - mode "subscription" → solo log; los cobros
     individuales los gestiona invoice.payment_succeeded
===================================================== */

async function handleCheckoutCompleted(session) {

  const { id, mode, customer_email, amount_total, currency, metadata } = session;

  if (mode === "payment") {

    console.log(
      `[MCW webhook] ✅ Pago único completado` +
      ` · ${(amount_total / 100).toFixed(2)} ${currency?.toUpperCase()}` +
      ` · ${customer_email || "sin email"}` +
      ` · Plan: ${metadata?.producto || "—"}`
    );

    await savePayment({
      type:          "pago_unico",
      sessionId:     id,
      email:         customer_email || null,
      producto:      metadata?.producto || null,
      importe:       amount_total / 100,
      moneda:        currency,
      paymentIntent: session.payment_intent,
      estado:        "completado"
    });

  } else if (mode === "subscription") {

    // El primer cobro real llega vía invoice.payment_succeeded.
    // Aquí solo confirmamos que el cliente entró al flujo.
    console.log(
      `[MCW webhook] 📋 Suscripción iniciada` +
      ` · ${customer_email || "sin email"}` +
      ` · Sub: ${session.subscription}`
    );
  }
}


/* =====================================================
   HANDLER: invoice.payment_succeeded
   ─────────────────────────────────────────────────
   Dispara cada vez que Stripe cobra una cuota.
   Cuando se detecta el 3.er cobro:
     1. Cancela la suscripción inmediatamente (principal)
     2. cancel_at actúa como red de seguridad (en subscription.js)
===================================================== */

async function handleInvoicePaid(invoice) {

  const subscriptionId = invoice.subscription;
  if (!subscriptionId) return; // Factura de pago único — ignorar aquí

  /* ── Leer metadata de la suscripción ────────── */
  const subscription = await stripe.subscriptions.retrieve(subscriptionId);

  // Solo gestionamos suscripciones de cuotas propias
  if (subscription.metadata?.cuotas_plan !== "true") return;

  const totalCuotas = parseInt(subscription.metadata.cuotas_total || "3", 10);
  const producto    = subscription.metadata.producto || "Plan desconocido";

  /* ── Contar facturas pagadas ─────────────────── */
  const { data: invoicesPagadas } = await stripe.invoices.list({
    subscription: subscriptionId,
    status:       "paid",
    limit:        10          // Máximo de cuotas esperadas: 3
  });

  const paidCount = invoicesPagadas.length;

  console.log(
    `[MCW webhook] 💳 Cuota pagada: ${paidCount}/${totalCuotas}` +
    ` · ${producto}` +
    ` · ${invoice.customer_email || "sin email"}` +
    ` · ${(invoice.amount_paid / 100).toFixed(2)} €`
  );

  /* ── Guardar registro de la cuota ────────────── */
  await savePayment({
    type:           "cuota",
    subscriptionId,
    cuotaNum:       paidCount,
    cuotaTotal:     totalCuotas,
    email:          invoice.customer_email || null,
    producto,
    importe:        invoice.amount_paid / 100,
    moneda:         invoice.currency,
    facturaUrl:     invoice.hosted_invoice_url,
    estado:         "pagado"
  });

  /* ── Cancelar tras el último cobro ───────────── */
  if (paidCount >= totalCuotas) {
    await stripe.subscriptions.cancel(subscriptionId);
    console.log(
      `[MCW webhook] 🏁 Suscripción completada y cancelada: ${subscriptionId}` +
      ` · ${paidCount} cuotas cobradas`
    );
  }
}


/* =====================================================
   HANDLER: invoice.payment_failed
   ─────────────────────────────────────────────────
   Stripe ya reintenta automáticamente según la
   configuración del Dashboard (Billing → Settings →
   Retry schedule). Este handler registra el fallo
   para auditoría y alertas futuras.
===================================================== */

async function handleInvoiceFailed(invoice) {

  const subscriptionId = invoice.subscription;
  if (!subscriptionId) return;

  const subscription = await stripe.subscriptions.retrieve(subscriptionId);
  if (subscription.metadata?.cuotas_plan !== "true") return;

  console.warn(
    `[MCW webhook] ⚠️  Pago fallido` +
    ` · Sub: ${subscriptionId}` +
    ` · ${invoice.customer_email || "sin email"}` +
    ` · Intento: ${invoice.attempt_count || 1}` +
    ` · Motivo: ${invoice.last_payment_error?.message || "desconocido"}`
  );

  await savePayment({
    type:           "cuota_fallida",
    subscriptionId,
    email:          invoice.customer_email || null,
    producto:       subscription.metadata.producto || null,
    importe:        invoice.amount_due / 100,
    moneda:         invoice.currency,
    intentos:       invoice.attempt_count || 1,
    motivoFallo:    invoice.last_payment_error?.message || null,
    estado:         "fallido"
  });
}

export default router;
