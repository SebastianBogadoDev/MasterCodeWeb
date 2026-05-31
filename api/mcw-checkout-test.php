<?php
/**
 * api/mcw-checkout-test.php — Diagnóstico completo del stack de checkout
 *
 * ⚠️  ELIMINAR después de verificar producción.
 *
 * Uso: GET /api/mcw-checkout-test.php?token=MCW-TEST-2026
 *      Cambia el token antes de subir a Hostinger.
 */

const MCW_TEST_TOKEN = 'MCW-TEST-2026';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store, no-cache');

if (($_GET['token'] ?? '') !== MCW_TEST_TOKEN) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$root   = dirname(__DIR__);
$report = [
    'timestamp'  => date('Y-m-d H:i:s T'),
    'php_version' => PHP_VERSION,
    'php_ok'     => version_compare(PHP_VERSION, '8.0.0', '>='),
    'server'     => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
    'paths'      => [],
    'files'      => [],
    'env_vars'   => [],
    'bootstrap'  => ['loaded' => false, 'error' => null],
    'stripe'     => [],
    'errors'     => [],
    'ready'      => false,
];

// ── 1. Rutas clave ──────────────────────────────────────────────────
$report['paths'] = [
    'root_dir'    => $root,
    'env_file'    => $root . '/.env',
    'vendor'      => $root . '/vendor/autoload.php',
    'bootstrap'   => $root . '/config/bootstrap.php',
    'stripe_cfg'  => $root . '/config/stripe.php',
];

// ── 2. Existencia de archivos críticos ─────────────────────────────
$report['files'] = [
    'env_exists'        => file_exists($root . '/.env'),
    'env_readable'      => is_readable($root . '/.env'),
    'vendor_exists'     => file_exists($root . '/vendor/autoload.php'),
    'bootstrap_exists'  => file_exists($root . '/config/bootstrap.php'),
    'stripe_cfg_exists' => file_exists($root . '/config/stripe.php'),
    'vendor_stripe'     => is_dir($root . '/vendor/stripe'),
    'vendor_vlucas'     => is_dir($root . '/vendor/vlucas'),
];

// ── 3. Leer .env sin bootstrap ─────────────────────────────────────
if ($report['files']['env_readable']) {
    try {
        $lines = file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $parsed = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            $parsed[trim($k)] = trim($v);
        }

        $required = [
            'APP_ENV', 'SITE_URL',
            'STRIPE_SECRET_KEY', 'STRIPE_PUBLIC_KEY', 'STRIPE_WEBHOOK_SECRET',
            'PRICE_BASICO', 'PRICE_PRO', 'PRICE_PREMIUM',
            'PRICE_MANT_BASICO', 'PRICE_MANT_PRO', 'PRICE_MANT_PREMIUM',
            'OWNER_EMAIL',
        ];

        foreach ($required as $var) {
            $val = $parsed[$var] ?? null;
            if ($val === null || $val === '') {
                $report['env_vars'][$var] = '⚠️ MISSING';
                $report['errors'][]       = "Variable faltante en .env: {$var}";
            } else {
                $isSensitive = str_contains($var, 'KEY') || str_contains($var, 'SECRET');
                if (str_contains($val, 'PEGA_')) {
                    $report['env_vars'][$var] = '❌ PLACEHOLDER — actualiza .env';
                    $report['errors'][]       = "Valor placeholder en .env: {$var}";
                } else {
                    $report['env_vars'][$var] = $isSensitive
                        ? substr($val, 0, 8) . '…[len=' . strlen($val) . ']'
                        : $val;
                }
            }
        }

        $sk = $parsed['STRIPE_SECRET_KEY'] ?? '';
        $report['stripe']['key_prefix'] = $sk ? substr($sk, 0, 7) : 'NOT SET';
        $report['stripe']['mode_from_key'] = match (true) {
            str_starts_with($sk, 'sk_live_') => 'LIVE ✅',
            str_starts_with($sk, 'sk_test_') => 'TEST ⚠️',
            default                           => 'UNKNOWN ❌',
        };

    } catch (\Throwable $e) {
        $report['errors'][] = '.env parse error: ' . $e->getMessage();
    }
}

// ── 4. Cargar vendor + verificar clases ────────────────────────────
if ($report['files']['vendor_exists']) {
    try {
        require_once $root . '/vendor/autoload.php';
        $report['files']['vendor_loaded'] = true;
        $report['files']['class_stripe']  = class_exists('Stripe\\Stripe')    ? '✅' : '❌ no encontrada';
        $report['files']['class_dotenv']  = class_exists('Dotenv\\Dotenv')    ? '✅' : '— no instalada (no necesaria)';
    } catch (\Throwable $e) {
        $report['files']['vendor_loaded'] = false;
        $report['errors'][] = 'vendor/autoload.php error: ' . $e->getMessage();
    }
} else {
    $report['errors'][] = 'vendor/autoload.php NO ENCONTRADO — sube vendor/ a Hostinger';
}

// ── 5. Intentar cargar bootstrap completo ─────────────────────────
if ($report['files']['bootstrap_exists']) {
    try {
        require_once $root . '/config/bootstrap.php';
        $report['bootstrap']['loaded']  = true;
        $report['bootstrap']['app_env'] = defined('APP_ENV')   ? APP_ENV   : '❌ sin definir';
        $report['bootstrap']['site_url']= defined('SITE_URL')  ? SITE_URL  : '❌ sin definir';
        $report['bootstrap']['mode']    = defined('STRIPE_MODE') ? STRIPE_MODE : '❌ sin definir';

        foreach (['PRICE_BASICO','PRICE_PRO','PRICE_PREMIUM','PRICE_MANT_BASICO','PRICE_MANT_PRO','PRICE_MANT_PREMIUM'] as $p) {
            if (!defined($p)) {
                $report['stripe']['prices'][$p] = '❌ sin definir';
                $report['errors'][] = "Constante no definida: {$p}";
            } else {
                $v = constant($p);
                $report['stripe']['prices'][$p] = str_contains($v, 'PEGA_')
                    ? '❌ placeholder'
                    : (substr($v, 0, 12) . '…');
            }
        }

    } catch (\Throwable $e) {
        $report['bootstrap']['error'] = $e->getMessage();
        $report['bootstrap']['file']  = basename($e->getFile()) . ':' . $e->getLine();
        $report['errors'][] = 'bootstrap.php lanzó excepción: ' . $e->getMessage()
            . ' en ' . basename($e->getFile()) . ':' . $e->getLine();
    }
} else {
    $report['errors'][] = 'config/bootstrap.php NO ENCONTRADO';
}

// ── 6. Test de conexión Stripe (solo si bootstrap cargó) ───────────
if ($report['bootstrap']['loaded'] && class_exists('Stripe\\Stripe')) {
    try {
        $balance = \Stripe\Balance::retrieve();
        $report['stripe']['connection']   = '✅ OK';
        $report['stripe']['currency']     = $balance->available[0]->currency ?? '?';
        $report['stripe']['livemode']     = $balance->livemode ? 'LIVE' : 'TEST';
    } catch (\Stripe\Exception\AuthenticationException $e) {
        $report['stripe']['connection']  = '❌ AuthError — clave incorrecta';
        $report['errors'][] = 'Stripe auth error: ' . $e->getMessage();
    } catch (\Throwable $e) {
        $report['stripe']['connection']  = '❌ ' . $e->getMessage();
        $report['errors'][] = 'Stripe connection error: ' . $e->getMessage();
    }
}

// ── 7. Simulación del body de checkout ─────────────────────────────
$report['checkout_simulation'] = [];
$testCases = [
    ['plan' => 'basico',      'tipo' => 'unico'],
    ['plan' => 'profesional', 'tipo' => 'unico'],
    ['plan' => 'premium',     'tipo' => 'unico'],
    ['plan' => 'basico',      'tipo' => 'mensual'],
];
if ($report['bootstrap']['loaded']) {
    foreach ($testCases as $tc) {
        $key = $tc['tipo'] === 'mensual' ? "mant-{$tc['plan']}" : $tc['plan'];
        if (!defined('PLANS') || !isset(PLANS[$key])) {
            $report['checkout_simulation'][$key] = '❌ clave no en PLANS';
        } else {
            [$priceId, $mode] = PLANS[$key];
            if (empty($priceId) || str_contains($priceId, 'PEGA_')) {
                $report['checkout_simulation'][$key] = "❌ price ID inválido: {$priceId}";
                $report['errors'][] = "Plan '{$key}': price ID inválido";
            } else {
                $report['checkout_simulation'][$key] = "✅ {$mode} → " . substr($priceId, 0, 12) . '…';
            }
        }
    }
}

// ── 8. Resumen final ───────────────────────────────────────────────
$report['ready'] = empty($report['errors'])
    && $report['bootstrap']['loaded']
    && ($report['stripe']['connection'] ?? '') === '✅ OK';

$report['verdict'] = $report['ready']
    ? '✅ SISTEMA LISTO — checkout debería funcionar'
    : '❌ ERRORES DETECTADOS — revisa $report[errors]';

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
