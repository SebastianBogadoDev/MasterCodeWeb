# Stripe — Documentación técnica

## Planes configurados

| Clave interna | Tipo | Precio | Mode Stripe | Price ID |
|---------------|------|--------|------------|----------|
| `basico` | Pago único | 349€ | `payment` | `PRICE_BASICO` (en .env) |
| `profesional` | Pago único | 599€ | `payment` | `PRICE_PROFESIONAL` |
| `premium` | Pago único | 999€ | `payment` | `PRICE_PREMIUM` |
| `mant-basico` | Suscripción mensual | ~29€ | `subscription` | `PRICE_MANT_BASICO` |
| `mant-pro` | Suscripción mensual | ~49€ | `subscription` | `PRICE_MANT_PRO` |
| `mant-premium` | Suscripción mensual | ~79€ | `subscription` | `PRICE_MANT_PREMIUM` |

**Nota:** Los Price IDs reales están en `.env` como `PRICE_*`. Nunca hardcodeados en PHP.

## Flujo de checkout

```
1. Usuario hace clic en "Contratar plan X" en precios.html o checkout-X.html
2. js/02-modules/payments.js → POST /api/create-checkout.php
   Body: { plan, tipo, addMaintenance }
3. create-checkout.php:
   a. rateLimitIp('checkout', 10, 60) — máx 10 intentos/min por IP
   b. validatePlan($key) — whitelist de claves válidas
   c. Si addMaintenance=true + plan de pago único → mode="subscription" con 2 line_items
   d. Stripe\Checkout\Session::create() con locale='es', cancel_url, success_url
   e. Devuelve { url: "https://checkout.stripe.com/..." }
4. Browser redirige a Stripe Hosted Checkout
5. Usuario completa el pago en Stripe
6. Stripe redirige a /pages/success.html?plan=X
7. Stripe envía webhook a /api/webhook.php
```

## Webhook (`api/webhook.php`)

- Verifica firma HMAC-SHA256 con `STRIPE_WEBHOOK_SECRET`
- Procesa: `checkout.session.completed`, `customer.subscription.updated`, `customer.subscription.deleted`
- Acciones: appLog(), Resend email bienvenida, actualizar estado de suscripción
- **Idempotente:** los reintentos de Stripe son seguros

## Customer Portal

El área de cliente usa el Stripe Customer Portal (gestión de suscripciones, facturas, tarjeta):
- `api/find-customer-portal.php` — busca customer por email en Stripe
- `api/customer-portal.php` — genera URL de Customer Portal
- `api/create-portal-session.php` — crea sesión

**Nota:** Las páginas `pages/acceso-cliente.html` y `pages/cliente.html` son el frontend de este portal. El flujo autentica por email (sin contraseña propia).

## Variables de entorno requeridas

```
STRIPE_SECRET_KEY=sk_live_...       # Clave secreta Stripe
STRIPE_WEBHOOK_SECRET=whsec_...     # Secreto webhook Stripe
STRIPE_MODE=live                    # "live" o "test"
PRICE_BASICO=price_...
PRICE_PROFESIONAL=price_...
PRICE_PREMIUM=price_...
PRICE_MANT_BASICO=price_...
PRICE_MANT_PRO=price_...
PRICE_MANT_PREMIUM=price_...
SUCCESS_URL=https://www.mastercodeweb.com/pages/success.html?plan={PLAN}
CANCEL_URL=https://www.mastercodeweb.com/pages/cancel.html
```

## Testing

- En desarrollo: usar `STRIPE_SECRET_KEY=sk_test_...` + `STRIPE_MODE=test`
- Para el webhook local: `stripe listen --forward-to localhost/api/webhook.php`
- Las páginas `checkout-*.html` en `/pages/` son las páginas de checkout propias (alternativas a Stripe Hosted)

## Procedimiento de recuperación si Stripe falla

1. **Checkout no funciona:** Verificar `STRIPE_SECRET_KEY` en `.env` + logs en `storage/logs/stripe.log`
2. **Webhook no llega:** Verificar en Stripe Dashboard → Webhooks → Failed events → reintentar manualmente
3. **Price ID no configurado:** Crear el precio en Stripe Dashboard → copiar ID → actualizar `.env`
4. **Error 502 en checkout:** Verificar que la versión de la librería Stripe en `vendor/` es compatible con la API version configurada en el Dashboard
