<?php
error_log('[CP1] checkout.php ejecutado');

// ── Helpers de log ────────────────────────────────────────────────
function _cp(int $n, string $detail = ''): void
{
    $msg = '[CHECKOUT CP] ' . $n . ($detail !== '' ? ' — ' . $detail : '');
    error_log($msg);

    $log = __DIR__ . '/../storage/logs/debug.log';
    $dir = dirname($log);
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    @file_put_contents($log, date('c') . ' ' . $msg . "\n", FILE_APPEND);
}

function _cpErr(\Throwable $e, string $step): void
{
    $trace = implode(' > ', array_slice(
        array_map(fn($f) => basename($f['file'] ?? '?') . ':' . ($f['line'] ?? '?'), $e->getTrace()),
        0, 5
    ));
    $msg = '[CHECKOUT ERR] [' . $step . '] '
        . $e->getMessage()
        . ' FILE=' . $e->getFile()
        . ' LINE=' . $e->getLine()
        . ' TRACE=[' . $trace . ']';

    error_log($msg);

    $dir = __DIR__ . '/../storage/logs';
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    @file_put_contents($dir . '/error.log', date('c') . ' ' . $msg . "\n", FILE_APPEND);
}

function _fail(int $code, string $step, string $error, array $extra = []): never
{
    error_log('[CHECKOUT FAIL] HTTP=' . $code . ' step=' . $step . ' error=' . $error);
    http_response_code($code);
    echo json_encode(array_merge(['success' => false, 'step' => $step, 'error' => $error], $extra));
    exit;
}

// ─────────────────────────────────────────────────────────────────

_cp(1, 'START method=' . ($_SERVER['REQUEST_METHOD'] ?? '?')
    . ' ip=' . ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '?'));

// ══ CONTENT-TYPE + CORS ═══════════════════════════════════════════
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://www.mastercodeweb.com');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');

_cp(2, 'HEADERS_SENT');
error_log('[CP2] headers ok method=' . ($_SERVER['REQUEST_METHOD'] ?? '?'));

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    _cp(3, 'OPTIONS_EXIT');
    http_response_code(204);
    exit;
}

// ══ BOOTSTRAP ════════════════════════════════════════════════════
$bootstrapPath = dirname(__DIR__) . '/config/bootstrap.php';

if (!file_exists($bootstrapPath)) {
    _cp(4, 'BOOTSTRAP_NOT_FOUND path=' . $bootstrapPath);
    _fail(503, 'BOOTSTRAP_NOT_FOUND', 'bootstrap.php no encontrado: ' . $bootstrapPath);
}

_cp(4, 'BOOTSTRAP_FILE_EXISTS path=' . $bootstrapPath);

// NOTA: si bootstrap.php llama a exit() internamente (p.ej. .env error),
// ese exit NO puede ser capturado por este try/catch.
// En ese caso el script termina aquí. Los checkpoints 5+ no se registran.
error_log('[CP3] antes de require bootstrap');
try {
    require_once $bootstrapPath;
    error_log('[CP4] bootstrap require_once retornó');
} catch (\Throwable $e) {
    _cpErr($e, 'BOOTSTRAP_EXCEPTION');
    _fail(503, 'BOOTSTRAP_EXCEPTION', $e->getMessage(), [
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
        'class' => get_class($e),
    ]);
}

// Si llegamos aquí bootstrap NO llamó a exit() y NO lanzó excepción.
// SITE_URL ya está definida — sobreescribir CORS con el valor real del .env.
header('Access-Control-Allow-Origin: ' . SITE_URL);
error_log('[CP5] bootstrap ok');
_cp(5, 'BOOTSTRAP_OK stripe_mode=' . (defined('STRIPE_MODE') ? STRIPE_MODE : 'UNDEFINED')
    . ' app_env=' . (defined('APP_ENV') ? APP_ENV : 'UNDEFINED'));

// ══ RATE LIMIT ═══════════════════════════════════════════════════
error_log('[CP6] antes rate limit');
_cp(6, 'BEFORE_RATE_LIMIT');
rateLimitIp('checkout', 10, 60);
_cp(7, 'AFTER_RATE_LIMIT');
error_log('[CP7] después rate limit');

// ══ MÉTODO ═══════════════════════════════════════════════════════
error_log('[CP8] antes validateMethod');
_cp(8, 'BEFORE_VALIDATE_METHOD method=' . ($_SERVER['REQUEST_METHOD'] ?? '?'));
validateMethod('POST');
_cp(9, 'AFTER_VALIDATE_METHOD');
error_log('[CP9] después validateMethod');

// ══ BODY ═════════════════════════════════════════════════════════
error_log('[CP10] antes parseJsonBody');
_cp(10, 'BEFORE_PARSE_BODY');
$body = parseJsonBody();
_cp(11, 'JSON_OK keys=' . implode(',', array_keys($body)));

requireFields($body, 'plan', 'tipo');

$plan           = sanitize($body['plan']);
$tipo           = sanitize($body['tipo']);
$addMaintenance = filter_var($body['addMaintenance'] ?? false, FILTER_VALIDATE_BOOLEAN);

_cp(12, 'BODY_PARSED plan=[' . $plan . '] tipo=[' . $tipo . '] addMaint=' . ($addMaintenance ? '1' : '0'));
error_log('[CP11] body ok plan=[' . $plan . '] tipo=[' . $tipo . ']');

// ══ PLAN ════════════════════════════════════════════════════════
$key = ($tipo === 'mensual') ? "mant-{$plan}" : $plan;

_cp(13, 'BEFORE_VALIDATE_PLAN key=[' . $key . ']');
validatePlan($key);
_cp(14, 'PLAN_OK key=[' . $key . ']');

[$priceId, $mode] = PLANS[$key];

// Registrar el price ID real — clave para detectar placeholders o vacíos
_cp(15, 'PRICE_ID price_id=[' . $priceId . '] checkout_mode=[' . $mode . ']');
error_log('[CP12] price_id=[' . $priceId . '] mode=[' . $mode . ']');

if (empty($priceId) || str_contains($priceId, 'PEGA_')) {
    _fail(503, 'PRICE_ID_INVALID',
        "Plan '{$key}' no tiene price ID configurado. "
        . "Edita .env en Hostinger: PRICE_BASICO, PRICE_PRO, PRICE_PREMIUM.",
        ['price_id_raw' => $priceId, 'key' => $key]
    );
}

_cp(16, 'STRIPE_OK stripe_mode=' . STRIPE_MODE
    . ' price_prefix=' . substr($priceId, 0, 8) . ' checkout_mode=' . $mode);

// ══ PARAMS ═══════════════════════════════════════════════════════
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

_cp(17, 'BEFORE_STRIPE_SESSION withMaint=' . ($withMaint ? 'yes' : 'no'));
error_log('[CP13] antes Stripe Session::create');

// ══ STRIPE SESSION ════════════════════════════════════════════════
try {
    $session = stripeRetry(fn() => \Stripe\Checkout\Session::create($params));

    _cp(18, 'SESSION_OK plan=' . $key . ' livemode=' . ($session->livemode ? 'yes' : 'no'));
    error_log('[CP14] session ok url=' . ($session->url ?? 'NULL'));

    $out = json_encode(['success' => true, 'url' => $session->url]);
    _cp(19, 'OUTPUT_OK bytes=' . strlen((string) $out));
    error_log('[CP15] echo output bytes=' . strlen((string) $out));
    echo $out;

} catch (\Stripe\Exception\AuthenticationException $e) {
    _cpErr($e, 'STRIPE_AUTH_ERROR');
    http_response_code(502);
    echo json_encode([
        'success' => false, 'step' => 'STRIPE_AUTH_ERROR',
        'error'   => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(),
    ]);

} catch (\Stripe\Exception\ApiErrorException $e) {
    _cpErr($e, 'STRIPE_API_ERROR');
    http_response_code(502);
    echo json_encode([
        'success' => false, 'step' => 'STRIPE_API_ERROR',
        'error'   => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(),
    ]);

} catch (\Throwable $e) {
    _cpErr($e, 'THROWABLE');
    http_response_code(500);
    echo json_encode([
        'success' => false, 'step' => 'THROWABLE',
        'error'   => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(),
    ]);
}
