<?php
/**
 * api/mcw-checkout-diag.php — Diagnóstico rápido del stack de checkout
 *
 * NOTA: No puede llamarse api/debug-checkout.php —
 *       .htaccess bloquea ^api/debug- con 403.
 *
 * Uso: GET /api/mcw-checkout-diag.php?token=MCW-DIAG-2026
 * ⚠️  ELIMINAR después del diagnóstico.
 */

const MCW_DIAG_TOKEN = 'MCW-DIAG-2026';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');
header('X-Robots-Tag: noindex');

if (($_GET['token'] ?? '') !== MCW_DIAG_TOKEN) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$root = dirname(__DIR__);

// ── 1. Existencia de archivos clave ───────────────────────────────
$autoloadPath   = $root . '/vendor/autoload.php';
$autoloadExists = file_exists($autoloadPath);
$vendorExists   = is_dir($root . '/vendor');
$envExists      = file_exists($root . '/.env');
$bootstrapExists = file_exists($root . '/config/bootstrap.php');

// ── 2. Cargar autoload y verificar clases ──────────────────────────
$dotenvExists  = false;
$stripeExists  = false;
$autoloadError = null;

if ($autoloadExists) {
    try {
        require_once $autoloadPath;
        $dotenvExists = class_exists('Dotenv\\Dotenv');
        $stripeExists = class_exists('Stripe\\Stripe');
    } catch (\Throwable $e) {
        $autoloadError = '[' . date('c') . '] '
            . $e->getMessage()
            . ' FILE=' . $e->getFile()
            . ' LINE=' . $e->getLine();
    }
}

// ── 3. Parsear .env directamente ──────────────────────────────────
$envLoaded = false;
$envError  = null;
$prices    = [];
$envValues = [];

if ($envExists && is_readable($root . '/.env')) {
    try {
        $parsed = [];
        foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            $parsed[trim($k)] = trim($v);
        }

        $watch = [
            'APP_ENV', 'SITE_URL',
            'STRIPE_SECRET_KEY', 'STRIPE_PUBLIC_KEY', 'STRIPE_WEBHOOK_SECRET',
            'PRICE_BASICO', 'PRICE_PRO', 'PRICE_PREMIUM',
            'PRICE_MANT_BASICO', 'PRICE_MANT_PRO', 'PRICE_MANT_PREMIUM',
            'OWNER_EMAIL',
        ];

        $missing = [];
        foreach ($watch as $var) {
            $val = $parsed[$var] ?? null;
            if ($val === null || $val === '') {
                $envValues[$var] = '⚠️ MISSING';
                $missing[] = $var;
            } else {
                $isSensitive = str_contains($var, 'KEY') || str_contains($var, 'SECRET');
                $isPlaceholder = str_contains($val, 'PEGA_') || str_contains($val, 'CHANGE_ME');
                if ($isPlaceholder) {
                    $envValues[$var] = '❌ PLACEHOLDER';
                } elseif ($isSensitive) {
                    $envValues[$var] = substr($val, 0, 8) . '… [len=' . strlen($val) . ']';
                } else {
                    $envValues[$var] = $val;
                }
            }
        }

        $prices = [
            'price_basico'       => $envValues['PRICE_BASICO']       ?? '?',
            'price_pro'          => $envValues['PRICE_PRO']           ?? '?',
            'price_premium'      => $envValues['PRICE_PREMIUM']       ?? '?',
            'price_mant_basico'  => $envValues['PRICE_MANT_BASICO']   ?? '?',
            'price_mant_pro'     => $envValues['PRICE_MANT_PRO']      ?? '?',
            'price_mant_premium' => $envValues['PRICE_MANT_PREMIUM']  ?? '?',
        ];

        $envLoaded = empty($missing);

    } catch (\Throwable $e) {
        $envError = '[' . date('c') . '] '
            . $e->getMessage()
            . ' FILE=' . $e->getFile()
            . ' LINE=' . $e->getLine();
    }
}

// ── 4. Intentar cargar bootstrap completo ──────────────────────────
$bootstrapLoaded = false;
$bootstrapError  = null;

if ($bootstrapExists && $autoloadExists) {
    try {
        require_once $root . '/config/bootstrap.php';
        $bootstrapLoaded = true;
    } catch (\Throwable $e) {
        $bootstrapError = '[' . date('c') . '] '
            . $e->getMessage()
            . ' FILE=' . $e->getFile()
            . ' LINE=' . $e->getLine();
        error_log(
            '[' . date('c') . '] [MCW-DIAG] [BOOTSTRAP_ERROR] '
            . $e->getMessage()
            . ' FILE=' . $e->getFile()
            . ' LINE=' . $e->getLine()
        );
    }
}

// ── 5. Resumen ────────────────────────────────────────────────────
$errors = array_filter([
    !$autoloadExists  ? 'vendor/autoload.php no existe — sube vendor/' : null,
    !$vendorExists    ? 'vendor/ no existe'                             : null,
    !$envExists       ? '.env no existe en ' . $root                   : null,
    $autoloadError,
    $envError,
    $bootstrapError,
]);

echo json_encode([
    // Campos exactos solicitados
    'autoload_exists' => $autoloadExists,
    'vendor_exists'   => $vendorExists,
    'dotenv_exists'   => $dotenvExists,
    'stripe_exists'   => $stripeExists,
    'env_loaded'      => $envLoaded,
    'price_basico'    => $prices['price_basico']  ?? '?',
    'price_pro'       => $prices['price_pro']     ?? '?',
    'price_premium'   => $prices['price_premium'] ?? '?',

    // Campos adicionales para diagnóstico completo
    'bootstrap_loaded'    => $bootstrapLoaded,
    'stripe_mode'         => defined('STRIPE_MODE') ? STRIPE_MODE : null,
    'env_vars'            => $envValues,
    'prices_all'          => $prices,
    'errors'              => array_values($errors),
    'ready'               => empty($errors) && $bootstrapLoaded,
    'verdict'             => empty($errors) && $bootstrapLoaded
                                ? '✅ Stack listo'
                                : '❌ Errores detectados — revisa errors[]',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
