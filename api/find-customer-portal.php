<?php
/* =====================================================
   FIND CUSTOMER PORTAL — MasterCodeWeb
   POST /api/find-customer-portal.php

   Flujo:
   1. Recibe email via formulario POST
   2. Valida y sanitiza el email
   3. Busca el customer en Stripe por email
   4. Si existe → crea Billing Portal Session → redirect
   5. Si no existe → redirige con mensaje de error

   SEGURIDAD:
   · Validación estricta con FILTER_VALIDATE_EMAIL
   · Email normalizado a minúsculas, longitud limitada
   · IDs de Stripe nunca expuestos en la URL
   · Solo acepta método POST
   · Logs en /logs/stripe.log
===================================================== */

// Suprimir output de errores PHP al cliente, activar log
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

// ── Cargar config ─────────────────────────────────
$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    error_log('[find-customer-portal] FATAL: config.php no encontrado en ' . $configPath);
    redirectError('Error de configuración del servidor. Contacta con soporte.');
}
require_once $configPath;

// ── Cargar Stripe SDK ─────────────────────────────
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    error_log('[find-customer-portal] FATAL: vendor/autoload.php no encontrado en ' . $autoload);
    redirectError('Error interno del servidor. Inténtalo de nuevo más tarde.');
}
require_once $autoload;

// ── Solo POST ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $base = defined('SITE_URL') ? SITE_URL : 'https://www.mastercodeweb.com';
    header('Location: ' . $base . '/pages/acceso-cliente.html', true, 302);
    exit;
}

// ── Validar email ─────────────────────────────────
$rawEmail = trim($_POST['email'] ?? '');

if ($rawEmail === '') {
    redirectError('Por favor, introduce tu email.');
}

$email = filter_var($rawEmail, FILTER_VALIDATE_EMAIL);
if ($email === false) {
    redirectError('El email no es válido. Comprueba que está escrito correctamente.');
}

$email = strtolower($email);

if (strlen($email) > 254) {
    redirectError('El email introducido no es válido.');
}

// Detectar modo test/live para logs
$isTestMode = (strpos(STRIPE_SECRET_KEY, 'sk_test_') === 0);
$modeLabel  = $isTestMode ? 'TEST' : 'LIVE';

error_log('[find-customer-portal] Inicio. Modo=' . $modeLabel . ' Email-domain=' . substr(strrchr($email, '@'), 1));

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

// ── Buscar customer por email ─────────────────────
try {
    // Expandir subscriptions para evitar null dereference posterior
    $response  = \Stripe\Customer::all([
        'email'  => $email,
        'limit'  => 5,
        'expand' => ['data.subscriptions'],
    ]);

    $list = $response->data;

    error_log('[find-customer-portal] Customers encontrados: ' . count($list) . ' (modo ' . $modeLabel . ')');

    if (empty($list)) {
        flog('INFO', 'Customer no encontrado', [
            'domain' => substr(strrchr($email, '@'), 1),
            'mode'   => $modeLabel,
        ]);

        if ($isTestMode) {
            // Aviso especial: probablemente el email existe en LIVE y aquí buscamos en TEST
            redirectError(
                'No encontramos tu cuenta. ' .
                'Nota: el servidor está en modo TEST. Si realizaste un pago real, ' .
                'contacta con soporte por WhatsApp para que lo gestionemos directamente.'
            );
        }

        redirectError(
            'No encontramos ninguna cuenta asociada a ese email. ' .
            'Comprueba que es el mismo con el que realizaste el pedido, ' .
            'o escríbenos por WhatsApp si crees que es un error.'
        );
    }

    // Priorizar customer con suscripción activa
    $customer = $list[0];
    foreach ($list as $c) {
        // Acceso null-safe: subscriptions puede ser null si el expand falló
        $subs = isset($c->subscriptions) ? $c->subscriptions : null;
        $subsData = ($subs !== null && isset($subs->data)) ? $subs->data : [];
        if (!empty($subsData)) {
            $customer = $c;
            error_log('[find-customer-portal] Customer con suscripción seleccionado: ' . substr($c->id, 0, 12) . '***');
            break;
        }
    }

    error_log('[find-customer-portal] Customer final: ' . substr($customer->id, 0, 12) . '***');

} catch (\Stripe\Exception\AuthenticationException $e) {
    error_log('[find-customer-portal] AuthenticationException: ' . $e->getMessage());
    flog('ERROR', 'Clave Stripe inválida o revocada', ['err' => $e->getMessage(), 'mode' => $modeLabel]);
    redirectError('Error de autenticación con Stripe. Contacta con soporte.');

} catch (\Throwable $e) {
    error_log('[find-customer-portal] Throwable buscando customer: ' . get_class($e) . ': ' . $e->getMessage());
    flog('ERROR', 'Error buscando customer', ['err' => $e->getMessage(), 'mode' => $modeLabel]);
    redirectError('Error al buscar tu cuenta. Inténtalo de nuevo en unos segundos.');
}

// ── Crear Stripe Billing Portal Session ──────────
try {
    error_log('[find-customer-portal] Creando BillingPortal\Session para ' . substr($customer->id, 0, 12) . '***');

    $portal = \Stripe\BillingPortal\Session::create([
        'customer'   => $customer->id,
        'return_url' => SITE_URL . '/pages/cliente.html',
    ]);

    flog('INFO', 'Portal session creada, redirigiendo', [
        'customer' => substr($customer->id, 0, 10) . '***',
        'mode'     => $modeLabel,
    ]);

    error_log('[find-customer-portal] Redirect a portal Stripe OK');

    header('Location: ' . $portal->url, true, 302);
    exit;

} catch (\Stripe\Exception\InvalidRequestException $e) {
    $msg = $e->getMessage();
    $code = $e->getStripeCode() ?? '';
    error_log('[find-customer-portal] InvalidRequestException portal: code=' . $code . ' msg=' . $msg);
    flog('ERROR', 'Error creando portal session', [
        'err'  => $msg,
        'code' => $code,
        'mode' => $modeLabel,
    ]);

    // El customer no tiene suscripciones activas
    if (strpos($msg, 'No active subscriptions') !== false
        || strpos($msg, 'no_active_subscriptions') !== false
        || $code === 'no_active_subscriptions'
    ) {
        redirectError(
            'No tienes una suscripción de mantenimiento activa. ' .
            'Si contrataste mantenimiento y ves este error, escríbenos por WhatsApp y lo resolvemos.'
        );
    }

    // Portal no configurado → intentar auto-configurar
    if (strpos($msg, 'No configuration') !== false
        || strpos($msg, 'portal') !== false
        || strpos($msg, 'configuration') !== false
    ) {
        error_log('[find-customer-portal] Portal no configurado. Intentando auto-configurar...');

        $configId = ensurePortalConfiguration();

        if ($configId !== null) {
            // Reintentar con la configuración creada
            try {
                $portal = \Stripe\BillingPortal\Session::create([
                    'customer'      => $customer->id,
                    'return_url'    => SITE_URL . '/pages/cliente.html',
                    'configuration' => $configId,
                ]);

                error_log('[find-customer-portal] Reintento con config OK: ' . $configId);
                flog('INFO', 'Portal session creada (reintento con config)', [
                    'customer' => substr($customer->id, 0, 10) . '***',
                    'config'   => $configId,
                ]);

                header('Location: ' . $portal->url, true, 302);
                exit;

            } catch (\Throwable $e2) {
                error_log('[find-customer-portal] Reintento también falló: ' . $e2->getMessage());
            }
        }

        redirectError(
            'El portal de cliente no está disponible en este momento. ' .
            'Escríbenos por WhatsApp y te enviamos el enlace directamente.'
        );
    }

    // Customer eliminado en Stripe
    if (strpos($msg, 'No such customer') !== false) {
        redirectError('No encontramos tu cuenta en Stripe. Contacta con soporte.');
    }

    redirectError('Error al acceder al portal. Inténtalo de nuevo o escríbenos por WhatsApp.');

} catch (\Throwable $e) {
    error_log('[find-customer-portal] Throwable creando portal: ' . get_class($e) . ': ' . $e->getMessage());
    flog('ERROR', 'Excepción inesperada creando portal session', ['err' => $e->getMessage()]);
    redirectError('Error interno del servidor. Inténtalo de nuevo más tarde.');
}


// ══════════════════════════════════════════════════════
//  HELPERS
// ══════════════════════════════════════════════════════

/**
 * Si no existe ninguna configuración de portal, crea una minimal
 * con las opciones básicas (cancelar, cambiar tarjeta, facturas).
 * Devuelve el ID de la configuración o null si falla.
 */
function ensurePortalConfiguration(): ?string
{
    try {
        $configs = \Stripe\BillingPortal\Configuration::all(['limit' => 1]);

        if (!empty($configs->data)) {
            $id = $configs->data[0]->id;
            error_log('[find-customer-portal] Portal config existente: ' . $id);
            return $id;
        }

        // Crear configuración básica automáticamente
        $config = \Stripe\BillingPortal\Configuration::create([
            'business_profile' => [
                'headline'    => 'MasterCodeWeb — Gestión de suscripción',
                'privacy_policy_url' => SITE_URL . '/legal/politica_privacidad.html',
                'terms_of_service_url' => SITE_URL . '/legal/aviso_legal.html',
            ],
            'features' => [
                'payment_method_update' => ['enabled' => true],
                'subscription_cancel'   => [
                    'enabled'    => true,
                    'mode'       => 'at_period_end',
                    'proration_behavior' => 'none',
                ],
                'invoice_history'       => ['enabled' => true],
            ],
        ]);

        error_log('[find-customer-portal] Portal config creada automáticamente: ' . $config->id);
        flog('INFO', 'Portal configuration creada automáticamente', ['id' => $config->id]);
        return $config->id;

    } catch (\Throwable $e) {
        error_log('[find-customer-portal] No se pudo crear portal config: ' . $e->getMessage());
        return null;
    }
}

function redirectError(string $msg): void
{
    $base = defined('SITE_URL') ? SITE_URL : 'https://www.mastercodeweb.com';
    header('Location: ' . $base . '/pages/acceso-cliente.html?error=' . rawurlencode($msg), true, 302);
    exit;
}

function flog(string $level, string $msg, array $ctx = []): void
{
    $dir  = __DIR__ . '/../logs';
    $file = $dir . '/stripe.log';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $line = sprintf(
        "[%s] [%s] [find-customer-portal] %s %s\n",
        date('Y-m-d H:i:s'),
        $level,
        $msg,
        $ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE) : ''
    );
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}
