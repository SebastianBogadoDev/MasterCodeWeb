<?php
/* =====================================================
   STRIPE CUSTOMER PORTAL — MasterCodeWeb
   POST /api/customer-portal.php
   Body: { "session_id": "cs_..." }   ← desde la success URL
      OR { "customer_id": "cus_..." } ← acceso directo
   Returns: { "url": "https://billing.stripe.com/..." }

   PRERREQUISITO: Activar Customer Portal en Stripe Dashboard
   Dashboard → Settings → Billing → Customer portal
   Activar las acciones que quieras permitir:
     · Cancel subscription
     · Update payment method
     · Download invoices
===================================================== */

@ini_set('display_errors', '0');
error_reporting(0);

$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    jsonError(503, 'Configuración no encontrada en el servidor.');
}
require_once $configPath;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    jsonError(503, 'Stripe SDK no encontrado.');
}
require_once $autoload;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . SITE_URL);
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError(405, 'Método no permitido.');
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!is_array($body)) {
    jsonError(400, 'JSON no válido en el body.');
}

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

$customerId = null;

// ── Opción A: session_id → recuperar customer ─────────
if (!empty($body['session_id'])) {
    $sessionId = trim((string)$body['session_id']);

    if (!preg_match('/^cs_(test_|live_)?[A-Za-z0-9_]+$/', $sessionId)) {
        jsonError(400, 'session_id no tiene el formato esperado.');
    }

    try {
        $session    = \Stripe\Checkout\Session::retrieve($sessionId);
        $customerId = $session->customer ?? null;

        if (!$customerId) {
            plog('INFO', 'Sesión sin customer — probablemente pago único', ['session' => $sessionId]);
            jsonError(404, 'Esta sesión no tiene suscripción asociada. El portal solo está disponible para clientes con mantenimiento mensual activo.');
        }
    } catch (\Stripe\Exception\InvalidRequestException $e) {
        plog('ERROR', 'Sesión no encontrada', ['session' => $sessionId, 'err' => $e->getMessage()]);
        jsonError(404, 'Sesión de pago no encontrada. El enlace puede haber expirado.');
    } catch (\Throwable $e) {
        plog('ERROR', 'Error recuperando sesión', ['err' => $e->getMessage()]);
        jsonError(502, 'Error al verificar la sesión con Stripe.');
    }
}

// ── Opción B: customer_id directo ─────────────────────
if (!$customerId && !empty($body['customer_id'])) {
    $cid = trim((string)$body['customer_id']);
    if (!preg_match('/^cus_[A-Za-z0-9]+$/', $cid)) {
        jsonError(400, 'customer_id no tiene el formato esperado.');
    }
    $customerId = $cid;
}

if (!$customerId) {
    jsonError(400, 'Se requiere session_id o customer_id en el body.');
}

// ── Crear Stripe Billing Portal Session ───────────────
try {
    $portal = \Stripe\BillingPortal\Session::create([
        'customer'   => $customerId,
        'return_url' => SITE_URL,
    ]);

    plog('INFO', 'Portal session creada', [
        'customer' => substr($customerId, 0, 10) . '***',
    ]);

    echo json_encode(['url' => $portal->url]);

} catch (\Stripe\Exception\InvalidRequestException $e) {
    $msg = $e->getMessage();
    plog('ERROR', 'Error creando portal session', [
        'customer' => substr($customerId, 0, 10) . '***',
        'err'      => $msg,
    ]);

    if (str_contains($msg, 'No active subscriptions')) {
        jsonError(404, 'Este cliente no tiene suscripciones activas en este momento.');
    }
    if (str_contains($msg, 'portal') || str_contains($msg, 'configuration')) {
        jsonError(503, 'El portal de cliente no está activado. Actívalo en Stripe Dashboard → Settings → Billing → Customer portal.');
    }

    jsonError(502, 'Error de Stripe al crear el portal.');

} catch (\Throwable $e) {
    plog('ERROR', 'Excepción inesperada en portal', ['err' => $e->getMessage()]);
    jsonError(500, 'Error interno del servidor.');
}


// ══════════════════════════════════════════════════════
//  HELPERS
// ══════════════════════════════════════════════════════

function jsonError(int $code, string $msg): never
{
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function plog(string $level, string $msg, array $ctx = []): void
{
    $dir  = __DIR__ . '/../logs';
    $file = $dir . '/stripe.log';

    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }

    $line = sprintf(
        "[%s] [%s] [portal] %s %s\n",
        date('Y-m-d H:i:s'),
        $level,
        $msg,
        $ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE) : ''
    );

    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}
