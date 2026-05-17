<?php
/* =====================================================
   STRIPE CHECKOUT SESSION CREATOR — MasterCodeWeb
   POST /api/checkout.php
   Body: { "plan": "basico|profesional|premium", "tipo": "unico|cuotas|mensual" }
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
// key → [price_id, mode, max_cycles (0 = sin límite)]
const PLANS = [
    'basico'             => [PRICE_BASICO,          'payment',      0],
    'profesional'        => [PRICE_PRO,              'payment',      0],
    'premium'            => [PRICE_PREMIUM,          'payment',      0],
    'basico-cuotas'      => [PRICE_BASICO_CUOTAS,    'subscription', 3],
    'profesional-cuotas' => [PRICE_PRO_CUOTAS,       'subscription', 3],
    'premium-cuotas'     => [PRICE_PREMIUM_CUOTAS,   'subscription', 3],
    'mant-basico'        => [PRICE_MANT_BASICO,      'subscription', 0],
    'mant-profesional'   => [PRICE_MANT_PRO,         'subscription', 0],
    'mant-premium'       => [PRICE_MANT_PREMIUM,     'subscription', 0],
];

// ── Parse input ───────────────────────────────────────
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'JSON no válido en el body.']);
    exit;
}

$plan = trim($body['plan'] ?? '');
$tipo = trim($body['tipo'] ?? '');

// Build plan key
if ($tipo === 'mensual') {
    $key = "mant-$plan";
} elseif ($tipo === 'cuotas') {
    $key = "$plan-cuotas";
} else {
    $key = $plan;
}

if (!isset(PLANS[$key])) {
    http_response_code(400);
    echo json_encode(['error' => "Plan no reconocido: '$key'. Planes válidos: " . implode(', ', array_keys(PLANS))]);
    exit;
}

[$priceId, $mode, $maxCycles] = PLANS[$key];

// Verificar que el Price ID no es un placeholder
if (str_starts_with($priceId, 'PEGA_')) {
    http_response_code(503);
    echo json_encode(['error' => "Price ID para '$key' no configurado en config.php. Reemplaza el placeholder con el ID real de Stripe."]);
    exit;
}

// ── Create Stripe Checkout Session ────────────────────
\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

$successUrl = str_replace('{PLAN}', urlencode($key), SUCCESS_URL);

$params = [
    'payment_method_types' => ['card'],
    'line_items'           => [['price' => $priceId, 'quantity' => 1]],
    'mode'                 => $mode,
    'success_url'          => $successUrl,
    'cancel_url'           => CANCEL_URL . '?plan=' . urlencode($key),
    'locale'               => 'es',
    'metadata'             => ['plan' => $key, 'max_cycles' => $maxCycles],
];

if ($mode === 'subscription') {
    $params['subscription_data'] = [
        'metadata' => [
            'plan'        => $key,
            'max_cycles'  => (string)$maxCycles,
            'paid_cycles' => '0',
        ],
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
        'decline' => $stripeErr->decline_code ?? null,
    ]);
    error_log('[MCW-Stripe] checkout error: ' . $e->getMessage());
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    error_log('[MCW] checkout fatal: ' . $e->getMessage());
}
