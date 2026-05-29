<?php
/**
 * api/mcw-last-step.php — Lee debug.log y devuelve el último checkpoint alcanzado.
 *
 * ⚠️  ELIMINAR después del diagnóstico.
 *
 * Uso: GET /api/mcw-last-step.php?token=MCW-DEBUG-LOG-2026
 */

const MCW_LOG_TOKEN = 'MCW-DEBUG-LOG-2026';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');
header('X-Robots-Tag: noindex');

if (($_GET['token'] ?? '') !== MCW_LOG_TOKEN) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$logFile = dirname(__DIR__) . '/storage/logs/debug.log';
$logDir  = dirname($logFile);

// ── Diagnóstico del directorio ────────────────────────────────────
$dirInfo = [
    'path'     => $logDir,
    'exists'   => is_dir($logDir),
    'writable' => is_writable($logDir),
];

if (!file_exists($logFile)) {
    echo json_encode([
        'error'      => 'debug.log no encontrado — checkout.php no se ejecutó o el directorio no es escribible',
        'log_path'   => $logFile,
        'dir'        => $dirInfo,
        'suggestion' => 'Ejecuta el curl de prueba primero y recarga este endpoint.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$allLines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

if (empty($allLines)) {
    echo json_encode([
        'error'    => 'debug.log existe pero está vacío',
        'log_path' => $logFile,
        'dir'      => $dirInfo,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── Tomar las últimas 80 líneas ───────────────────────────────────
$tail = array_slice($allLines, -80);

// ── Parsear steps y errores ───────────────────────────────────────
$steps  = [];
$errors = [];

foreach ($tail as $line) {
    // Formato: "2026-05-29T18:00:00+00:00 STEP: NOMBRE | detalle"
    if (preg_match('/^(.+?) STEP: ([A-Z_]+)(.*)$/', $line, $m)) {
        $steps[] = [
            'ts'     => trim($m[1]),
            'step'   => $m[2],
            'detail' => trim(ltrim($m[3], ' |')),
        ];
    }

    if (preg_match('/EXCEPTION|THROWABLE|ERROR|NOT_FOUND|INVALID/i', $line)) {
        $errors[] = $line;
    }
}

$lastStep = !empty($steps) ? end($steps) : null;

// ── Interpret last step ───────────────────────────────────────────
$hints = [
    'START'                => 'PHP arrancó. El problema está después.',
    'HEADERS_SENT'         => 'Headers emitidos. Falla justo después — en bootstrap.php.',
    'BOOTSTRAP_FILE_EXISTS'=> 'bootstrap.php existe pero lanzó excepción (mira errors_found). Revisa config/bootstrap.php y config/stripe.php.',
    'BOOTSTRAP_OK'         => 'Bootstrap cargó. Falla en rate limit o validateMethod(). Revisa config/rate-limiter.php y config/validator.php.',
    'AFTER_RATE_LIMIT'     => 'Rate limit OK. Falla en validateMethod(). Verifica REQUEST_METHOD.',
    'BEFORE_PARSE_BODY'    => 'validateMethod OK. Falla en parseJsonBody() — body vacío o mal formado.',
    'JSON_OK'              => 'JSON parseado. Falla en requireFields, sanitize o validatePlan.',
    'BODY_PARSED'          => 'Body OK. Falla en validatePlan o PLANS lookup.',
    'PLAN_OK'              => 'Plan validado. Falla al leer PLANS[key] o price ID check.',
    'STRIPE_OK'            => 'Price ID OK. Falla al crear sesión Stripe — revisa la clave secreta y los price IDs en .env.',
    'BEFORE_STRIPE_SESSION'=> 'Stripe session falló — revisa STRIPE_SECRET_KEY y price IDs.',
    'SESSION_OK'           => 'Sesión creada. Falla al serializar o hacer echo del JSON.',
    'OUTPUT_OK'            => 'Todo OK — el JSON se generó. Posible problema de output buffering.',
];

$hint = isset($lastStep['step']) ? ($hints[$lastStep['step']] ?? 'Step desconocido.') : 'Ningún step registrado.';

// ── Agrupar steps de la última ejecución ──────────────────────────
// (desde el último START hasta el final)
$lastRun = [];
for ($i = count($steps) - 1; $i >= 0; $i--) {
    array_unshift($lastRun, $steps[$i]);
    if ($steps[$i]['step'] === 'START') break;
}

echo json_encode([
    'last_step'   => $lastStep['step'] ?? 'ninguno',
    'last_detail' => $lastStep['detail'] ?? null,
    'timestamp'   => $lastStep['ts'] ?? null,
    'hint'        => $hint,
    'last_run'    => $lastRun,
    'errors_found'=> array_values(array_unique($errors)),
    'log_stats'   => [
        'total_lines' => count($allLines),
        'file_size'   => filesize($logFile) . ' bytes',
        'last_modified' => date('Y-m-d H:i:s', filemtime($logFile)),
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
