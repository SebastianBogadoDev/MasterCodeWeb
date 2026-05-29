<?php
// ── VERSION PROBE — eliminar tras verificar ───────────────────────
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'checkpoint' => 'VERSION_20260529',
    'timestamp'  => time(),
]);
exit;
// ─────────────────────────────────────────────────────────────────

// ══ DIAGNÓSTICO ACTIVO ════════════════════════════════════════════
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ── Log helpers ───────────────────────────────────────────────────

function _dbg(string $step, string $detail = ''): void
{
    $log = __DIR__ . '/../storage/logs/debug.log';
    $dir = dirname($log);
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    file_put_contents(
        $log,
        date('c') . ' STEP: ' . $step . ($detail !== '' ? ' | ' . $detail : '') . "\n",
        FILE_APPEND
    );
}

function _errLog(\Throwable $e, string $step = ''): void
{
    // Formato exacto solicitado → error_log PHP (Hostinger: ~/public_html/error_log)
    error_log(
        '[' . date('c') . '] '
        . ($step !== '' ? '[' . $step . '] ' : '')
        . $e->getMessage()
        . ' FILE=' . $e->getFile()
        . ' LINE=' . $e->getLine()
    );

    // También a storage/logs/error.log para poder leerlo desde File Manager
    $dir = __DIR__ . '/../storage/logs';
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    file_put_contents(
        $dir . '/error.log',
        '[' . date('c') . '] '
        . ($step !== '' ? '[' . $step . '] ' : '')
        . $e->getMessage()
        . ' FILE=' . $e->getFile()
        . ' LINE=' . $e->getLine() . "\n",
        FILE_APPEND
    );
}

function _fail(int $code, string $step, string $error, array $extra = []): never
{
    http_response_code($code);
    echo json_encode(array_merge(
        ['success' => false, 'step' => $step, 'error' => $error],
        $extra
    ));
    exit;
}

// ─────────────────────────────────────────────────────────────────

_dbg('START', 'method=' . ($_SERVER['REQUEST_METHOD'] ?? '?')
    . ' ip=' . ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '?'));

// ══ CONTENT-TYPE + CORS ═══════════════════════════════════════════
header('Content-Type: application/json; charset=utf-8');
$reqOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . ($reqOrigin !== '' ? $reqOrigin : 'https://www.mastercodeweb.com'));
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');

_dbg('HEADERS_SENT');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    _dbg('OPTIONS_EXIT');
    http_response_code(204);
    exit;
}

// ══ BOOTSTRAP ════════════════════════════════════════════════════
$bootstrapPath = dirname(__DIR__) . '/config/bootstrap.php';

if (!file_exists($bootstrapPath)) {
    _dbg('BOOTSTRAP_NOT_FOUND', $bootstrapPath);
    _fail(503, 'BOOTSTRAP_NOT_FOUND', 'bootstrap.php no encontrado: ' . $bootstrapPath);
}

_dbg('BOOTSTRAP_FILE_EXISTS');

try {
    require_once $bootstrapPath;
} catch (\Throwable $e) {
    _errLog($e, 'BOOTSTRAP_EXCEPTION');
    _dbg('BOOTSTRAP_EXCEPTION', $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    _fail(503, 'BOOTSTRAP_EXCEPTION', $e->getMessage(), [
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
        'class' => get_class($e),
    ]);
}

_dbg('BOOTSTRAP_OK', 'mode=' . (defined('STRIPE_MODE') ? STRIPE_MODE : 'UNDEFINED')
    . ' env=' . (defined('APP_ENV') ? APP_ENV : 'UNDEFINED'));

// ══ RATE LIMIT ═══════════════════════════════════════════════════
_dbg('BEFORE_RATE_LIMIT');
rateLimitIp('checkout', 10, 60);
_dbg('AFTER_RATE_LIMIT');

// ══ MÉTODO ═══════════════════════════════════════════════════════
_dbg('BEFORE_VALIDATE_METHOD');
validateMethod('POST');
_dbg('AFTER_VALIDATE_METHOD');

// ══ BODY ═════════════════════════════════════════════════════════
_dbg('BEFORE_PARSE_BODY');
$body = parseJsonBody();
_dbg('JSON_OK', 'keys=' . implode(',', array_keys($body)));

requireFields($body, 'plan', 'tipo');

$plan           = sanitize($body['plan']);
$tipo           = sanitize($body['tipo']);
$addMaintenance = filter_var($body['addMaintenance'] ?? false, FILTER_VALIDATE_BOOLEAN);

_dbg('BODY_PARSED', 'plan=' . $plan . ' tipo=' . $tipo . ' addMaint=' . ($addMaintenance ? '1' : '0'));

// ══ PLAN ════════════════════════════════════════════════════════
$key = ($tipo === 'mensual') ? "mant-{$plan}" : $plan;

_dbg('BEFORE_VALIDATE_PLAN', 'key=' . $key);
validatePlan($key);
_dbg('PLAN_OK', 'key=' . $key);

[$priceId, $mode] = PLANS[$key];

if (empty($priceId) || str_contains($priceId, 'PEGA_')) {
    _dbg('PRICE_ID_INVALID', 'key=' . $key . ' priceId=[' . $priceId . ']');
    _fail(503, 'PRICE_ID_INVALID', "Plan '{$key}' no tiene price ID configurado.");
}

_dbg('STRIPE_OK', 'stripe_mode=' . STRIPE_MODE
    . ' price_prefix=' . substr($priceId, 0, 8)
    . ' checkout_mode=' . $mode);

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

_dbg('BEFORE_STRIPE_SESSION', 'withMaint=' . ($withMaint ? 'yes' : 'no'));

// ══ STRIPE SESSION ════════════════════════════════════════════════
try {
    $session = stripeRetry(fn() => \Stripe\Checkout\Session::create($params));

    _dbg('SESSION_OK', 'plan=' . $key . ' mode=' . STRIPE_MODE);

    $out = json_encode(['success' => true, 'url' => $session->url]);
    _dbg('OUTPUT_OK', 'bytes=' . strlen((string) $out));
    echo $out;

} catch (\Stripe\Exception\AuthenticationException $e) {
    _errLog($e, 'STRIPE_AUTH_ERROR');
    _dbg('STRIPE_AUTH_ERROR', $e->getMessage());
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'step'    => 'STRIPE_AUTH_ERROR',
        'error'   => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);

} catch (\Stripe\Exception\ApiErrorException $e) {
    _errLog($e, 'STRIPE_API_ERROR');
    _dbg('STRIPE_API_ERROR', $e->getMessage());
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'step'    => 'STRIPE_API_ERROR',
        'error'   => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);

} catch (\Throwable $e) {
    _errLog($e, 'THROWABLE');
    _dbg('THROWABLE', $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'step'    => 'THROWABLE',
        'error'   => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
}
