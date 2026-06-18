<?php
/* =====================================================
   STRIPE CHECKOUT SESSION CREATOR — MasterCodeWeb
   POST /api/create-checkout.php
   Body: { "plan": "basico|profesional|premium",
           "tipo": "unico|mensual",
           "addMaintenance": bool }
   Returns: { "url": "https://checkout.stripe.com/..." }
===================================================== */

require_once __DIR__ . '/config.php';

apiHeaders('POST');
rateLimitIp('checkout', 10, 60);   // máx 10 intentos/min por IP
validateMethod('POST');

$body = parseJsonBody();
requireFields($body, 'plan', 'tipo');

$plan           = sanitize($body['plan']);
$tipo           = sanitize($body['tipo']);
$addMaintenance = filter_var($body['addMaintenance'] ?? false, FILTER_VALIDATE_BOOLEAN);

$key = ($tipo === 'mensual') ? "mant-$plan" : $plan;
validatePlan($key);

[$priceId, $mode] = PLANS[$key];

if ($priceId === '' || str_contains($priceId, 'PEGA_')) {
    appLog('ERROR', 'checkout', 'Price ID no configurado', ['key' => $key, 'raw' => $priceId]);
    jsonError(503, "El plan '$key' no tiene un Price ID configurado. Edita las variables PRICE_* en el archivo .env.");
}

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
        'automatic_payment_methods' => ['enabled' => true],
        'line_items'                => [
            ['price' => $priceId,    'quantity' => 1],
            ['price' => $maintPrice, 'quantity' => 1],
        ],
        'mode'        => 'subscription',
        'success_url' => $successUrl . '&maint=1',
        'cancel_url'  => CANCEL_URL . '?plan=' . urlencode($key),
        'locale'      => 'es',
        'metadata'    => ['plan' => $key, 'maintenance' => $maintKey, 'env' => APP_ENV],
    ];
} else {
    $params = [
        'automatic_payment_methods' => ['enabled' => true],
        'line_items'                => [['price' => $priceId, 'quantity' => 1]],
        'mode'                      => $mode,
        'success_url'               => $successUrl,
        'cancel_url'                => CANCEL_URL . '?plan=' . urlencode($key),
        'locale'                    => 'es',
        'metadata'                  => ['plan' => $key, 'env' => APP_ENV],
    ];
}

try {
    $session = stripeRetry(fn() => \Stripe\Checkout\Session::create($params));

    appLog('INFO', 'checkout', 'Sesión creada', [
        'plan' => $key,
        'mode' => STRIPE_MODE,
        'ip'   => clientIp(),
    ]);

    echo json_encode(['url' => $session->url]);

} catch (\Stripe\Exception\ApiErrorException $e) {
    $err = $e->getError();
    appLog('ERROR', 'checkout', 'Stripe API error', [
        'plan' => $key,
        'err'  => $e->getMessage(),
        'code' => $err->code ?? null,
    ]);
    http_response_code(502);
    echo json_encode(['error' => $e->getMessage(), 'code' => $err->code ?? null]);

} catch (\Throwable $e) {
    appLog('ERROR', 'checkout', 'Fatal', ['err' => $e->getMessage()]);
    jsonError(500, 'Error interno del servidor.');
}
