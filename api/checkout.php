<?php
@ini_set('display_errors', '0');

// ══ 1. CONTENT-TYPE + CORS ════════════════════════════════════════
// Deben ser las primeras líneas ejecutadas — antes de cualquier exit.
header('Content-Type: application/json; charset=utf-8');
$reqOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . ($reqOrigin !== '' ? $reqOrigin : 'https://www.mastercodeweb.com'));
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');

// ── Preflight ──────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ══ 2. LOG HELPER PRE-BOOTSTRAP ═══════════════════════════════════
// appLog() no está disponible hasta que bootstrap cargue.
// Este helper escribe directamente al log antes de bootstrap.
function _coLog(string $step, array $ctx = []): void
{
    $dir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] [INFO] [checkout] ' . $step
          . ($ctx ? ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '') . "\n";
    @file_put_contents($dir . '/stripe.log', $line, FILE_APPEND | LOCK_EX);
}

_coLog('REQUEST_RECEIVED', [
    'method' => $_SERVER['REQUEST_METHOD'] ?? '?',
    'ip'     => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '?',
    'uri'    => $_SERVER['REQUEST_URI'] ?? '?',
]);

// ══ 3. BOOTSTRAP ══════════════════════════════════════════════════
$bootstrapPath = dirname(__DIR__) . '/config/bootstrap.php';

if (!file_exists($bootstrapPath)) {
    _coLog('BOOTSTRAP_NOT_FOUND', ['path' => $bootstrapPath]);
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'error'   => 'Configuración del servidor no disponible.',
        'step'    => 'BOOTSTRAP_NOT_FOUND',
    ]);
    exit;
}

try {
    require_once $bootstrapPath;
} catch (\Throwable $e) {
    _coLog('BOOTSTRAP_EXCEPTION', [
        'msg'   => $e->getMessage(),
        'class' => get_class($e),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
    ]);
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
        'step'    => 'BOOTSTRAP_EXCEPTION',
    ]);
    exit;
}

appLog('INFO', 'checkout', 'BOOTSTRAP_OK', ['mode' => STRIPE_MODE, 'env' => APP_ENV]);

// ══ 4. RATE LIMIT ═════════════════════════════════════════════════
rateLimitIp('checkout', 10, 60);

// ══ 5. MÉTODO ═════════════════════════════════════════════════════
validateMethod('POST');

// ══ 6. BODY ═══════════════════════════════════════════════════════
$body = parseJsonBody();
appLog('INFO', 'checkout', 'JSON_PARSED', ['keys' => array_keys($body)]);

requireFields($body, 'plan', 'tipo');

$plan           = sanitize($body['plan']);
$tipo           = sanitize($body['tipo']);
$addMaintenance = filter_var($body['addMaintenance'] ?? false, FILTER_VALIDATE_BOOLEAN);

// ══ 7. PLAN ═══════════════════════════════════════════════════════
$key = ($tipo === 'mensual') ? "mant-{$plan}" : $plan;
validatePlan($key);

appLog('INFO', 'checkout', 'PLAN_VALIDATED', [
    'key'  => $key,
    'plan' => $plan,
    'tipo' => $tipo,
]);

[$priceId, $mode] = PLANS[$key];

if (empty($priceId) || str_contains($priceId, 'PEGA_')) {
    appLog('ERROR', 'checkout', 'Price ID no configurado', ['key' => $key, 'mode' => STRIPE_MODE]);
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'error'   => "El plan '{$key}' no está disponible. Contacta por WhatsApp.",
    ]);
    exit;
}

appLog('INFO', 'checkout', 'STRIPE_INITIALIZED', [
    'mode'          => STRIPE_MODE,
    'price_prefix'  => substr($priceId, 0, 8),
    'checkout_mode' => $mode,
]);

// ══ 8. PARAMS ═════════════════════════════════════════════════════
$maintKeyMap = [
    'basico'      => 'mant-basico',
    'profesional' => 'mant-pro',
    'premium'     => 'mant-premium',
];

$successUrl = str_replace('{PLAN}', urlencode($key), SUCCESS_URL);
$withMaint  = ($mode === 'payment') && $addMaintenance && isset($maintKeyMap[$key]);

if ($withMaint) {
    $maintKey     = $maintKeyMap[$key];
    [$maintPrice] = PLANS[$maintKey];

    $params = [
        'payment_method_types' => ['card'],
        'line_items'           => [
            ['price' => $priceId,    'quantity' => 1],
            ['price' => $maintPrice, 'quantity' => 1],
        ],
        'mode'        => 'subscription',
        'success_url' => $successUrl . '&maint=1',
        'cancel_url'  => CANCEL_URL . '?plan=' . urlencode($key),
        'locale'      => 'es',
        'metadata'    => ['plan' => $key, 'maintenance' => $maintKey, 'environment' => STRIPE_MODE],
    ];
} else {
    $params = [
        'payment_method_types' => ['card'],
        'line_items'           => [['price' => $priceId, 'quantity' => 1]],
        'mode'                 => $mode,
        'success_url'          => $successUrl,
        'cancel_url'           => CANCEL_URL . '?plan=' . urlencode($key),
        'locale'               => 'es',
        'metadata'             => ['plan' => $key, 'environment' => STRIPE_MODE],
    ];
}

// ══ 9. CREAR SESIÓN STRIPE ════════════════════════════════════════
try {
    $session = stripeRetry(fn() => \Stripe\Checkout\Session::create($params));

    appLog('INFO', 'checkout', 'SESSION_CREATED', [
        'plan' => $key,
        'mode' => STRIPE_MODE,
        'ip'   => clientIp(),
    ]);

    appLog('INFO', 'checkout', 'RESPONSE_SENT', [
        'plan'       => $key,
        'url_prefix' => substr($session->url, 0, 50),
    ]);

    echo json_encode(['success' => true, 'url' => $session->url]);

} catch (\Stripe\Exception\ApiErrorException $e) {
    $err = $e->getError();
    appLog('ERROR', 'checkout', 'Stripe API error', [
        'plan' => $key,
        'err'  => $e->getMessage(),
        'code' => $err->code ?? null,
    ]);
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);

} catch (\Throwable $e) {
    appLog('ERROR', 'checkout', 'Error inesperado', [
        'err'  => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
}
