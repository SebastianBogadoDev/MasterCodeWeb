<?php
/* =====================================================
   STRIPE CONFIG — MasterCodeWeb
   Copia este archivo como config.php y rellena los valores.
   NUNCA subas config.php a git (está en .gitignore).

   Pasos en Stripe Dashboard:
   1. API keys → Dashboard → Developers → API keys
   2. Price IDs → Dashboard → Products → Add product
      · Planes únicos: precio one-time
      · Mantenimiento: precio recurrente mensual
   3. Webhook secret → Dashboard → Developers → Webhooks
      URL del webhook: https://www.mastercodeweb.com/api/webhook.php
      Eventos a escuchar:
        - checkout.session.completed
        - invoice.paid
        - invoice.payment_failed
===================================================== */

// ── API Keys ─────────────────────────────────────────
define('STRIPE_SECRET_KEY',     'sk_live_PEGA_AQUI_TU_SECRET_KEY');   // sk_live_... o sk_test_...
define('STRIPE_WEBHOOK_SECRET', 'whsec_PEGA_AQUI_TU_WEBHOOK_SECRET'); // whsec_...

// ── URLs de redirección ──────────────────────────────
define('SITE_URL',    'https://www.mastercodeweb.com');
define('SUCCESS_URL', SITE_URL . '/pages/stripe_success.html?plan={PLAN}&session_id={CHECKOUT_SESSION_ID}');
define('CANCEL_URL',  SITE_URL . '/pages/stripe_cancel.html');

// ── Price IDs — Pago único (one-time) ────────────────
define('PRICE_BASICO',        'price_1TY82mBYGo0Y9vKY4ZDN76xL');        // 349 €
define('PRICE_PRO',           'price_1TY8bdBYGo0Y9vKYOoUfg2jv');           // 699 €
define('PRICE_PREMIUM',       'price_1TYAbPBYGo0Y9vKYrLIrvwdf');       // 1.499 €

// ── Price IDs — Mantenimiento mensual (subscription) ─
define('PRICE_MANT_BASICO',   'price_1TY8OVBYGo0Y9vKYqfeGxD2M');   // 29 €/mes
define('PRICE_MANT_PRO',      'price_1TY8bdBYGo0Y9vKYmMNqdo0K');      // 49 €/mes
define('PRICE_MANT_PREMIUM',  'price_1TYAdhBYGo0Y9vKYGIVIws7d');  // 149 €/mes

// ── Notificaciones ───────────────────────────────────
define('OWNER_EMAIL', 'contact@mastercodeweb.com');
