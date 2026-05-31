<?php
/* =====================================================
   CLOUDFLARE TURNSTILE — Server-side verification
   POST /api/verify-turnstile.php

   SEGURIDAD:
   · Secret Key nunca expuesta al cliente
   · CORS estricto (www + non-www del dominio canónico)
   · Rate limit: 20 req/min por IP
   · Token sanitizado antes de enviar a Cloudflare
   · Fail-open si Cloudflare no responde (con 1 retry)
   · Logs estructurados — sin datos sensibles
===================================================== */

@ini_set('display_errors', '0');

// Config primero: define SITE_URL, TURNSTILE_SECRET, helpers
require_once __DIR__ . '/config.php';

// ── CORS estricto ─────────────────────────────────────
// Acepta www y non-www porque el .htaccess redirige 301 pero
// los requests AJAX pueden llegar desde cualquiera de los dos orígenes.
$origin        = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = [SITE_URL, str_replace('://www.', '://', SITE_URL)];

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($origin !== '' && !in_array($origin, $allowedOrigins, true)) {
    appLog('WARNING', 'turnstile', 'Origen no permitido', [
        'origin' => $origin,
        'ip'     => clientIp(),
    ]);
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'origin_not_allowed']);
    exit;
}

if ($origin !== '') {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// ── Preflight + método ────────────────────────────────
validateMethod('POST');

// ── Rate limit: 20 req/min por IP ─────────────────────
rateLimitIp('turnstile', 20, 60);

// ── Secret Key ────────────────────────────────────────
if (TURNSTILE_SECRET === '' || TURNSTILE_SECRET === 'REPLACE_WITH_YOUR_TURNSTILE_SECRET') {
    // Sin configurar → fail-open con modo explícito
    // El widget Turnstile ya garantizó el challenge en el frontend
    echo json_encode(['success' => true, 'mode' => 'frontend-only']);
    exit;
}

// ── Leer y validar token ──────────────────────────────
$data  = json_decode((string) file_get_contents('php://input'), true) ?? [];
$token = sanitize($data['token'] ?? ($_POST['token'] ?? ''), 2048);

if ($token === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'missing_token']);
    exit;
}

// Sanity check básico: tokens Turnstile son base64url, longitud ~500–2000 chars
if (strlen($token) < 20 || !preg_match('/^[A-Za-z0-9._\-]+$/', $token)) {
    appLog('WARNING', 'turnstile', 'Token con formato inválido', ['ip' => clientIp()]);
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_token_format']);
    exit;
}

// ── Verificar con Cloudflare (1 retry en timeout) ─────
$cfResult = callCloudflare(TURNSTILE_SECRET, $token, clientIp());

if ($cfResult === null) {
    // Cloudflare no respondió en 2 intentos → fail-open
    appLog('WARNING', 'turnstile', 'Cloudflare sin respuesta — fail-open', ['ip' => clientIp()]);
    echo json_encode(['success' => true, 'fallback' => true]);
    exit;
}

if (!empty($cfResult['success'])) {
    appLog('INFO', 'turnstile', 'Verificación OK', [
        'hostname' => $cfResult['hostname'] ?? null,
        'mode'     => STRIPE_MODE,
    ]);
    echo json_encode(['success' => true]);
} else {
    $codes = array_map('strval', $cfResult['error-codes'] ?? []);
    appLog('WARNING', 'turnstile', 'Verificación fallida', [
        'codes' => $codes,
        'ip'    => clientIp(),
    ]);
    http_response_code(400);
    echo json_encode(['success' => false, 'codes' => $codes]);
}


// ══════════════════════════════════════════════════════
//  HELPER LOCAL
// ══════════════════════════════════════════════════════

/**
 * Llama a Cloudflare Turnstile siteverify con 1 reintento en caso de timeout.
 * Devuelve el array decodificado o null si ambos intentos fallan.
 */
function callCloudflare(string $secret, string $token, string $ip, int $maxAttempts = 2): ?array
{
    $payload = http_build_query([
        'secret'   => $secret,
        'response' => $token,
        'remoteip' => $ip,
    ]);

    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $payload,
        'timeout' => 5,
        'ignore_errors' => true,   // captura respuestas 4xx/5xx sin warning
    ]]);

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $raw = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $ctx);

        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        if ($attempt < $maxAttempts) {
            usleep(500_000); // 0.5 s antes del reintento
        }
    }

    return null; // ambos intentos fallaron
}
