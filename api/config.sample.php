<?php
/* =====================================================
   STRIPE CONFIG — MasterCodeWeb
   Copia este archivo como config.php y rellena los valores.
   NUNCA subas config.php a git (está en .gitignore).

   Pasos en Stripe Dashboard:
   1. Obtén las keys en: Dashboard → Developers → API keys
   2. Crea los Price IDs en: Dashboard → Products → Add product
      Crea un producto por plan, añade precios:
        - Planes únicos: precio one-time
        - Cuotas: precio recurrente mensual (misma cantidad que 1/3 del plan)
        - Mantenimiento: precio recurrente mensual
   3. Obtén el webhook secret en: Dashboard → Developers → Webhooks
      URL del webhook: https://www.mastercodeweb.com/api/webhook.php
      Eventos a escuchar:
        - checkout.session.completed
        - invoice.paid
        - invoice.payment_failed
   4. Instala el SDK: composer require stripe/stripe-php
===================================================== */

// ── API Keys ─────────────────────────────────────────
// Obtén estos valores en: dashboard.stripe.com → Developers → API keys / Webhooks
define('STRIPE_SECRET_KEY',     'PEGA_AQUI_TU_SECRET_KEY');      // empieza por sk_live_
define('STRIPE_WEBHOOK_SECRET', 'PEGA_AQUI_TU_WEBHOOK_SECRET');  // empieza por whsec_

// ── URLs de redirección ──────────────────────────────
define('SITE_URL',      'https://www.mastercodeweb.com');
define('SUCCESS_URL',   SITE_URL . '/pages/stripe_success.html?plan={PLAN}&session_id={CHECKOUT_SESSION_ID}');
define('CANCEL_URL',    SITE_URL . '/pages/stripe_cancel.html');

// ── Price IDs (crear en Stripe Dashboard) ────────────
// Planes únicos (one-time)
define('PRICE_BASICO',          'PEGA_PRICE_ID_BASICO');
define('PRICE_PRO',             'PEGA_PRICE_ID_PRO');
define('PRICE_PREMIUM',         'PEGA_PRICE_ID_PREMIUM');

// Cuotas (recurrente mensual · 3 ciclos)
// Importe = precio_total / 3  (ej. Básico: 120€/mes durante 3 meses)
define('PRICE_BASICO_CUOTAS',   'PEGA_PRICE_ID_BASICO_CUOTAS');
define('PRICE_PRO_CUOTAS',      'PEGA_PRICE_ID_PRO_CUOTAS');
define('PRICE_PREMIUM_CUOTAS',  'PEGA_PRICE_ID_PREMIUM_CUOTAS');

// Mantenimiento (recurrente mensual · sin límite)
define('PRICE_MANT_BASICO',     'PEGA_PRICE_ID_MANT_BASICO');
define('PRICE_MANT_PRO',        'PEGA_PRICE_ID_MANT_PRO');
define('PRICE_MANT_PREMIUM',    'PEGA_PRICE_ID_MANT_PREMIUM');

// ── Notificaciones ───────────────────────────────────
define('OWNER_EMAIL', 'contact@mastercodeweb.com');
