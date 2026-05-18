<?php
/* =====================================================
   STRIPE BILLING PORTAL — Redirect directo
   GET  /api/create-portal-session.php?customer_id=cus_...
   POST /api/create-portal-session.php  body: { "customer_id": "cus_..." }

   Crea una Stripe Billing Portal Session y redirige al usuario
   directamente al portal sin necesidad de JavaScript en el cliente.

   PRERREQUISITO: Customer Portal activado en Stripe Dashboard
   Settings → Billing → Customer portal
===================================================== */

@ini_set('display_errors', '0');
error_reporting(0);

$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    redirectError('Configuración no encontrada en el servidor.');
}
require_once $configPath;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    redirectError('Stripe SDK no encontrado.');
}
require_once $autoload;

// ── Extraer customer_id (GET o POST) ─────────────────
$customerId = null;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // GET: ?customer_id=cus_...
    $customerId = trim($_GET['customer_id'] ?? '');
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // POST: body JSON { "customer_id": "cus_..." }
    $raw  = file_get_contents('php://input');
    $body = @json_decode($raw, true);
    $customerId = trim($body['customer_id'] ?? ($_POST['customer_id'] ?? ''));
} else {
    http_response_code(405);
    exit('Método no permitido.');
}

// ── Validar formato customer_id ────────────────────────
if (!$customerId || !preg_match('/^cus_[A-Za-z0-9]+$/', $customerId)) {
    redirectError('customer_id inválido o ausente. Formato esperado: cus_...');
}

// ── Crear Stripe Billing Portal Session ───────────────
\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

try {
    $portal = \Stripe\BillingPortal\Session::create([
        'customer'   => $customerId,
        'return_url' => SITE_URL . '/pages/stripe_success.html',
    ]);

    plog('INFO', 'Portal session creada (redirect)', [
        'customer' => substr($customerId, 0, 10) . '***',
    ]);

    // Redirige directamente al portal de Stripe
    header('Location: ' . $portal->url, true, 302);
    exit;

} catch (\Stripe\Exception\InvalidRequestException $e) {
    $msg = $e->getMessage();
    plog('ERROR', 'Error portal session', ['err' => $msg]);

    if (str_contains($msg, 'No active subscriptions')) {
        redirectError('No hay suscripciones activas para este cliente.');
    }
    if (str_contains($msg, 'portal') || str_contains($msg, 'configuration')) {
        redirectError('El portal de cliente no está activado en Stripe Dashboard → Settings → Billing → Customer portal.');
    }
    redirectError('Error de Stripe al crear el portal.');

} catch (\Throwable $e) {
    plog('ERROR', 'Excepción inesperada', ['err' => $e->getMessage()]);
    redirectError('Error interno del servidor.');
}


// ══════════════════════════════════════════════════════
//  HELPERS
// ══════════════════════════════════════════════════════

/**
 * Redirige a customer-portal.html con mensaje de error en la URL.
 * Así el usuario ve la UI de error en vez de una pantalla en blanco.
 */
function redirectError(string $msg): never
{
    $base = defined('SITE_URL') ? SITE_URL : 'https://www.mastercodeweb.com';
    header('Location: ' . $base . '/pages/customer-portal.html?error=' . rawurlencode($msg), true, 302);
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
        "[%s] [%s] [create-portal-session] %s %s\n",
        date('Y-m-d H:i:s'),
        $level,
        $msg,
        $ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE) : ''
    );
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}
