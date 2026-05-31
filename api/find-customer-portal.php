<?php
/* =====================================================
   FIND CUSTOMER PORTAL — MasterCodeWeb
   POST /api/find-customer-portal.php (formulario HTML)

   Flujo:
   1. Rate limit por IP (5 req/min)
   2. Turnstile server-side (si está configurado)
   3. Validar + sanitizar email
   4. Buscar customer en Stripe por email
   5. Priorizar customer con suscripción activa
   6. Crear Billing Portal Session → redirect

   SEGURIDAD:
   · Rate limiting estricto (5/min por IP)
   · Turnstile anti-bot (fail-open si CF no responde)
   · Email normalizado, nunca IDs Stripe expuestos en URL
   · Logs estructurados sin datos sensibles
===================================================== */

@ini_set('display_errors', '0');   // safety extra para endpoint con redirects

require_once __DIR__ . '/config.php';

// ── Método HTTP ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . SITE_URL . '/pages/acceso-cliente.html', true, 302);
    exit;
}

// ── Rate limiting: 5 intentos/min por IP ─────────────
// Más estricto que otros endpoints — lookup por email es sensible
rateLimitIp('portal-find', 5, 60);

// ── Turnstile anti-bot ────────────────────────────────
verifyTurnstile();

// ── Validar email ─────────────────────────────────────
$rawEmail = sanitize($_POST['email'] ?? '', 254);

if ($rawEmail === '') {
    redirectError('Por favor, introduce tu email.');
}

if (!filter_var($rawEmail, FILTER_VALIDATE_EMAIL)) {
    redirectError('El email no es válido. Comprueba que está escrito correctamente.');
}

$email     = strtolower($rawEmail);
$modeLabel = STRIPE_MODE === 'test' ? 'TEST' : 'LIVE';

appLog('INFO', 'portal-find', 'Búsqueda iniciada', [
    'domain' => substr(strrchr($email, '@'), 1),
    'mode'   => $modeLabel,
    'ip'     => clientIp(),
]);

// ── Buscar customer por email ─────────────────────────
try {
    $response = stripeRetry(fn() => \Stripe\Customer::all([
        'email'  => $email,
        'limit'  => 5,
        'expand' => ['data.subscriptions'],
    ]));

    $list = $response->data;

    if (empty($list)) {
        appLog('INFO', 'portal-find', 'Customer no encontrado', [
            'domain' => substr(strrchr($email, '@'), 1),
            'mode'   => $modeLabel,
        ]);

        redirectError(
            STRIPE_MODE === 'test'
                ? 'No encontramos tu cuenta (modo TEST). Si realizaste un pago real, contacta por WhatsApp.'
                : 'No encontramos ninguna cuenta con ese email. Comprueba que es el mismo con el que realizaste el pedido.'
        );
    }

    // Priorizar customer con suscripción activa
    $customer = $list[0];
    foreach ($list as $c) {
        $subsData = $c->subscriptions->data ?? [];
        if (!empty($subsData)) {
            $customer = $c;
            break;
        }
    }

    appLog('INFO', 'portal-find', 'Customer seleccionado', [
        'customer' => substr($customer->id, 0, 12) . '***',
        'mode'     => $modeLabel,
    ]);

} catch (\Stripe\Exception\AuthenticationException $e) {
    appLog('ERROR', 'portal-find', 'Auth Stripe fallida', [
        'err'  => $e->getMessage(),
        'mode' => $modeLabel,
    ]);
    redirectError('Error de autenticación con Stripe. Contacta con soporte.');

} catch (\Throwable $e) {
    appLog('ERROR', 'portal-find', 'Error buscando customer', [
        'err'  => $e->getMessage(),
        'mode' => $modeLabel,
    ]);
    redirectError('Error al buscar tu cuenta. Inténtalo de nuevo en unos segundos.');
}

// ── Crear Billing Portal Session ──────────────────────
try {
    $portal = stripeRetry(fn() => \Stripe\BillingPortal\Session::create([
        'customer'   => $customer->id,
        'return_url' => SITE_URL . '/pages/cliente.html',
    ]));

    appLog('INFO', 'portal-find', 'Portal session creada', [
        'customer' => substr($customer->id, 0, 10) . '***',
        'mode'     => $modeLabel,
    ]);

    header('Location: ' . $portal->url, true, 302);
    exit;

} catch (\Stripe\Exception\InvalidRequestException $e) {
    $msg  = $e->getMessage();
    $code = $e->getStripeCode() ?? '';

    appLog('ERROR', 'portal-find', 'Error creando portal session', [
        'err'  => $msg,
        'code' => $code,
        'mode' => $modeLabel,
    ]);

    if ($code === 'no_active_subscriptions'
        || str_contains($msg, 'No active subscriptions')
    ) {
        redirectError('No tienes una suscripción de mantenimiento activa. Si crees que es un error, escríbenos por WhatsApp.');
    }

    if (str_contains($msg, 'No configuration') || str_contains($msg, 'configuration')) {
        $configId = ensurePortalConfiguration();

        if ($configId !== null) {
            try {
                $portal = \Stripe\BillingPortal\Session::create([
                    'customer'      => $customer->id,
                    'return_url'    => SITE_URL . '/pages/cliente.html',
                    'configuration' => $configId,
                ]);
                appLog('INFO', 'portal-find', 'Portal creado con config auto', ['config' => $configId]);
                header('Location: ' . $portal->url, true, 302);
                exit;
            } catch (\Throwable $e2) {
                appLog('ERROR', 'portal-find', 'Reintento con config falló', ['err' => $e2->getMessage()]);
            }
        }

        redirectError('El portal no está disponible ahora. Escríbenos por WhatsApp y te enviamos el enlace.');
    }

    if (str_contains($msg, 'No such customer')) {
        redirectError('No encontramos tu cuenta en Stripe. Contacta con soporte.');
    }

    redirectError('Error al acceder al portal. Inténtalo de nuevo o escríbenos por WhatsApp.');

} catch (\Throwable $e) {
    appLog('ERROR', 'portal-find', 'Excepción inesperada creando portal', ['err' => $e->getMessage()]);
    redirectError('Error interno del servidor. Inténtalo de nuevo más tarde.');
}


// ══════════════════════════════════════════════════════
//  HELPERS LOCALES
// ══════════════════════════════════════════════════════

/**
 * Verifica Cloudflare Turnstile server-side.
 * Fail-open si Cloudflare no responde (no bloqueamos al usuario).
 * No actúa si TURNSTILE_SECRET no está configurado.
 */
function verifyTurnstile(): void
{
    $secret = TURNSTILE_SECRET;
    if ($secret === '' || $secret === 'REPLACE_WITH_YOUR_TURNSTILE_SECRET') {
        return; // sin configurar → skip (modo fail-open)
    }

    $token = trim($_POST['cf-turnstile-response'] ?? '');
    if ($token === '') {
        appLog('WARNING', 'portal-find', 'Turnstile token vacío', ['ip' => clientIp()]);
        redirectError('Verificación de seguridad no completada. Recarga la página e inténtalo de nuevo.');
    }

    $ctx    = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query(['secret' => $secret, 'response' => $token, 'remoteip' => clientIp()]),
        'timeout' => 5,
    ]]);
    $result = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $ctx);

    if ($result === false) return; // CF no responde → fail-open

    $data = json_decode($result, true) ?? [];
    if (empty($data['success'])) {
        appLog('WARNING', 'portal-find', 'Turnstile fallido', [
            'codes' => $data['error-codes'] ?? [],
            'ip'    => clientIp(),
        ]);
        redirectError('Verificación de seguridad fallida. Recarga la página e inténtalo de nuevo.');
    }
}

/**
 * Obtiene o crea la configuración del Billing Portal automáticamente.
 * Devuelve el ID de configuración o null si falla.
 */
function ensurePortalConfiguration(): ?string
{
    try {
        $configs = \Stripe\BillingPortal\Configuration::all(['limit' => 1]);

        if (!empty($configs->data)) {
            return $configs->data[0]->id;
        }

        $config = \Stripe\BillingPortal\Configuration::create([
            'business_profile' => [
                'headline'             => 'MasterCodeWeb — Gestión de suscripción',
                'privacy_policy_url'   => SITE_URL . '/legal/politica_privacidad.html',
                'terms_of_service_url' => SITE_URL . '/legal/aviso_legal.html',
            ],
            'features' => [
                'payment_method_update' => ['enabled' => true],
                'subscription_cancel'   => [
                    'enabled'            => true,
                    'mode'               => 'at_period_end',
                    'proration_behavior' => 'none',
                ],
                'invoice_history' => ['enabled' => true],
            ],
        ]);

        appLog('INFO', 'portal-find', 'Portal configuration creada automáticamente', ['id' => $config->id]);
        return $config->id;

    } catch (\Throwable $e) {
        appLog('ERROR', 'portal-find', 'No se pudo crear portal config', ['err' => $e->getMessage()]);
        return null;
    }
}

function redirectError(string $msg): never
{
    header(
        'Location: ' . SITE_URL . '/pages/acceso-cliente.html?error=' . rawurlencode($msg),
        true,
        302
    );
    exit;
}
