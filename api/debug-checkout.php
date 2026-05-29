<?php
/**
 * api/debug-checkout.php — Diagnóstico del stack de checkout
 * ⚠️  ELIMINAR junto con la excepción en .htaccess después del diagnóstico.
 *
 * Uso: GET /api/debug-checkout.php?token=MCW-DEBUG-2026
 */

const DBG_TOKEN = 'MCW-DEBUG-2026';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');
header('X-Robots-Tag: noindex');

if (($_GET['token'] ?? '') !== DBG_TOKEN) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$root   = dirname(__DIR__);
$report = [];
$errors = [];

// ── 1. vendor/autoload.php ────────────────────────────────────────
$autoloadPath             = $root . '/vendor/autoload.php';
$report['autoload_exists'] = file_exists($autoloadPath);

// ── 2. Cargar autoload y verificar clases ─────────────────────────
$report['stripe_loaded'] = false;
$report['dotenv_loaded'] = false;

if ($report['autoload_exists']) {
    try {
        require_once $autoloadPath;
        $report['stripe_loaded'] = class_exists('Stripe\\Stripe');
        $report['dotenv_loaded'] = class_exists('Dotenv\\Dotenv');
    } catch (\Throwable $e) {
        $errors[] = '[AUTOLOAD] ' . $e->getMessage()
            . ' FILE=' . $e->getFile() . ' LINE=' . $e->getLine();
    }
}

// ── 3. Leer .env directamente ─────────────────────────────────────
$report['price_basico']  = null;
$report['price_pro']     = null;
$report['price_premium'] = null;
$report['stripe_mode']   = null;

$envFile = $root . '/.env';
if (file_exists($envFile) && is_readable($envFile)) {
    try {
        $parsed = [];
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            $parsed[trim($k)] = trim($v);
        }

        $sk = $parsed['STRIPE_SECRET_KEY'] ?? '';
        $report['stripe_mode'] = match (true) {
            str_starts_with($sk, 'sk_live_') => 'live',
            str_starts_with($sk, 'sk_test_') => 'test',
            $sk === ''                         => 'NOT_SET',
            default                            => 'UNKNOWN',
        };

        foreach (['PRICE_BASICO', 'PRICE_PRO', 'PRICE_PREMIUM',
                  'PRICE_MANT_BASICO', 'PRICE_MANT_PRO', 'PRICE_MANT_PREMIUM'] as $var) {
            $val = $parsed[$var] ?? '';
            if ($val === '') {
                $errors[] = "[ENV] {$var} está vacío o no existe";
                $display  = 'MISSING';
            } elseif (str_contains($val, 'PEGA_')) {
                $errors[] = "[ENV] {$var} tiene valor placeholder";
                $display  = 'PLACEHOLDER';
            } else {
                $display = substr($val, 0, 14) . '…';
            }
            $key = strtolower(str_replace(['PRICE_MANT_', 'PRICE_'], ['price_mant_', 'price_'], $var));
            $report[$key] = $display;
        }

        foreach (['STRIPE_SECRET_KEY', 'STRIPE_WEBHOOK_SECRET', 'SITE_URL', 'APP_ENV', 'OWNER_EMAIL'] as $var) {
            $val = $parsed[$var] ?? '';
            if ($val === '') {
                $errors[] = "[ENV] {$var} está vacío";
            }
        }

    } catch (\Throwable $e) {
        $errors[] = '[ENV_PARSE] ' . $e->getMessage()
            . ' FILE=' . $e->getFile() . ' LINE=' . $e->getLine();
    }
} else {
    $errors[] = '[ENV] .env no existe o no es legible en ' . $envFile;
}

// ── 4. Intentar cargar bootstrap completo ─────────────────────────
$report['bootstrap_loaded'] = false;
$bootstrapPath = $root . '/config/bootstrap.php';

if (file_exists($bootstrapPath)) {
    try {
        require_once $bootstrapPath;
        $report['bootstrap_loaded'] = true;
        $report['stripe_mode']      = defined('STRIPE_MODE') ? STRIPE_MODE : $report['stripe_mode'];
    } catch (\Throwable $e) {
        $msg = '[' . date('c') . '] [BOOTSTRAP] '
            . $e->getMessage()
            . ' FILE=' . $e->getFile()
            . ' LINE=' . $e->getLine();
        $errors[] = $msg;
        error_log($msg);

        $logDir = $root . '/storage/logs';
        if (!is_dir($logDir)) { @mkdir($logDir, 0750, true); }
        file_put_contents($logDir . '/error.log', $msg . "\n", FILE_APPEND);
    }
} else {
    $errors[] = '[BOOTSTRAP] config/bootstrap.php no encontrado';
}

// ── 5. Test Stripe Balance (solo si bootstrap cargó) ──────────────
$report['stripe_connection'] = null;

if ($report['bootstrap_loaded'] && $report['stripe_loaded']) {
    try {
        $balance = \Stripe\Balance::retrieve();
        $report['stripe_connection'] = $balance->livemode ? 'live_ok' : 'test_ok';
    } catch (\Stripe\Exception\AuthenticationException $e) {
        $errors[] = '[STRIPE_AUTH] ' . $e->getMessage();
        $report['stripe_connection'] = 'auth_error';
    } catch (\Throwable $e) {
        $errors[] = '[STRIPE] ' . $e->getMessage();
        $report['stripe_connection'] = 'error';
    }
}

// ── 6. Resumen ────────────────────────────────────────────────────
$report['success'] = empty($errors);
$report['errors']  = $errors;
$report['verdict'] = $report['success']
    ? '✅ Stack listo para checkout'
    : '❌ ' . count($errors) . ' error(s) — revisa errors[]';

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
