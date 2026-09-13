<?php
/* =====================================================
   REVIEWS API — MasterCodeWeb
   POST /api/reviews.php

   Recibe nueva reseña, valida todos los campos,
   verifica Turnstile anti-spam y guarda en estado
   'pending' para moderación manual antes de publicar.

   NUNCA publica automáticamente.
   NUNCA expone emails públicamente.

   Seguridad activa:
   · Validación y sanitización de todos los campos
   · Verificación Turnstile (mismo patrón que verify-turnstile.php)
   · Rate limiting: máx 2 envíos por IP en 24 horas
   · File locking con flock() (evita race conditions)
   · Hashing de IP (SHA-256, sin almacenar IP real)
   · CORS restringido al dominio propio
   · display_errors desactivado en producción
   · Sin stack traces al exterior

   Flujo de moderación:
   1. Cliente envía reseña → se guarda en reviews-pending.json
   2. Accedes al panel privado /admin/reviews.php (usuario/contraseña + sesión)
   3. Apruebas o rechazas cada reseña desde el panel
   4. Al aprobar, el panel elimina el email/ip_hash y mueve la reseña a
      reviews-approved.json (ver config/reviews-store.php)
   5. La reseña aparece públicamente en /pages/reviews.html

   RGPD:
   · reviews-pending.json contiene email (solo para verificación por el admin)
   · reviews-approved.json NUNCA debe contener email
   · El panel elimina el email automáticamente al aprobar (nunca manual)

   Requisito en api/config.php:
     define('TURNSTILE_SECRET', '0x...');
===================================================== */

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

/* ── Config (cargado antes del CORS: APP_ENV debe estar definido para
   decidir si se permite el origen de desarrollo local) ───────────── */
$configPath = __DIR__ . '/config.php';
if (file_exists($configPath)) {
    require_once $configPath;
}
require_once dirname(__DIR__) . '/config/review-avatar.php';

/* ── CORS: dominio de producción siempre; localhost SOLO fuera de
   producción (APP_ENV explícito y distinto de 'production'). Si APP_ENV
   no está definido, se asume producción — fail-closed. ─────────────── */
$allowed = ['https://www.mastercodeweb.com', 'https://mastercodeweb.com'];

$isProductionEnv = !defined('APP_ENV') || APP_ENV === 'production';
if (!$isProductionEnv) {
    $allowed[] = 'http://127.0.0.1:8080';
    $allowed[] = 'http://localhost:8080';
}

$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
if (!empty($origin) && !in_array($origin, $allowed, true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Origen no permitido']);
    exit;
}
if (!empty($origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

/* ── Rutas de datos ───────────────────────────────── */
$DATA_DIR      = __DIR__ . '/data';
$PENDING_FILE  = $DATA_DIR . '/reviews-pending.json';
$RATE_FILE     = $DATA_DIR . '/rate-limit.json';

/* ── Leer input ──────────────────────────────────── */
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    $body = $_POST;
}

/* ── Sanitización ────────────────────────────────── */
function mcw_clean(string $value, int $maxLen = 500): string {
    $v = trim($value);
    $v = strip_tags($v);
    $v = htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return mb_substr($v, 0, $maxLen);
}

function mcw_clean_email(string $value): string {
    $v = trim($value);
    $v = filter_var($v, FILTER_SANITIZE_EMAIL);
    return mb_strtolower($v);
}

/* ── Extraer y validar campos ─────────────────────── */
$name          = mcw_clean($body['name']          ?? '', 100);
$email         = mcw_clean_email($body['email']   ?? '');
$rating        = (int)($body['rating']            ?? 0);
$comment       = mcw_clean($body['comment']       ?? '', 800);
$service       = mcw_clean($body['service']       ?? '', 100);
$project_date  = mcw_clean($body['project_date']  ?? '', 10);
$consent       = !empty($body['consent']);
$declaration   = !empty($body['declaration']);
$ts_token      = $body['cf-turnstile-response']   ?? $body['turnstile_token'] ?? '';

$errors = [];

/* Identidad: nombre y apellidos reales (mínimo dos palabras) — sin exigir DNI/pasaporte */
if (!preg_match('/^\S+(?:\s+\S+)+$/', $name)) {
    $errors[] = 'Introduce tu nombre y apellidos completos.';
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL))
    $errors[] = 'Introduce un email válido.';

if ($rating < 1 || $rating > 5)
    $errors[] = 'Selecciona una valoración de 1 a 5 estrellas.';

if (mb_strlen($comment) < 20)
    $errors[] = 'El comentario debe tener al menos 20 caracteres.';

if (mb_strlen($comment) > 800)
    $errors[] = 'El comentario no puede superar 800 caracteres.';

if ($service === '')
    $errors[] = 'Indica el servicio contratado.';

if (!preg_match('/^\d{4}-\d{2}$/', $project_date))
    $errors[] = 'Indica el mes y año aproximado del proyecto.';

if (!$consent)
    $errors[] = 'Debes aceptar la política de privacidad para enviar la reseña.';

if (!$declaration)
    $errors[] = 'Debes declarar que la reseña corresponde a una experiencia real y personal.';

/* Foto de perfil: OPCIONAL. Solo se valida el formato/tamaño aquí (sin
   escribir a disco todavía) para no generar archivos huérfanos si otro
   campo del formulario resulta inválido. */
$avatarFile     = $_FILES['avatar'] ?? null;
$avatarPrecheck = reviewAvatarPrecheck($avatarFile);
if (!$avatarPrecheck['ok']) {
    $errors[] = $avatarPrecheck['error'];
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'errors' => $errors]);
    exit;
}

/* ── Verificar Turnstile ─────────────────────────── */
$ts_secret = defined('TURNSTILE_SECRET') ? TURNSTILE_SECRET : (getenv('TURNSTILE_SECRET') ?: '');

if (!empty($ts_secret) && $ts_secret !== 'REPLACE_WITH_YOUR_TURNSTILE_SECRET') {
    if (empty($ts_token)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Verificación anti-spam requerida.']);
        exit;
    }

    $remoteIp = $_SERVER['HTTP_CF_CONNECTING_IP']
             ?? $_SERVER['HTTP_X_FORWARDED_FOR']
             ?? $_SERVER['REMOTE_ADDR']
             ?? '';

    $payload = http_build_query([
        'secret'   => $ts_secret,
        'response' => $ts_token,
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

    $tsResult = @file_get_contents(
        'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        false,
        $ctx
    );

    if ($tsResult === false) {
        error_log('[reviews] Error conectando con Cloudflare Turnstile');
        // Fail open si Cloudflare no responde
    } else {
        $tsData = json_decode($tsResult, true);
        if (!($tsData['success'] ?? false)) {
            $codes = $tsData['error-codes'] ?? [];
            error_log('[reviews] Turnstile falló: ' . implode(', ', $codes));
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Verificación anti-spam fallida. Intenta de nuevo.']);
            exit;
        }
    }
}

/* ── Rate limiting: máx 2 envíos por IP en 24h ──── */
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$ipHash = hash('sha256', $remoteIp);
$now    = time();

$rateData = [];
if (file_exists($RATE_FILE)) {
    $rateRaw  = file_get_contents($RATE_FILE);
    $rateData = json_decode($rateRaw, true) ?? [];
}

// Limpiar entradas con más de 24h de antigüedad
$rateData = array_filter(
    $rateData,
    fn($ts) => is_int($ts) && ($now - $ts) < 86400
);

// Contar envíos de esta IP en las últimas 24h
$ipCount = count(array_filter(
    array_keys($rateData),
    fn($k) => str_starts_with($k, $ipHash . '_')
));

if ($ipCount >= 2) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Has enviado demasiadas reseñas hoy. Inténtalo de nuevo mañana.']);
    exit;
}

/* ── Procesar foto de perfil (opcional) ──────────── */
$avatarStoreResult = reviewAvatarStore($avatarFile);
if (!$avatarStoreResult['ok']) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'errors' => [$avatarStoreResult['error']]]);
    exit;
}
$avatarFilename = $avatarStoreResult['filename']; // string|null — solo el nombre aleatorio, nunca el original

/* ── Construir reseña ────────────────────────────── */
$review = [
    'id'                => uniqid('rv_', true),
    'name'              => $name,
    'email'             => $email,   // SOLO en pending — eliminado antes de mover a approved
    'rating'            => $rating,
    'comment'           => $comment,
    'service'           => $service,
    'project_date'      => $project_date,
    'avatar'            => $avatarFilename, // nombre aleatorio en api/data/uploads-pending/, o null
    'status'            => 'pending',
    'verified_customer' => false,    // SOLO el admin autenticado puede cambiar esto al aprobar
    'created_at'        => date('c'),
    'ip_hash'           => $ipHash,
];

/* ── Guardar en pending (con file locking) ───────── */
if (!is_dir($DATA_DIR)) {
    reviewAvatarDeletePending($avatarFilename); // evitar huérfanos
    http_response_code(500);
    error_log('[reviews] Directorio data/ no existe: ' . $DATA_DIR);
    echo json_encode(['ok' => false, 'error' => 'Error interno. Contacta con el administrador.']);
    exit;
}

$fp = fopen($PENDING_FILE, 'c+');
if (!$fp) {
    reviewAvatarDeletePending($avatarFilename); // evitar huérfanos
    http_response_code(500);
    error_log('[reviews] No se pudo abrir: ' . $PENDING_FILE);
    echo json_encode(['ok' => false, 'error' => 'Error al guardar la reseña.']);
    exit;
}

$saved = false;
if (flock($fp, LOCK_EX)) {
    $size    = filesize($PENDING_FILE);
    $content = ($size > 2) ? fread($fp, $size) : '[]';
    $pending = json_decode($content, true) ?? [];
    $pending[] = $review;

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($pending, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    $saved = true;
}
fclose($fp);

if (!$saved) {
    reviewAvatarDeletePending($avatarFilename); // evitar huérfanos si la reseña no llegó a guardarse
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al guardar la reseña. Inténtalo de nuevo.']);
    exit;
}

/* ── Actualizar rate limit ───────────────────────── */
$rateData[$ipHash . '_' . $now] = $now;
file_put_contents($RATE_FILE, json_encode($rateData));

/* ── Respuesta exitosa ───────────────────────────── */
echo json_encode([
    'ok'      => true,
    'message' => '¡Gracias por tu reseña! La revisaremos antes de publicarla. Esto puede tardar hasta 48 horas.',
]);
