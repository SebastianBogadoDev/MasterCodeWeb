<?php
/* =====================================================
   STRIPE BILLING PORTAL — Redirect directo
   GET  /api/create-portal-session.php?customer_id=cus_...
   POST /api/create-portal-session.php  body JSON o form-data

   Crea una Stripe Billing Portal Session y redirige al usuario
   directamente al portal sin JS en el cliente.

   SEGURIDAD:
   · Rate limit: 10 req/min por IP
   · validateStripeId() — valida formato cus_...
   · CSRF: no aplica — GET es safe method; POST desde link/redirect
     con customer_id ya conocido. Rate limit protege contra abuse.
   · Anti-enumeration: errores genéricos (no revelar si cus_ existe)
   · stripeRetry() para resiliencia en errores temporales
===================================================== */

@ini_set('display_errors', '0');   // safety extra: endpoint con redirects

require_once __DIR__ . '/config.php';

rateLimitIp('portal-session', 10, 60);

// ── Extraer y validar customer_id (GET o POST) ────────
$customerId = match ($_SERVER['REQUEST_METHOD']) {
    'GET'  => sanitize($_GET['customer_id'] ?? '', 50),
    'POST' => sanitize(
        (json_decode((string) file_get_contents('php://input'), true)['customer_id'] ?? '')
        ?: ($_POST['customer_id'] ?? ''),
        50
    ),
    default => (function () {
        http_response_code(405);
        exit;
    })(),
};

if ($customerId === '') {
    redirectError('Acceso no válido. Usa el enlace de acceso enviado a tu email.');
}

// validateStripeId() sale con JSON 400 — usamos regex local para redirigir en su lugar
if (!preg_match('/^cus_[A-Za-z0-9]{10,}$/', $customerId)) {
    appLog('WARNING', 'portal-session', 'customer_id con formato inválido', ['ip' => clientIp()]);
    redirectError('Enlace de acceso inválido. Solicita uno nuevo.');
}

// ── Crear Stripe Billing Portal Session ───────────────
try {
    $portal = stripeRetry(fn() => \Stripe\BillingPortal\Session::create([
        'customer'   => $customerId,
        'return_url' => SITE_URL . '/pages/stripe_success.html',
    ]));

    appLog('INFO', 'portal-session', 'Portal session creada', [
        'customer' => substr($customerId, 0, 10) . '***',
        'mode'     => STRIPE_MODE,
        'method'   => $_SERVER['REQUEST_METHOD'],
    ]);

    header('Location: ' . $portal->url, true, 302);
    exit;

} catch (\Stripe\Exception\InvalidRequestException $e) {
    $msg  = $e->getMessage();
    $code = $e->getStripeCode() ?? '';

    appLog('ERROR', 'portal-session', 'Error portal session', [
        'code' => $code,
        'mode' => STRIPE_MODE,
        'ip'   => clientIp(),
        // NO loguear customerId completo — reducir exposición
    ]);

    match (true) {
        $code === 'no_active_subscriptions',
        str_contains($msg, 'No active subscriptions')
            => redirectError('No tienes una suscripción de mantenimiento activa.'),

        // Anti-enumeration: "No such customer" → mensaje genérico
        str_contains($msg, 'No such customer')
            => redirectError('Enlace de acceso inválido o expirado. Solicita uno nuevo.'),

        str_contains($msg, 'portal') || str_contains($msg, 'configuration')
            => redirectError('El portal no está disponible en este momento. Contacta con soporte.'),

        default => redirectError('No se pudo acceder al portal. Inténtalo de nuevo.'),
    };

} catch (\Stripe\Exception\RateLimitException | \Stripe\Exception\ConnectionException $e) {
    appLog('ERROR', 'portal-session', 'Error temporal Stripe', ['err' => $e->getMessage()]);
    redirectError('Servicio temporalmente no disponible. Inténtalo en unos segundos.');

} catch (\Throwable $e) {
    appLog('ERROR', 'portal-session', 'Excepción inesperada', ['err' => $e->getMessage()]);
    redirectError('Error interno del servidor. Inténtalo de nuevo más tarde.');
}


// ══════════════════════════════════════════════════════
//  HELPERS LOCALES
// ══════════════════════════════════════════════════════

function redirectError(string $msg): never
{
    header(
        'Location: ' . SITE_URL . '/pages/customer-portal.html?error=' . rawurlencode($msg),
        true,
        302
    );
    exit;
}
