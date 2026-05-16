<?php
/* =====================================================
   STRIPE CHECKOUT SESSION CREATOR — MasterCodeWeb
   POST /api/checkout.php
   Body: { "plan": "basico|profesional|premium", "tipo": "unico|cuotas|mensual" }
   Returns: { "url": "https://checkout.stripe.com/..." }
            { "error": "mensaje de error" }
===================================================== */

// ── Cargar config ─────────────────────────────────────
$config = __DIR__ . '/config.php';
if (!file_exists($config)) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Servicio de pago no disponible temporalmente.']);
    exit;
}
require_once $config;

// ── Cargar Stripe SDK ─────────────────────────────────
$stripe = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($stripe)) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Servicio de pago no disponible temporalmente.']);
    exit;
}
require_once $stripe;

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
    echo json_encode(['error' => 'Solicitud no válida.']);
    exit;
}

$plan = trim($body['plan'] ?? '');
$tipo = trim($body['tipo'] ?? '');

// Build plan key
if ($tipo === 'mensual') {
    $key = "mant-$plan";        // mant-basico / mant-profesional / mant-premium
} elseif ($tipo === 'cuotas') {
    $key = "$plan-cuotas";      // basico-cuotas / profesional-cuotas / premium-cuotas
} else {
    $key = $plan;               // basico / profesional / premium
}

if (!isset(PLANS[$key])) {
    http_response_code(400);
    echo json_encode(['error' => 'Plan no reconocido.']);
    exit;
}

[$priceId, $mode, $maxCycles] = PLANS[$key];

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
    'automatic_tax'        => ['enabled' => true],  // Stripe Tax — activa en Dashboard primero
    'tax_id_collection'    => ['enabled' => true],  // permite NIF/VAT para B2B
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
    echo json_encode(['error' => 'Error al iniciar el pago. Inténtalo de nuevo.']);
    error_log('[MCW-Stripe] checkout error: ' . $e->getMessage());
}
