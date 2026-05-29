<?php
/* config/bootstrap.php — punto de entrada único
   Orden: helpers → .env (parser inline) → vendor (Stripe) → constantes → módulos

   Sin dependencia de vlucas/phpdotenv — funciona en cualquier PHP 8.0+
   con solo el vendor/stripe-php instalado.
*/

// ══════════════════════════════════════════════════════
//  HELPERS BASE  (sin dependencias externas)
// ══════════════════════════════════════════════════════

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


// ══════════════════════════════════════════════════════
//  PARSER .ENV INLINE
//  Reemplaza vlucas/phpdotenv — sin dependencias Composer.
//  Soporta: sin comillas, comillas dobles, comillas simples,
//           comentarios inline (# fuera de comillas).
// ══════════════════════════════════════════════════════

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

        // Ignorar comentarios y líneas sin '='
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }

        // Soporte para "export KEY=value"
        if (str_starts_with($line, 'export ')) {
            $line = ltrim(substr($line, 7));
        }

        $eqPos = strpos($line, '=');
        $name  = trim(substr($line, 0, $eqPos));
        $raw   = trim(substr($line, $eqPos + 1));

        // Nombre vacío o con caracteres inválidos → saltar
        if ($name === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            continue;
        }

        // Parsear valor
        if ($raw === '') {
            $value = '';
        } elseif ($raw[0] === '"') {
            // Comillas dobles: interpretar secuencias de escape (\n, \t, \")
            preg_match('/^"((?:[^"\\\\]|\\\\.)*)"/', $raw, $m);
            $value = isset($m[1]) ? stripcslashes($m[1]) : '';
        } elseif ($raw[0] === "'") {
            // Comillas simples: valor literal
            preg_match("/^'([^']*)'/", $raw, $m);
            $value = $m[1] ?? '';
        } else {
            // Sin comillas: recortar comentario inline (# precedido de espacio o tab)
            $value = $raw;
            foreach ([' #', "\t#"] as $sep) {
                if (($pos = strpos($value, $sep)) !== false) {
                    $value = substr($value, 0, $pos);
                }
            }
            $value = trim($value);
        }

        // Inmutable: no sobreescribir variable ya definida en el entorno
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


// ══════════════════════════════════════════════════════
//  CARGAR .ENV
// ══════════════════════════════════════════════════════

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
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? 'https://www.mastercodeweb.com'));
    }
    error_log('[MCW] .env error: ' . $e->getMessage());
    echo json_encode(['error' => 'Servicio no disponible. Contacta con soporte.']);
    exit;
}


// ══════════════════════════════════════════════════════
//  VENDOR / AUTOLOAD  (solo para Stripe SDK)
// ══════════════════════════════════════════════════════

$vendorPath = dirname(__DIR__) . '/vendor/autoload.php';
if (!file_exists($vendorPath)) {
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: ' . (rtrim($_ENV['SITE_URL'] ?? '', '/') ?: 'https://www.mastercodeweb.com'));
    }
    error_log('[MCW] vendor/autoload.php no encontrado — sube vendor/ a Hostinger.');
    echo json_encode(['error' => 'Dependencias del servidor no encontradas.']);
    exit;
}
require_once $vendorPath;


// ══════════════════════════════════════════════════════
//  CONSTANTES BASE
// ══════════════════════════════════════════════════════

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


// ══════════════════════════════════════════════════════
//  MÓDULOS
// ══════════════════════════════════════════════════════

try {
    require_once __DIR__ . '/stripe.php';
    require_once __DIR__ . '/rate-limiter.php';
    require_once __DIR__ . '/validator.php';
    require_once __DIR__ . '/csrf.php';
    require_once __DIR__ . '/error-handler.php';
} catch (\Throwable $e) {
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: ' . (rtrim($_ENV['SITE_URL'] ?? '', '/') ?: 'https://www.mastercodeweb.com'));
    }
    error_log('[MCW] module load error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
        'step'    => 'MODULE_LOAD_ERROR',
    ]);
    exit;
}

registerErrorHandlers();
