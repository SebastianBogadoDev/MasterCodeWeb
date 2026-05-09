/* =====================================================
   MASTERCODEWEB · API SERVER (VERSIÓN PRO)
===================================================== */

import express from "express";
import cors from "cors";
import dotenv from "dotenv";

/* ── CARGAR VARIABLES DE ENTORNO ───────────────────── */
dotenv.config();

/* ── IMPORTAR RUTAS ───────────────────────────────── */
import checkoutRoutes from "./routes/checkout.js";
import paymentRoutes from "./routes/payments.js";
import leadRoutes from "./routes/leads.js";
import webhookRoutes from "./routes/webhooks.js";

/* ── INIT APP ─────────────────────────────────────── */
const app = express();

/* =====================================================
   CORS
===================================================== */

const ALLOWED_ORIGINS = [
  "https://www.mastercodeweb.com",
  "https://mastercodeweb.com",
  "http://localhost:5500",
  "http://127.0.0.1:5500"
];

app.use(cors({
  origin(origin, cb) {
    if (!origin || ALLOWED_ORIGINS.includes(origin)) {
      return cb(null, true);
    }
    console.warn("❌ CORS bloqueado:", origin);
    cb(new Error(`CORS: origen no permitido — ${origin}`));
  },
  credentials: true
}));

/* =====================================================
   WEBHOOK (ANTES DE JSON)
===================================================== */

app.use("/api/webhooks", webhookRoutes);

/* =====================================================
   BODY PARSER
===================================================== */

app.use(express.json({ limit: "10kb" }));

/* =====================================================
   RATE LIMIT
===================================================== */

const ipStore = new Map();

function rateLimit({ windowMs, max }) {
  return (req, res, next) => {

    const ip = req.ip || "unknown";
    const now = Date.now();
    let entry = ipStore.get(ip);

    if (!entry || now - entry.time > windowMs) {
      entry = { time: now, count: 1 };
    } else {
      entry.count++;
    }

    ipStore.set(ip, entry);

    if (entry.count > max) {
      return res.status(429).json({
        error: "Demasiadas solicitudes. Inténtalo más tarde."
      });
    }

    next();
  };
}

const paymentLimiter = rateLimit({
  windowMs: 15 * 60 * 1000,
  max: 10
});

/* =====================================================
   SEGURIDAD
===================================================== */

app.use((req, res, next) => {
  res.setHeader("X-Content-Type-Options", "nosniff");
  res.setHeader("X-Frame-Options", "DENY");
  res.setHeader("Referrer-Policy", "strict-origin-when-cross-origin");
  next();
});

/* =====================================================
   DEBUG GLOBAL (MUY IMPORTANTE)
===================================================== */

app.use((req, _res, next) => {
  console.log(`➡️ ${req.method} ${req.url}`);
  next();
});

/* =====================================================
   RUTAS
===================================================== */

/* 🔥 SOLO USA ESTA (la correcta) */
app.use("/api/payments", paymentLimiter, paymentRoutes);

/* OPCIONAL */
app.use("/api/checkout", paymentLimiter, checkoutRoutes);
app.use("/api/leads", leadRoutes);

/* =====================================================
   HEALTH CHECK
===================================================== */

app.get("/health", (_req, res) => {
  res.json({
    status: "ok",
    ts: new Date().toISOString()
  });
});

/* =====================================================
   ERROR HANDLER
===================================================== */

app.use((err, _req, res, _next) => {

  const isDev = process.env.NODE_ENV !== "production";

  console.error("❌ ERROR GLOBAL:", err.message);

  res.status(err.status || 500).json({
    error: isDev ? err.message : "Error interno del servidor"
  });
});

/* =====================================================
   START SERVER
===================================================== */

const PORT = process.env.PORT || 3000;

app.listen(PORT, () => {

  console.log("\n🚀 MasterCodeWeb API · http://localhost:" + PORT);

  console.log("Stripe:",
    process.env.STRIPE_SECRET_KEY
      ? "✅"
      : "❌ falta STRIPE_SECRET_KEY"
  );

  console.log("Webhook:",
    process.env.STRIPE_WEBHOOK_SECRET
      ? "✅"
      : "❌ falta STRIPE_WEBHOOK_SECRET"
  );

  console.log("Precios:");
  console.log("Básico:", process.env.STRIPE_PRICE_BASICO);
  console.log("Profesional:", process.env.STRIPE_PRICE_PROFESIONAL);
  console.log("Premium:", process.env.STRIPE_PRICE_PREMIUM);

  console.log("");
});
