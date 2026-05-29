<?php
// ══ DIAGNÓSTICO: activar errores visibles ════════════════════════
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ══ PROBE — eliminar en cuanto curl devuelva este JSON ═══════════
// Si este JSON no aparece, el problema está ANTES de PHP (nginx,
// .htaccess, PHP-FPM, caché de servidor). Si aparece, PHP ejecuta.
echo json_encode(['debug' => 'checkout reached']);
exit;
// ═════════════════════════════════════════════════════════════════

// ── Función de log a archivo (independiente de bootstrap) ────────
function _dbg(string $step, string $detail = ''): void
{
    $log = __DIR__ . '/../storage/logs/debug.log';
    $dir = dirname($log);
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    file_put_contents(
        $log,
        date('c') . ' STEP: ' . $step . ($detail ? ' | ' . $detail : '') . "\n",
        FILE_APPEND
    );
}

_dbg('START', 'method=' . ($_SERVER['REQUEST_METHOD'] ?? '?') . ' ip=' . ($_SERVER['REMOTE_ADDR'] ?? '?'));

// ══ CONTENT-TYPE + CORS (primero — antes de cualquier exit) ══════
header('Content-Type: application/json; charset=utf-8');
$reqOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . ($reqOrigin !== '' ? $reqOrigin : 'https://www.mastercodeweb.com'));
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');

_dbg('HEADERS_SENT');

// ── Preflight ────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    _dbg('OPTIONS_EXIT');
    http_response_code(204);
    exit;
}

// ── Bootstrap ────────────────────────────────────────────────────
$bootstrapPath = dirname(__DIR__) . '/config/bootstrap.php';

if (!file_exists($bootstrapPath)) {
    _dbg('BOOTSTRAP_NOT_FOUND', $bootstrapPath);
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'bootstrap no encontrado', 'step' => 'BOOTSTRAP_NOT_FOUND']);
    exit;
}

_dbg('BOOTSTRAP_FILE_EXISTS');

try {
    require_once $bootstrapPath;
} catch (\Throwable $e) {
    _dbg('BOOTSTRAP_EXCEPTION', $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
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

_dbg('BOOTSTRAP_OK', 'mode=' . (defined('STRIPE_MODE') ? STRIPE_MODE : 'UNDEFINED') . ' env=' . (defined('APP_ENV') ? APP_ENV : 'UNDEFINED'));

// ── Rate limit ───────────────────────────────────────────────────
_dbg('BEFORE_RATE_LIMIT');
rateLimitIp('checkout', 10, 60);
_dbg('AFTER_RATE_LIMIT');

// ── Método ───────────────────────────────────────────────────────
_dbg('BEFORE_VALIDATE_METHOD');
validateMethod('POST');
_dbg('AFTER_VALIDATE_METHOD');

// ── Body ─────────────────────────────────────────────────────────
_dbg('BEFORE_PARSE_BODY');
$body = parseJsonBody();
_dbg('JSON_OK', 'keys=' . implode(',', array_keys($body)));

requireFields($body, 'plan', 'tipo');

$plan           = sanitize($body['plan']);
$tipo           = sanitize($body['tipo']);
$addMaintenance = filter_var($body['addMaintenance'] ?? false, FILTER_VALIDATE_BOOLEAN);

_dbg('BODY_PARSED', 'plan=' . $plan . ' tipo=' . $tipo);

// ── Plan ─────────────────────────────────────────────────────────
$key = ($tipo === 'mensual') ? "mant-{$plan}" : $plan;
_dbg('BEFORE_VALIDATE_PLAN', 'key=' . $key);
validatePlan($key);
_dbg('PLAN_OK', 'key=' . $key);

[$priceId, $mode] = PLANS[$key];

if (empty($priceId) || str_contains($priceId, 'PEGA_')) {
    _dbg('PRICE_ID_INVALID', 'key=' . $key . ' priceId=' . $priceId);
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => "plan '{$key}' sin price ID", 'step' => 'PRICE_ID_INVALID']);
    exit;
}

_dbg('STRIPE_OK', 'mode=' . STRIPE_MODE . ' price_prefix=' . substr($priceId, 0, 8) . ' checkout_mode=' . $mode);

// ── Params ───────────────────────────────────────────────────────
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

// ── Stripe session ───────────────────────────────────────────────
try {
    $session = stripeRetry(fn() => \Stripe\Checkout\Session::create($params));

    _dbg('SESSION_OK', 'plan=' . $key);

    $out = json_encode(['success' => true, 'url' => $session->url]);
    _dbg('OUTPUT_OK', 'bytes=' . strlen($out));
    echo $out;

} catch (\Stripe\Exception\ApiErrorException $e) {
    $err = $e->getError();
    _dbg('STRIPE_API_ERROR', $e->getMessage());
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);

} catch (\Throwable $e) {
    _dbg('THROWABLE', $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
}
