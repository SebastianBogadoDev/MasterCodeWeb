<?php
/**
 * api/env-check.php — Diagnóstico exacto de variables de entorno
 * ⚠️  ELIMINAR después del diagnóstico.
 *
 * Uso: GET /api/env-check.php?token=MCW-ENV-2026
 */

const ENV_TOKEN = 'MCW-ENV-2026';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');
header('X-Robots-Tag: noindex');

if (($_GET['token'] ?? '') !== ENV_TOKEN) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$root    = dirname(__DIR__);
$envFile = $root . '/.env';
$result  = [];

// ── 1. Existencia del archivo .env ────────────────────────────────
$result['env_path']       = $envFile;
$result['env_file_exists']= file_exists($envFile);
$result['env_readable']   = is_readable($envFile);

// ── 2. Leer el archivo .env directamente ─────────────────────────
$rawValues = [];

if ($result['env_readable']) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $rawValues[trim($k)] = trim($v);
    }
}

$priceKeys = [
    'PRICE_BASICO', 'PRICE_PRO', 'PRICE_PREMIUM',
    'PRICE_MANT_BASICO', 'PRICE_MANT_PRO', 'PRICE_MANT_PREMIUM',
];

foreach ($priceKeys as $key) {
    $result['raw_file'][$key] = $rawValues[$key] ?? '⚠️ NOT_IN_FILE';
}

// ── 3. Estado de $_ENV antes de cargar bootstrap ──────────────────
foreach ($priceKeys as $key) {
    $envVal    = $_ENV[$key] ?? 'NOT_SET';
    $getenvVal = getenv($key);
    $result['before_bootstrap']['$_ENV'][$key]   = $envVal   === '' ? '(empty string)' : $envVal;
    $result['before_bootstrap']['getenv'][$key]  = $getenvVal === false ? 'false/NOT_SET' : ($getenvVal === '' ? '(empty string)' : $getenvVal);
}

// ── 4. PHP config relevante ───────────────────────────────────────
$result['php']['variables_order'] = ini_get('variables_order');
$result['php']['version']         = PHP_VERSION;
$result['php']['sapi']            = PHP_SAPI;

// ── 5. Cargar bootstrap y ver el resultado ────────────────────────
$result['bootstrap_loaded'] = false;
$result['bootstrap_error']  = null;

try {
    require_once $root . '/config/bootstrap.php';
    $result['bootstrap_loaded'] = true;
} catch (\Throwable $e) {
    $result['bootstrap_error'] = $e->getMessage() . ' FILE=' . $e->getFile() . ' LINE=' . $e->getLine();
}

// ── 6. Estado tras bootstrap ──────────────────────────────────────
foreach ($priceKeys as $key) {
    $envVal    = $_ENV[$key] ?? 'NOT_SET';
    $getenvVal = getenv($key);
    $constVal  = defined($key) ? constant($key) : 'CONSTANT_NOT_DEFINED';

    $result['after_bootstrap']['$_ENV'][$key]    = $envVal   === '' ? '(empty string)' : $envVal;
    $result['after_bootstrap']['getenv'][$key]   = $getenvVal === false ? 'false/NOT_SET' : ($getenvVal === '' ? '(empty string)' : $getenvVal);
    $result['after_bootstrap']['constant'][$key] = $constVal === '' ? '(empty string)' : $constVal;
}

// ── 7. Valores finales cortos para diagnóstico rápido ─────────────
foreach ($priceKeys as $key) {
    $constVal = defined($key) ? constant($key) : '';
    $result[$key] = match(true) {
        $constVal === ''               => '❌ EMPTY',
        str_contains($constVal, 'PEGA_') => '❌ PLACEHOLDER: ' . $constVal,
        default                        => '✅ ' . substr($constVal, 0, 14) . '…',
    };
}

// ── 8. Campos exactos solicitados ─────────────────────────────────
$result['PRICE_BASICO_ENV']    = $result['after_bootstrap']['$_ENV']['PRICE_BASICO']   ?? '?';
$result['PRICE_BASICO_GETENV'] = $result['after_bootstrap']['getenv']['PRICE_BASICO']  ?? '?';
$result['PRICE_PRO']           = defined('PRICE_PRO')     ? constant('PRICE_PRO')     : '❌ NOT_DEFINED';
$result['PRICE_PREMIUM']       = defined('PRICE_PREMIUM') ? constant('PRICE_PREMIUM') : '❌ NOT_DEFINED';

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
