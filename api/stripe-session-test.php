<?php
/**
 * api/stripe-session-test.php — diagnóstico Stripe Checkout Session
 * ⚠️  ELIMINAR después del diagnóstico.
 * Uso: GET /api/stripe-session-test.php?token=MCW-CHECK-2026
 */

// ── Estado compartido (visible desde shutdown y closures) ──────────
$__done   = false;
$__lastCp = 0;
$__logDir = dirname(__DIR__) . '/storage/logs';

// Guardamos el tamaño actual de debug.log ANTES de cargar bootstrap.
// El shutdown function leerá solo las líneas NUEVAS que bootstrap añada
// mediante _bcp() — así sabemos exactamente en qué checkpoint murió,
// incluso cuando LiteSpeed descarta los buffers ob_start antes del shutdown.
$__debugLog    = $__logDir . '/debug.log';
$__logBaseline = file_exists($__debugLog) ? (int) filesize($__debugLog) : 0;

// Archivo temporal para persistir el último CP propio.
// Sobrevive al flush de LiteSpeed porque se escribe en disco, no en buffer.
$__cpFile = sys_get_temp_dir() . '/mcw_sst_' . md5(__FILE__) . '.json';

// ── Shutdown: siempre devuelve JSON ───────────────────────────────
register_shutdown_function(function () use (
    &$__done, &$__lastCp,
    $__cpFile, $__logDir, $__debugLog, $__logBaseline
): void {
    if ($__done) {
        return;
    }

    // Leer el CP más reciente del archivo temporal
    if (file_exists($__cpFile)) {
        $saved    = json_decode((string) @file_get_contents($__cpFile), true) ?? [];
        $__lastCp = (int) ($saved['cp'] ?? $__lastCp);
        @unlink($__cpFile);
    }

    // Vaciar buffers (en LiteSpeed suelen estar vacíos, pero se intenta)
    $captured = '';
    while (ob_get_level() > 0) {
        $captured .= (string) ob_get_clean();
    }

    // Leer las líneas NUEVAS que bootstrap escribió en debug.log
    $bootstrapLog = [];
    if (file_exists($__debugLog)) {
        $newSize = (int) filesize($__debugLog);
        if ($newSize > $__logBaseline) {
            $fh = @fopen($__debugLog, 'r');
            if ($fh) {
                fseek($fh, $__logBaseline);
                $raw = (string) fread($fh, 3000);
                fclose($fh);
                $bootstrapLog = array_values(array_filter(
                    array_map('trim', explode("\n", $raw))
                ));
            }
        }
    }

    $fatal = error_get_last();

    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
    }

    $out = [
        'success' => false,
        'message' => 'Script abortado en CP' . $__lastCp
            . ' (bootstrap llamó exit() o error fatal)',
        'last_cp' => $__lastCp,
        'file'    => null,
        'line'    => null,
        'trace'   => null,
    ];

    if ($bootstrapLog !== []) {
        $out['bootstrap_log'] = $bootstrapLog;
    }

    if ($captured !== '') {
        $decoded = json_decode($captured, true);
        $out['bootstrap_output'] = is_array($decoded)
            ? $decoded
            : substr($captured, 0, 500);
    }

    if ($fatal && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $out['fatal'] = [
            'message' => $fatal['message'],
            'file'    => basename($fatal['file']),
            'line'    => $fatal['line'],
        ];
    }

    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

// ── Helper CP ─────────────────────────────────────────────────────
$_cp = function (int $n, string $detail) use (&$__lastCp, $__cpFile, $__logDir): void {
    $__lastCp = $n;
    @file_put_contents(
        $__cpFile,
        json_encode(['cp' => $n, 'detail' => $detail, 'ts' => date('c')])
    );
    $msg = "[STRIPE-TEST CP{$n}] {$detail}";
    error_log($msg);
    if (!is_dir($__logDir)) { @mkdir($__logDir, 0750, true); }
    @file_put_contents($__logDir . '/stripe.log', date('c') . " {$msg}\n", FILE_APPEND | LOCK_EX);
};

// ── Headers propios ───────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

if (($_GET['token'] ?? '') !== 'MCW-CHECK-2026') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'forbidden']);
    $__done = true;
    exit;
}

// ── CP0 → CP1: bootstrap ──────────────────────────────────────────
$_cp(0, 'antes de bootstrap');
ob_start();

try {
    require_once dirname(__DIR__) . '/config/bootstrap.php';
    ob_end_clean();
    $_cp(1, 'bootstrap OK'
        . ' · APP_ENV='    . (defined('APP_ENV')    ? APP_ENV    : '?')
        . ' · STRIPE_MODE=' . (defined('STRIPE_MODE') ? STRIPE_MODE : '?'));
} catch (\Throwable $e) {
    ob_end_clean();
    $_cp(1, 'bootstrap EXCEPTION: ' . $e->getMessage());
    $__done = true;
    if (!headers_sent()) { http_response_code(500); }
    echo json_encode([
        'success' => false,
        'message' => 'bootstrap exception: ' . $e->getMessage(),
        'file'    => basename($e->getFile()),
        'line'    => $e->getLine(),
        'trace'   => substr($e->getTraceAsString(), 0, 800),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}
// Nota: si bootstrap llamó exit() sin excepción, la ejecución termina aquí.
// El shutdown function devuelve JSON con last_cp=0 y bootstrap_log con los
// _bcp() que bootstrap alcanzó antes de morir.

// ── CP2: Stripe SDK ───────────────────────────────────────────────
$sdkOk = class_exists('Stripe\\Checkout\\Session');
$_cp(2, 'Stripe SDK=' . ($sdkOk ? 'OK' : 'MISSING'));

// ── CP3: PRICE_BASICO ─────────────────────────────────────────────
$priceId = defined('PRICE_BASICO') ? PRICE_BASICO : '';
$_cp(3, 'PRICE_BASICO=' . ($priceId !== '' ? substr($priceId, 0, 12) . '…' : 'VACÍO'));

if ($priceId === '') {
    $__done = true;
    echo json_encode([
        'success' => false,
        'message' => 'PRICE_BASICO está vacío tras bootstrap.',
        'file'    => null,
        'line'    => null,
        'trace'   => null,
    ]);
    exit;
}

// ── CP4: Session::create ──────────────────────────────────────────
$_cp(4, 'antes de Stripe Session::create · price=' . substr($priceId, 0, 12));

try {
    $session = \Stripe\Checkout\Session::create([
        'payment_method_types' => ['card'],
        'line_items'           => [['price' => $priceId, 'quantity' => 1]],
        'mode'                 => 'payment',
        'success_url'          => str_replace('{PLAN}', 'basico', SUCCESS_URL),
        'cancel_url'           => CANCEL_URL . '?plan=basico',
        'locale'               => 'es',
        'metadata'             => ['source' => 'stripe-session-test', 'env' => APP_ENV],
    ]);

    $_cp(5, 'session OK · id=' . $session->id . ' · livemode=' . ($session->livemode ? 'yes' : 'no'));

    $__done = true;
    echo json_encode([
        'success'    => true,
        'session_id' => $session->id,
        'url'        => $session->url,
    ], JSON_UNESCAPED_SLASHES);

} catch (\Throwable $e) {
    $_cp(5, 'Session::create FAILED · ' . $e->getMessage());
    $__done = true;
    if (!headers_sent()) { http_response_code(502); }
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'file'    => basename($e->getFile()),
        'line'    => $e->getLine(),
        'trace'   => substr($e->getTraceAsString(), 0, 800),
    ], JSON_UNESCAPED_SLASHES);
}
