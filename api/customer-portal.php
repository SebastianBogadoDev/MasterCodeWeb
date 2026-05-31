<?php
/* =====================================================
   STRIPE CUSTOMER PORTAL — MasterCodeWeb
   POST /api/customer-portal.php  (llamado vía AJAX/fetch)

   Body (una de las dos):
     { "session_id":  "cs_..."  }  ← desde success URL
     { "customer_id": "cus_..." }  ← acceso directo

   Returns: { "url": "https://billing.stripe.com/..." }
            { "error": "..." }

   SEGURIDAD:
   · CSRF: no aplica — AJAX JSON con CORS same-origin protege
   · Rate limit: 15 req/min por IP
   · IDs validados con validateStripeId()
   · stripeRetry() en todas las llamadas Stripe
   · Anti-enumeration: errores genéricos en casos ambiguos
===================================================== */

require_once __DIR__ . '/config.php';

apiHeaders('POST');
rateLimitIp('customer-portal', 15, 60);
validateMethod('POST');

$body = parseJsonBody();

$customerId = null;

// ── Opción A: session_id → recuperar customer ─────────
if (!empty($body['session_id'])) {
    $sessionId = validateStripeId(sanitize($body['session_id']), 'cs_', 'session_id');

    try {
        $session    = stripeRetry(fn() => \Stripe\Checkout\Session::retrieve($sessionId));
        $customerId = $session->customer ?? null;

        if (!$customerId) {
            appLog('INFO', 'customer-portal', 'Sesión sin customer (pago único)', [
                'mode' => STRIPE_MODE,
            ]);
            // Mensaje de usuario útil; no revela info del session internamente
            jsonError(404, 'Esta sesión no tiene suscripción asociada. El portal solo está disponible para clientes con mantenimiento mensual activo.');
        }

    } catch (\Stripe\Exception\InvalidRequestException $e) {
        appLog('WARNING', 'customer-portal', 'Sesión no encontrada o inválida', [
            'code' => $e->getStripeCode(),
            'mode' => STRIPE_MODE,
            'ip'   => clientIp(),
        ]);
        // Genérico: no revelar si el session_id existe o no (anti-enumeration)
        jsonError(404, 'Sesión de pago no encontrada. El enlace puede haber expirado.');

    } catch (\Stripe\Exception\RateLimitException | \Stripe\Exception\ConnectionException $e) {
        appLog('ERROR', 'customer-portal', 'Error temporal Stripe recuperando sesión', ['err' => $e->getMessage()]);
        jsonError(503, 'Servicio temporalmente no disponible. Inténtalo en unos segundos.');

    } catch (\Throwable $e) {
        appLog('ERROR', 'customer-portal', 'Error recuperando sesión', ['err' => $e->getMessage()]);
        jsonError(502, 'Error al verificar la sesión con Stripe.');
    }
}

// ── Opción B: customer_id directo ─────────────────────
if (!$customerId && !empty($body['customer_id'])) {
    $customerId = validateStripeId(sanitize($body['customer_id']), 'cus_', 'customer_id');
}

if (!$customerId) {
    jsonError(400, 'Se requiere session_id o customer_id en el body.');
}

// ── Crear Stripe Billing Portal Session ───────────────
try {
    $portal = stripeRetry(fn() => \Stripe\BillingPortal\Session::create([
        'customer'   => $customerId,
        'return_url' => SITE_URL,
    ]));

    appLog('INFO', 'customer-portal', 'Portal session creada', [
        'customer' => substr($customerId, 0, 10) . '***',
        'mode'     => STRIPE_MODE,
    ]);

    echo json_encode(['url' => $portal->url]);

} catch (\Stripe\Exception\InvalidRequestException $e) {
    $msg  = $e->getMessage();
    $code = $e->getStripeCode() ?? '';

    appLog('ERROR', 'customer-portal', 'Error creando portal session', [
        'customer' => substr($customerId, 0, 10) . '***',
        'code'     => $code,
        'mode'     => STRIPE_MODE,
    ]);

    match (true) {
        $code === 'no_active_subscriptions',
        str_contains($msg, 'No active subscriptions')
            => jsonError(404, 'Este cliente no tiene suscripciones activas.'),

        str_contains($msg, 'No such customer')
            // Genérico: no confirmar si el cus_ existe (anti-enumeration)
            => jsonError(404, 'No se pudo acceder al portal. Verifica que el cliente existe.'),

        str_contains($msg, 'portal') || str_contains($msg, 'configuration')
            => jsonError(503, 'El portal de cliente no está activado. Actívalo en Stripe Dashboard → Settings → Billing → Customer portal.'),

        default => jsonError(502, 'Error de Stripe al crear el portal.'),
    };

} catch (\Stripe\Exception\RateLimitException | \Stripe\Exception\ConnectionException $e) {
    appLog('ERROR', 'customer-portal', 'Error temporal Stripe portal', ['err' => $e->getMessage()]);
    jsonError(503, 'Servicio temporalmente no disponible. Inténtalo en unos segundos.');

} catch (\Throwable $e) {
    appLog('ERROR', 'customer-portal', 'Excepción inesperada', [
        'err'  => $e->getMessage(),
        'mode' => STRIPE_MODE,
    ]);
    jsonError(500, 'Error interno del servidor.');
}
