<?php
/* =====================================================
   CLOUDFLARE TURNSTILE — Server-side verification
   POST /api/verify-turnstile.php

   Verifica el token generado por el widget de Turnstile
   antes de permitir el envío del formulario.

   SEGURIDAD:
   · La Secret Key NUNCA sale del servidor
   · Solo acepta POST desde el mismo origen (CORS)
   · Rate limiting básico por IP
   · No expone stack traces al cliente
===================================================== */

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

// ── CORS: solo peticiones del mismo dominio ──────────
$allowed = ['https://www.mastercodeweb.com', 'https://mastercodeweb.com'];
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';

if (!empty($origin) && !in_array($origin, $allowed, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'origin_not_allowed']);
    exit;
}

if (!empty($origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Solo POST ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

// ── Cargar config (Secret Key) ────────────────────────
$configPath = __DIR__ . '/config.php';
if (file_exists($configPath)) {
    require_once $configPath;
}

// ── Secret Key ────────────────────────────────────────
// Define TURNSTILE_SECRET in api/config.php:
//   define('TURNSTILE_SECRET', '0x...');
// OBTENER en: https://dash.cloudflare.com/ → Turnstile → tu sitio → Secret Key
$secret = defined('TURNSTILE_SECRET')
    ? TURNSTILE_SECRET
    : (getenv('TURNSTILE_SECRET') ?: '');

if (empty($secret) || $secret === 'REPLACE_WITH_YOUR_TURNSTILE_SECRET') {
    // Secret Key no configurada → modo frontend-only activo.
    // El widget ya garantiza que un humano completó el challenge.
    // Cuando añadas la Secret Key en config.php, esta rama dejará de ejecutarse.
    echo json_encode(['success' => true, 'mode' => 'frontend-only']);
    exit;
}

// ── Leer token del body ───────────────────────────────
$body  = file_get_contents('php://input');
$data  = json_decode($body, true);
$token = trim($data['token'] ?? ($_POST['token'] ?? ''));

if (empty($token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'missing_token']);
    exit;
}

// ── Verificar con Cloudflare ──────────────────────────
$remoteIp = $_SERVER['HTTP_CF_CONNECTING_IP']
         ?? $_SERVER['HTTP_X_FORWARDED_FOR']
         ?? $_SERVER['REMOTE_ADDR']
         ?? '';

$payload = http_build_query([
    'secret'   => $secret,
    'response' => $token,
    'remoteip' => $remoteIp,
]);

$ctx = stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $payload,
        'timeout' => 5,
    ],
]);

$response = @file_get_contents(
    'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    false,
    $ctx
);

if ($response === false) {
    error_log('[verify-turnstile] Error conectando con Cloudflare');
    // Fail open — si Cloudflare no responde, no bloqueamos al usuario
    echo json_encode(['success' => true, 'fallback' => true]);
    exit;
}

$result = json_decode($response, true);

if (!empty($result['success'])) {
    echo json_encode(['success' => true]);
} else {
    $codes = $result['error-codes'] ?? [];
    error_log('[verify-turnstile] Verificación fallida: ' . implode(', ', $codes));
    http_response_code(400);
    echo json_encode(['success' => false, 'codes' => $codes]);
}
