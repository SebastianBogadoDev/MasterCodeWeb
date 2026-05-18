<?php
/* =====================================================
   STRIPE CHECKOUT SESSION CREATOR — MasterCodeWeb
   POST /api/checkout.php
   Body: { "plan": "basico|profesional|premium", "tipo": "unico|mensual", "addMaintenance": bool }
   Returns: { "url": "https://checkout.stripe.com/..." }
            { "error": "mensaje de error" }
===================================================== */

// ── Cargar config ─────────────────────────────────────
$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Archivo de configuración no encontrado en el servidor. Sube api/config.php manualmente.']);
    exit;
}
require_once $configPath;

// ── Cargar Stripe SDK ─────────────────────────────────
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Stripe SDK no encontrado. vendor/autoload.php ausente.']);
    exit;
}
require_once $autoload;

// ── Validar que la secret key está configurada ────────
if (!defined('STRIPE_SECRET_KEY') || str_starts_with(STRIPE_SECRET_KEY, 'PEGA_')) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'STRIPE_SECRET_KEY no configurada en config.php.']);
    exit;
}

// ── Headers ───────────────────────────────────────────
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
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido.']);
    exit;
}

// ── Plan catalog ──────────────────────────────────────
// key → [price_id, mode]
// mode: 'payment' = pago único  |  'subscription' = mantenimiento mensual
const PLANS = [
    'basico'        => [PRICE_BASICO,       'payment'],
    'profesional'   => [PRICE_PRO,          'payment'],
    'premium'       => [PRICE_PREMIUM,      'payment'],
    'mant-basico'   => [PRICE_MANT_BASICO,  'subscription'],
    'mant-pro'      => [PRICE_MANT_PRO,     'subscription'],
    'mant-premium'  => [PRICE_MANT_PREMIUM, 'subscription'],
];

// ── Parse input ───────────────────────────────────────
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'JSON no válido en el body.']);
    exit;
}

$plan           = trim($body['plan'] ?? '');
$tipo           = trim($body['tipo'] ?? '');
$addMaintenance = filter_var($body['addMaintenance'] ?? false, FILTER_VALIDATE_BOOLEAN);

// Build plan key
$key = ($tipo === 'mensual') ? "mant-$plan" : $plan;

if (!isset(PLANS[$key])) {
    http_response_code(400);
    echo json_encode(['error' => "Plan no reconocido: '$key'. Válidos: " . implode(', ', array_keys(PLANS))]);
    exit;
}

[$priceId, $mode] = PLANS[$key];

// Verificar que el Price ID no es un placeholder
if (str_starts_with($priceId, 'PEGA_')) {
    http_response_code(503);
    echo json_encode(['error' => "Price ID para '$key' no configurado en config.php."]);
    exit;
}

// Mapa plan base → clave de mantenimiento
$maintKeyMap = [
    'basico'      => 'mant-basico',
    'profesional' => 'mant-pro',
    'premium'     => 'mant-premium',
];

// ── Create Stripe Checkout Session ────────────────────
\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

$successUrl = str_replace('{PLAN}', urlencode($key), SUCCESS_URL);

// ¿Carrito mixto? plan único + mantenimiento en una sola sesión
$withMaint = ($mode === 'payment') && $addMaintenance && isset($maintKeyMap[$key]);

if ($withMaint) {
    $maintKey     = $maintKeyMap[$key];
    [$maintPrice] = PLANS[$maintKey];
    if (str_starts_with($maintPrice, 'PEGA_')) {
        http_response_code(503);
        echo json_encode(['error' => "Price ID de mantenimiento '$maintKey' no configurado en config.php."]);
        exit;
    }
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
        'metadata'    => ['plan' => $key, 'maintenance' => $maintKey],
    ];
} else {
    $params = [
        'payment_method_types' => ['card'],
        'line_items'           => [['price' => $priceId, 'quantity' => 1]],
        'mode'                 => $mode,
        'success_url'          => $successUrl,
        'cancel_url'           => CANCEL_URL . '?plan=' . urlencode($key),
        'locale'               => 'es',
        'metadata'             => ['plan' => $key],
    ];
}

try {
    $session = \Stripe\Checkout\Session::create($params);
    echo json_encode(['url' => $session->url]);
} catch (\Stripe\Exception\ApiErrorException $e) {
    http_response_code(502);
    $stripeErr = $e->getError();
    echo json_encode([
        'error'   => $e->getMessage(),
        'code'    => $stripeErr->code    ?? null,
        'param'   => $stripeErr->param   ?? null,
        'type'    => $stripeErr->type    ?? null,
    ]);
    error_log('[MCW-Stripe] checkout error: ' . $e->getMessage());
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    error_log('[MCW] checkout fatal: ' . $e->getMessage());
}
