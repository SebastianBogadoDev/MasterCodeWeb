<?php
/**
 * api/mcw-diag.php — Diagnóstico de configuración Stripe + .env
 *
 * ⚠️  ELIMINAR ESTE ARCHIVO después de verificar producción.
 *     Nunca debe quedar accesible en un servidor live sin el token.
 *
 * Uso: GET /api/mcw-diag.php?token=MCW_DEBUG_TOKEN
 *      Cambia el token antes de subir a Hostinger.
 */

// ── Protección: token requerido ───────────────────────
// Cambia este valor antes de subir. Usa uno difícil de adivinar.
const DEBUG_TOKEN = 'MCW-DEBUG-2026-CHANGE-ME';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

if (($_GET['token'] ?? '') !== DEBUG_TOKEN) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

// ── Rutas clave ───────────────────────────────────────
$rootDir   = dirname(__DIR__);          // public_html/ en Hostinger
$envPath   = $rootDir . '/.env';
$vendorPath = $rootDir . '/vendor/autoload.php';

$out = [
    'php_version'    => PHP_VERSION,
    'sapi'           => PHP_SAPI,
    'root_dir'       => $rootDir,
    'env_path'       => $envPath,
    'env_exists'     => file_exists($envPath),
    'env_readable'   => is_readable($envPath),
    'vendor_exists'  => file_exists($vendorPath),
    'dotenv_loaded'  => false,
    'dotenv_error'   => null,
    'vars'           => [],
    'stripe'         => [],
    'warnings'       => [],
];

// ── Cargar vendor ─────────────────────────────────────
if (!$out['vendor_exists']) {
    $out['warnings'][] = 'vendor/autoload.php no encontrado. Ejecuta composer install localmente y sube vendor/.';
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

require_once $vendorPath;

// ── Cargar .env con phpdotenv ─────────────────────────
if (!$out['env_exists']) {
    $out['warnings'][] = '.env no encontrado en ' . $envPath . '. Súbelo manualmente via Hostinger File Manager.';
} else {
    try {
        $dotenv = Dotenv\Dotenv::createImmutable($rootDir);
        $dotenv->load();
        $out['dotenv_loaded'] = true;
    } catch (\Throwable $e) {
        $out['dotenv_error'] = $e->getMessage();
        $out['warnings'][] = 'phpdotenv lanzó excepción al cargar .env.';
    }
}

// ── Variables de entorno (nunca mostrar valores completos de claves) ──
$envVars = [
    'APP_ENV', 'APP_URL', 'SITE_URL',
    'STRIPE_SECRET_KEY', 'STRIPE_PUBLIC_KEY', 'STRIPE_WEBHOOK_SECRET',
    'PRICE_BASICO', 'PRICE_PRO', 'PRICE_PREMIUM',
    'PRICE_MANT_BASICO', 'PRICE_MANT_PRO', 'PRICE_MANT_PREMIUM',
    'OWNER_EMAIL', 'TURNSTILE_SECRET',
];

foreach ($envVars as $var) {
    $val = $_ENV[$var] ?? getenv($var) ?: null;

    if ($val === null) {
        $out['vars'][$var] = 'NOT SET ⚠️';
        $out['warnings'][] = "Variable requerida no encontrada: $var";
        continue;
    }

    // Truncar valores sensibles: mostrar solo prefijo + longitud
    $isSensitive = str_contains($var, 'KEY') || str_contains($var, 'SECRET');
    $out['vars'][$var] = $isSensitive
        ? substr($val, 0, 10) . '... [len=' . strlen($val) . ']'
        : $val;
}

// ── Diagnóstico Stripe ────────────────────────────────
$secretKey = $_ENV['STRIPE_SECRET_KEY'] ?? '';
$pubKey    = $_ENV['STRIPE_PUBLIC_KEY']  ?? '';

$out['stripe'] = [
    'secret_prefix'         => $secretKey ? substr($secretKey, 0, 8) : 'NOT SET',
    'public_prefix'         => $pubKey    ? substr($pubKey,    0, 8) : 'NOT SET',
    'mode'                  => match (true) {
                                  str_starts_with($secretKey, 'sk_live_') => 'LIVE ✅',
                                  str_starts_with($secretKey, 'sk_test_') => 'TEST ⚠️',
                                  default                                  => 'UNKNOWN ❌',
                               },
    'expected_session_prefix'=> str_starts_with($secretKey, 'sk_live_') ? 'cs_live_' : 'cs_test_',
    'webhook_secret_set'    => !empty($_ENV['STRIPE_WEBHOOK_SECRET'] ?? ''),
    'price_prefixes'        => array_map(
        fn($k) => $k . ': ' . substr($_ENV[$k] ?? 'NOT SET', 0, 10),
        ['PRICE_BASICO', 'PRICE_PRO', 'PRICE_PREMIUM',
         'PRICE_MANT_BASICO', 'PRICE_MANT_PRO', 'PRICE_MANT_PREMIUM']
    ),
];

// ── Bloqueo producción ────────────────────────────────
$appEnv = $_ENV['APP_ENV'] ?? '';
if ($appEnv === 'production') {
    $out['warnings'][] = '🚨 APP_ENV=production detectado. ELIMINA ESTE ARCHIVO AHORA.';
}

// ── Resumen ───────────────────────────────────────────
$out['ready_for_live'] = (
    $out['dotenv_loaded'] &&
    str_starts_with($secretKey, 'sk_live_') &&
    str_starts_with($pubKey,    'pk_live_') &&
    $appEnv === 'production' &&
    empty(array_filter($out['vars'], fn($v) => str_contains($v, 'NOT SET')))
);

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
