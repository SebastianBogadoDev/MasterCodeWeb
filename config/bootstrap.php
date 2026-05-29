<?php
/* config/bootstrap.php — punto de entrada único
   Orden: helpers → .env (parser inline) → vendor (Stripe) → constantes → módulos

   Sin dependencia de vlucas/phpdotenv — funciona en cualquier PHP 8.0+
   con solo vendor/stripe-php instalado.
*/

// ══ CHECKPOINT HELPER (disponible desde la primera línea) ════════
function _bcp(int $n, string $detail = ''): void
{
    $msg = '[BOOTSTRAP CP] ' . $n . ($detail !== '' ? ' — ' . $detail : '');
    error_log($msg);

    $dir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    @file_put_contents($dir . '/debug.log', date('c') . ' ' . $msg . "\n", FILE_APPEND);
}

_bcp(1, 'BOOTSTRAP START');

// ══ HELPERS BASE ═════════════════════════════════════════════════

function jsonError(int $code, string $msg): never
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => $msg]);
    exit;
}

function appLog(string $level, string $context, string $msg, array $ctx = []): void
{
    static $dir = null;
    $dir ??= dirname(__DIR__) . '/storage/logs';

    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }

    $line = sprintf(
        "[%s] [%s] [%s] %s%s\n",
        date('Y-m-d H:i:s'),
        strtoupper($level),
        $context,
        $msg,
        $ctx ? ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
    );

    @file_put_contents($dir . '/stripe.log', $line, FILE_APPEND | LOCK_EX);
}


// ══ PARSER .ENV INLINE ════════════════════════════════════════════
// Reemplaza vlucas/phpdotenv — sin dependencias Composer.
// Soporta: sin comillas, comillas dobles, comillas simples,
//          comentarios inline (# fuera de comillas).

function loadDotEnv(string $rootDir): void
{
    $file = rtrim($rootDir, '/') . '/.env';

    if (!file_exists($file)) {
        throw new \RuntimeException(".env no encontrado en: {$file}");
    }
    if (!is_readable($file)) {
        throw new \RuntimeException(".env no legible (permisos): {$file}");
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }

        if (str_starts_with($line, 'export ')) {
            $line = ltrim(substr($line, 7));
        }

        $eqPos = strpos($line, '=');
        $name  = trim(substr($line, 0, $eqPos));
        $raw   = trim(substr($line, $eqPos + 1));

        if ($name === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            continue;
        }

        if ($raw === '') {
            $value = '';
        } elseif ($raw[0] === '"') {
            preg_match('/^"((?:[^"\\\\]|\\\\.)*)"/', $raw, $m);
            $value = isset($m[1]) ? stripcslashes($m[1]) : '';
        } elseif ($raw[0] === "'") {
            preg_match("/^'([^']*)'/", $raw, $m);
            $value = $m[1] ?? '';
        } else {
            $value = $raw;
            foreach ([' #', "\t#"] as $sep) {
                if (($pos = strpos($value, $sep)) !== false) {
                    $value = substr($value, 0, $pos);
                }
            }
            $value = trim($value);
        }

        if (!array_key_exists($name, $_ENV)) {
            $_ENV[$name]    = $value;
            $_SERVER[$name] = $value;
            putenv("{$name}={$value}");
        }
    }
}

function requireEnvVars(array $vars): void
{
    $missing = array_filter($vars, fn(string $v) => !isset($_ENV[$v]) || $_ENV[$v] === '');

    if (!empty($missing)) {
        throw new \RuntimeException(
            'Variables faltantes en .env: ' . implode(', ', array_values($missing))
        );
    }
}


// ══ CARGAR .ENV ══════════════════════════════════════════════════

_bcp(2, 'BEFORE_LOAD_DOTENV');

try {
    loadDotEnv(dirname(__DIR__));
    requireEnvVars([
        'STRIPE_SECRET_KEY', 'STRIPE_WEBHOOK_SECRET', 'STRIPE_PUBLIC_KEY',
        'SITE_URL', 'APP_ENV',
        'PRICE_BASICO', 'PRICE_PRO', 'PRICE_PREMIUM',
        'PRICE_MANT_BASICO', 'PRICE_MANT_PRO', 'PRICE_MANT_PREMIUM',
        'OWNER_EMAIL',
    ]);
} catch (\Throwable $e) {
    error_log(
        '[' . date('c') . '] [ENV_ERROR] '
        . $e->getMessage()
        . ' FILE=' . $e->getFile()
        . ' LINE=' . $e->getLine()
    );
    _bcp(3, 'ENV_ERROR — ' . $e->getMessage());

    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? 'https://www.mastercodeweb.com'));
    }
    echo json_encode(['success' => false, 'step' => 'ENV_ERROR', 'error' => $e->getMessage()]);
    exit;
}

_bcp(3, 'ENV_LOADED APP_ENV=' . ($_ENV['APP_ENV'] ?? '?'));


// ══ VENDOR / AUTOLOAD ════════════════════════════════════════════

_bcp(4, 'BEFORE_VENDOR');

$vendorPath = dirname(__DIR__) . '/vendor/autoload.php';
if (!file_exists($vendorPath)) {
    error_log('[' . date('c') . '] [VENDOR_NOT_FOUND] path=' . $vendorPath);
    _bcp(5, 'VENDOR_NOT_FOUND path=' . $vendorPath);

    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: ' . (rtrim($_ENV['SITE_URL'] ?? '', '/') ?: 'https://www.mastercodeweb.com'));
    }
    echo json_encode(['success' => false, 'step' => 'VENDOR_NOT_FOUND', 'error' => 'vendor/autoload.php no encontrado']);
    exit;
}

require_once $vendorPath;

_bcp(5, 'VENDOR_LOADED stripe_class=' . (class_exists('Stripe\\Stripe') ? 'yes' : 'NO'));


// ══ CONSTANTES ═══════════════════════════════════════════════════

_bcp(6, 'BEFORE_CONSTANTS');

define('STRIPE_SECRET_KEY',     $_ENV['STRIPE_SECRET_KEY']);
define('STRIPE_WEBHOOK_SECRET', $_ENV['STRIPE_WEBHOOK_SECRET']);
define('SITE_URL',              rtrim($_ENV['SITE_URL'], '/'));
define('SUCCESS_URL',           SITE_URL . '/pages/stripe_success.html?plan={PLAN}&session_id={CHECKOUT_SESSION_ID}');
define('CANCEL_URL',            SITE_URL . '/pages/stripe_cancel.html');
define('PRICE_BASICO',          $_ENV['PRICE_BASICO']);
define('PRICE_PRO',             $_ENV['PRICE_PRO']);
define('PRICE_PREMIUM',         $_ENV['PRICE_PREMIUM']);
define('PRICE_MANT_BASICO',     $_ENV['PRICE_MANT_BASICO']);
define('PRICE_MANT_PRO',        $_ENV['PRICE_MANT_PRO']);
define('PRICE_MANT_PREMIUM',    $_ENV['PRICE_MANT_PREMIUM']);
define('OWNER_EMAIL',           $_ENV['OWNER_EMAIL']);
define('TURNSTILE_SECRET',      $_ENV['TURNSTILE_SECRET'] ?? '');

_bcp(7, 'CONSTANTS_OK PRICE_BASICO=' . substr(PRICE_BASICO, 0, 12)
    . ' PRICE_PRO=' . substr(PRICE_PRO, 0, 12)
    . ' PRICE_PREMIUM=' . substr(PRICE_PREMIUM, 0, 12));


// ══ MÓDULOS ══════════════════════════════════════════════════════

_bcp(8, 'BEFORE_MODULES');

try {
    require_once __DIR__ . '/stripe.php';
    _bcp(9, 'stripe.php OK STRIPE_MODE=' . (defined('STRIPE_MODE') ? STRIPE_MODE : 'UNDEFINED'));

    require_once __DIR__ . '/rate-limiter.php';
    _bcp(10, 'rate-limiter.php OK');

    require_once __DIR__ . '/validator.php';
    _bcp(11, 'validator.php OK');

    require_once __DIR__ . '/csrf.php';
    _bcp(12, 'csrf.php OK');

    require_once __DIR__ . '/error-handler.php';
    _bcp(13, 'error-handler.php OK');

} catch (\Throwable $e) {
    error_log(
        '[' . date('c') . '] [MODULE_LOAD_ERROR] '
        . $e->getMessage()
        . ' FILE=' . $e->getFile()
        . ' LINE=' . $e->getLine()
    );
    _bcp(14, 'MODULE_LOAD_ERROR — ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: ' . (rtrim($_ENV['SITE_URL'] ?? '', '/') ?: 'https://www.mastercodeweb.com'));
    }
    echo json_encode([
        'success' => false,
        'step'    => 'MODULE_LOAD_ERROR',
        'error'   => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
    exit;
}

registerErrorHandlers();

_bcp(15, 'BOOTSTRAP COMPLETE');
