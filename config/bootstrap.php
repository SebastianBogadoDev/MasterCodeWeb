<?php
/* config/bootstrap.php — punto de entrada único
   Orden: helpers → vendor → .env → constantes → módulos

   Estrategia .env (sin dependencia obligatoria de vlucas/phpdotenv):
     • Si Dotenv\Dotenv existe en vendor → lo usa (desarrollo local)
     • Si no existe             → usa el parser inline incorporado (Hostinger / producción)
   Elimina por completo el error "Class Dotenv\Dotenv not found" en producción.
*/

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
// Fallback cuando Dotenv\Dotenv no está disponible (Hostinger / producción).
// Soporta: sin comillas, comillas dobles, comillas simples,
//          comentarios inline (# fuera de comillas), export VAR=value.

function loadDotEnv(string $rootDir): void
{
    $file = rtrim($rootDir, '/') . '/.env';

    if (!file_exists($file)) {
        throw new \RuntimeException(".env no encontrado en: {$file}");
    }
    if (!is_readable($file)) {
        throw new \RuntimeException(".env no legible (permisos): {$file}");
    }

    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
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

        // Sobreescribir si no existe O si ya existe pero está vacío.
        // Hostinger puede pre-definir variables de entorno como '' vacías.
        $existing = $_ENV[$name] ?? null;
        if ($existing === null || $existing === '') {
            $_ENV[$name]    = $value;
            $_SERVER[$name] = $value;
            putenv("{$name}={$value}");
        }
    }
}

function requireEnvVars(array $vars): void
{
    // $_ENV puede estar vacío en Hostinger (variables_order sin 'E') — cae a getenv().
    $missing = array_filter($vars, function (string $v): bool {
        $fromEnv    = $_ENV[$v] ?? '';
        $fromGetenv = (string)(getenv($v) ?: '');
        return $fromEnv === '' && $fromGetenv === '';
    });

    if (!empty($missing)) {
        throw new \RuntimeException(
            'Variables faltantes en .env: ' . implode(', ', array_values($missing))
        );
    }
}


// ══ VENDOR / AUTOLOAD ════════════════════════════════════════════
// Se carga ANTES que .env para que Dotenv\Dotenv esté disponible
// cuando existe en vendor (entorno local con phpdotenv instalado).

$vendorPath = dirname(__DIR__) . '/vendor/autoload.php';
if (!file_exists($vendorPath)) {
    error_log('[' . date('c') . '] [VENDOR_NOT_FOUND] path=' . $vendorPath);

    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: https://www.mastercodeweb.com');
    }
    echo json_encode(['success' => false, 'step' => 'VENDOR_NOT_FOUND', 'error' => 'vendor/autoload.php no encontrado']);
    exit;
}

require_once $vendorPath;


// ══ CARGAR .ENV ══════════════════════════════════════════════════

try {
    $rootDir = dirname(__DIR__);

    if (class_exists(\Dotenv\Dotenv::class)) {
        // phpdotenv disponible en vendor → desarrollo local
        \Dotenv\Dotenv::createUnsafeMutable($rootDir)->load();
    } else {
        // phpdotenv no está en vendor → Hostinger / producción
        loadDotEnv($rootDir);
    }

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

    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? 'https://www.mastercodeweb.com'));
    }
    echo json_encode(['success' => false, 'step' => 'ENV_ERROR', 'error' => $e->getMessage()]);
    exit;
}


// ══ CONSTANTES ═══════════════════════════════════════════════════

// $_ENV puede estar vacío en Hostinger si variables_order no incluye 'E'.
// getenv() lee directamente del proceso y siempre funciona como fallback.
$_eg = static fn(string $k): string => ($_ENV[$k] ?? '') !== '' ? $_ENV[$k] : ((string)(getenv($k) ?: ''));

define('STRIPE_SECRET_KEY',     $_eg('STRIPE_SECRET_KEY'));
define('STRIPE_WEBHOOK_SECRET', $_eg('STRIPE_WEBHOOK_SECRET'));
define('SITE_URL',              rtrim($_eg('SITE_URL'), '/'));
define('SUCCESS_URL',           SITE_URL . '/pages/stripe_success.html?plan={PLAN}&session_id={CHECKOUT_SESSION_ID}');
define('CANCEL_URL',            SITE_URL . '/pages/stripe_cancel.html');
define('PRICE_BASICO',          $_eg('PRICE_BASICO'));
define('PRICE_PRO',             $_eg('PRICE_PRO'));
define('PRICE_PREMIUM',         $_eg('PRICE_PREMIUM'));
define('PRICE_MANT_BASICO',     $_eg('PRICE_MANT_BASICO'));
define('PRICE_MANT_PRO',        $_eg('PRICE_MANT_PRO'));
define('PRICE_MANT_PREMIUM',    $_eg('PRICE_MANT_PREMIUM'));
define('OWNER_EMAIL',           $_eg('OWNER_EMAIL'));
define('TURNSTILE_SECRET',      $_eg('TURNSTILE_SECRET'));
define('RESEND_API_KEY',        $_eg('RESEND_API_KEY'));


// ══ MÓDULOS ══════════════════════════════════════════════════════

try {
    require_once __DIR__ . '/stripe.php';
    require_once __DIR__ . '/rate-limiter.php';
    require_once __DIR__ . '/validator.php';
    require_once __DIR__ . '/csrf.php';
    require_once __DIR__ . '/error-handler.php';

} catch (\Throwable $e) {
    error_log(
        '[' . date('c') . '] [MODULE_LOAD_ERROR] '
        . $e->getMessage()
        . ' FILE=' . $e->getFile()
        . ' LINE=' . $e->getLine()
    );

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
