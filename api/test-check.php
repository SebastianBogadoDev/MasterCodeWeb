<?php
/**
 * api/test-check.php — Diagnóstico de entorno Stripe
 * ⚠️  ELIMINAR después del diagnóstico.
 * Uso: GET /api/test-check.php?token=MCW-CHECK-2026
 */

const CHECK_TOKEN = 'MCW-CHECK-2026';

$report = [];
$errors = [];
$done   = false;

// ── Shutdown function ──────────────────────────────────────────────
// Se ejecuta incluso tras exit() o error fatal.
// Captura la salida de bootstrap si llamó a exit() y siempre devuelve JSON.
register_shutdown_function(function () use (&$report, &$errors, &$done): void {
    if ($done) {
        return;
    }

    // Vaciar buffers abiertos por ob_start() — contienen lo que bootstrap
    // imprimió antes de llamar a exit(). Extraer el JSON de error si existe.
    while (ob_get_level() > 0) {
        $captured = (string) ob_get_clean();
        if ($captured !== '') {
            $decoded = json_decode($captured, true);
            if (is_array($decoded)) {
                $errors[] = 'bootstrap_exit · step=' . ($decoded['step'] ?? '?')
                    . ' · error=' . ($decoded['error'] ?? '?');
                $report['bootstrap_exit_json'] = $decoded;
            } else {
                $errors[] = 'bootstrap_output_raw: ' . substr($captured, 0, 300);
            }
        }
    }

    // Detectar error fatal (E_ERROR, E_PARSE, etc.)
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $errors[] = '[FATAL] ' . $err['message']
            . ' · file=' . $err['file']
            . ' · line=' . $err['line'];
        $report['fatal'] = [
            'message' => $err['message'],
            'file'    => basename($err['file']),
            'line'    => $err['line'],
        ];
    }

    // Forzar 200 y Content-Type antes de imprimir el JSON diagnóstico.
    // Los headers de PHP no se envían hasta el primer flush — podemos
    // sobreescribir el código de estado que bootstrap haya puesto.
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
    }

    $report['errors'] = $errors;
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

// ── Headers propios (antes de cualquier lógica) ────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

if (($_GET['token'] ?? '') !== CHECK_TOKEN) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    $done = true;
    exit;
}

$root = dirname(__DIR__);

// ── 1. PHP ─────────────────────────────────────────────────────────
$report['php']             = PHP_VERSION;
$report['php_sapi']        = PHP_SAPI;
$report['variables_order'] = ini_get('variables_order');
$report['display_errors']  = ini_get('display_errors');

// ── 2. Archivos clave ──────────────────────────────────────────────
$report['autoload']   = file_exists($root . '/vendor/autoload.php');
$report['bootstrap']  = file_exists($root . '/config/bootstrap.php');

// ── 3. Stripe SDK class (solo autoload, sin bootstrap) ────────────
$report['stripe_class'] = false;

if ($report['autoload']) {
    try {
        require_once $root . '/vendor/autoload.php';
        $report['stripe_class'] = class_exists('Stripe\\Stripe');
    } catch (\Throwable $e) {
        $errors[] = 'autoload_exception: ' . $e->getMessage()
            . ' · file=' . basename($e->getFile())
            . ' · line=' . $e->getLine();
    }
}

// ── 4. Leer .env directamente (sin bootstrap) ─────────────────────
// Valores de referencia aunque bootstrap no cargue.
$report['app_env']  = null;
$report['site_url'] = null;
$report['env_vars'] = [];

$envFile = $root . '/.env';
if (file_exists($envFile) && is_readable($envFile)) {
    $parsed = [];
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $rawLine) {
        $rawLine = trim($rawLine);
        if ($rawLine === '' || $rawLine[0] === '#' || !str_contains($rawLine, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $rawLine, 2);
        $parsed[trim($k)] = trim($v);
    }

    $report['app_env']  = $parsed['APP_ENV']  ?? null;
    $report['site_url'] = $parsed['SITE_URL'] ?? null;

    $chk = static function (string $v) use ($parsed): bool {
        $val = $parsed[$v] ?? '';
        return $val !== '' && !str_contains($val, 'PEGA_');
    };

    $report['env_vars'] = [
        'STRIPE_SECRET_KEY'     => $chk('STRIPE_SECRET_KEY'),
        'STRIPE_PUBLIC_KEY'     => $chk('STRIPE_PUBLIC_KEY'),
        'STRIPE_WEBHOOK_SECRET' => $chk('STRIPE_WEBHOOK_SECRET'),
        'PRICE_BASICO'          => $chk('PRICE_BASICO'),
        'PRICE_PRO'             => $chk('PRICE_PRO'),
        'PRICE_PREMIUM'         => $chk('PRICE_PREMIUM'),
        'PRICE_MANT_BASICO'     => $chk('PRICE_MANT_BASICO'),
        'PRICE_MANT_PRO'        => $chk('PRICE_MANT_PRO'),
        'PRICE_MANT_PREMIUM'    => $chk('PRICE_MANT_PREMIUM'),
        'OWNER_EMAIL'           => $chk('OWNER_EMAIL'),
    ];
} else {
    $errors[] = '.env no encontrado o no legible en: ' . $envFile;
}

// ── 5. Cargar bootstrap completo ───────────────────────────────────
// ob_start captura cualquier echo que bootstrap emita antes de exit().
// El shutdown function limpia el buffer y decodifica el JSON de error.
$report['bootstrap_ok']     = false;
$report['bootstrap_error']  = null;

if ($report['bootstrap'] && $report['autoload']) {
    ob_start();
    try {
        require_once $root . '/config/bootstrap.php';
        $report['bootstrap_ok'] = true;
        ob_end_clean(); // bootstrap cargó sin salida — descartar buffer vacío

        // Sobrescribir con los valores reales de las constantes
        $report['app_env']  = defined('APP_ENV')  ? APP_ENV  : '⚠ NOT_DEFINED';
        $report['site_url'] = defined('SITE_URL') ? SITE_URL : '⚠ NOT_DEFINED';
        $report['env_vars'] = [
            'STRIPE_SECRET_KEY'     => defined('STRIPE_SECRET_KEY')     && STRIPE_SECRET_KEY     !== '',
            'STRIPE_PUBLIC_KEY'     => defined('STRIPE_PUBLIC_KEY')     && STRIPE_PUBLIC_KEY     !== '',
            'STRIPE_WEBHOOK_SECRET' => defined('STRIPE_WEBHOOK_SECRET') && STRIPE_WEBHOOK_SECRET !== '',
            'PRICE_BASICO'          => defined('PRICE_BASICO')          && PRICE_BASICO          !== '',
            'PRICE_PRO'             => defined('PRICE_PRO')             && PRICE_PRO             !== '',
            'PRICE_PREMIUM'         => defined('PRICE_PREMIUM')         && PRICE_PREMIUM         !== '',
            'PRICE_MANT_BASICO'     => defined('PRICE_MANT_BASICO')     && PRICE_MANT_BASICO     !== '',
            'PRICE_MANT_PRO'        => defined('PRICE_MANT_PRO')        && PRICE_MANT_PRO        !== '',
            'PRICE_MANT_PREMIUM'    => defined('PRICE_MANT_PREMIUM')    && PRICE_MANT_PREMIUM    !== '',
            'OWNER_EMAIL'           => defined('OWNER_EMAIL')           && OWNER_EMAIL           !== '',
        ];

    } catch (\Throwable $e) {
        ob_end_clean();
        $report['bootstrap_error'] = $e->getMessage()
            . ' in ' . basename($e->getFile()) . ':' . $e->getLine();
        $errors[] = 'bootstrap_exception: ' . $report['bootstrap_error'];
    }
    // Si bootstrap llamó a exit(), la ejecución termina aquí.
    // El shutdown function recoge el buffer y los errores.
}

// ── 6. Salida ──────────────────────────────────────────────────────
$report['errors'] = $errors;
$done = true;
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
