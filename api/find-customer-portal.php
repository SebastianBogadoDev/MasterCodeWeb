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
   · Logs sin PII completa (solo dominio del email)
   · Solo acepta método POST
===================================================== */

@ini_set('display_errors', '0');
error_reporting(0);

$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    redirectError('Error de configuración del servidor. Inténtalo de nuevo más tarde.');
}
require_once $configPath;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    redirectError('Error interno del servidor. Inténtalo de nuevo más tarde.');
}
require_once $autoload;

// ── Solo aceptar POST ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . SITE_URL . '/pages/acceso-cliente.html', true, 302);
    exit;
}

// ── Leer y validar email ──────────────────────────
$rawEmail = trim($_POST['email'] ?? '');

if ($rawEmail === '') {
    redirectError('Por favor, introduce tu email.');
}

// FILTER_VALIDATE_EMAIL rechaza emails malformados y null-bytes
$email = filter_var($rawEmail, FILTER_VALIDATE_EMAIL);
if ($email === false) {
    redirectError('El email introducido no es válido. Comprueba que está escrito correctamente.');
}

// Normalizar a minúsculas y verificar longitud (RFC 5321: máx 254 caracteres)
$email = strtolower($email);
if (strlen($email) > 254) {
    redirectError('El email introducido no es válido.');
}

// ── Buscar customer en Stripe por email ───────────
\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

try {
    $customers = \Stripe\Customer::all([
        'email' => $email,
        'limit' => 5,  // Por si el mismo email tiene varios customers (test + live, duplicados)
    ]);

    if (empty($customers->data)) {
        flog('INFO', 'Customer no encontrado por email', [
            'domain' => substr(strrchr($email, '@'), 1),
        ]);
        redirectError(
            'No encontramos ninguna cuenta asociada a ese email. ' .
            'Comprueba que es el mismo email con el que realizaste el pedido, ' .
            'o escríbenos por WhatsApp si crees que es un error.'
        );
    }

    // Usar el customer con suscripciones activas; si no, el más reciente (index 0)
    $customer = $customers->data[0];
    foreach ($customers->data as $c) {
        if (!empty($c->subscriptions->data)) {
            $customer = $c;
            break;
        }
    }

    flog('INFO', 'Customer encontrado, creando portal session', [
        'customer' => substr($customer->id, 0, 10) . '***',
    ]);

} catch (\Stripe\Exception\AuthenticationException $e) {
    flog('ERROR', 'Clave Stripe inválida', ['err' => $e->getMessage()]);
    redirectError('Error de configuración del servidor. Contacta con soporte.');

} catch (\Throwable $e) {
    flog('ERROR', 'Error buscando customer en Stripe', ['err' => $e->getMessage()]);
    redirectError('Error al buscar tu cuenta. Inténtalo de nuevo en unos segundos.');
}

// ── Crear Stripe Billing Portal Session y redirigir ──
try {
    $portal = \Stripe\BillingPortal\Session::create([
        'customer'   => $customer->id,
        'return_url' => SITE_URL . '/pages/cliente.html',
    ]);

    flog('INFO', 'Portal session creada, redirigiendo', [
        'customer' => substr($customer->id, 0, 10) . '***',
    ]);

    header('Location: ' . $portal->url, true, 302);
    exit;

} catch (\Stripe\Exception\InvalidRequestException $e) {
    $msg = $e->getMessage();
    flog('ERROR', 'Error creando portal session', ['err' => $msg]);

    if (str_contains($msg, 'No active subscriptions') || str_contains($msg, 'no_active_subscriptions')) {
        redirectError(
            'Tu email está registrado, pero no encontramos una suscripción de mantenimiento activa. ' .
            'Si contrataste mantenimiento y ves este error, contáctanos y lo resolvemos en minutos.'
        );
    }

    if (str_contains($msg, 'portal') || str_contains($msg, 'configuration')) {
        flog('ERROR', 'Portal no configurado en Stripe Dashboard → Settings → Billing → Customer portal');
        redirectError('El portal de cliente no está disponible en este momento. Contacta con soporte.');
    }

    redirectError('Error al crear el acceso al portal. Inténtalo de nuevo o contacta con soporte.');

} catch (\Throwable $e) {
    flog('ERROR', 'Excepción inesperada creando portal session', ['err' => $e->getMessage()]);
    redirectError('Error interno del servidor. Inténtalo de nuevo más tarde.');
}


// ══════════════════════════════════════════════════════
//  HELPERS
// ══════════════════════════════════════════════════════

function redirectError(string $msg): never
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
