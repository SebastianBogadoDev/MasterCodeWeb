<?php
/* config/csrf.php — CSRF para formularios HTML tradicionales (POST form-encoded)
   AJAX con Content-Type: application/json no necesita CSRF — CORS + mismo dominio protege. */

function csrfInit(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start([
            'cookie_httponly' => true,
            'cookie_secure'   => isset($_SERVER['HTTPS']),
            'cookie_samesite' => 'Strict',
            'use_strict_mode' => true,
        ]);
    }
}

function csrfGenerate(): string
{
    csrfInit();
    if (empty($_SESSION['csrf_token']) || csrfExpired()) {
        $_SESSION['csrf_token']    = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_ts'] = time();
    }
    return $_SESSION['csrf_token'];
}

function csrfValidate(): void
{
    csrfInit();

    // Soporta: campo _csrf en formulario HTML o header X-CSRF-Token en AJAX
    $submitted = $_POST['_csrf']
              ?? $_SERVER['HTTP_X_CSRF_TOKEN']
              ?? '';

    $stored = $_SESSION['csrf_token'] ?? '';

    if (empty($submitted)
        || empty($stored)
        || !hash_equals($stored, $submitted)
        || csrfExpired()
    ) {
        appLog('WARNING', 'csrf', 'Token inválido o expirado', [
            'ip'  => clientIp(),
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
        ]);
        http_response_code(403);
        // Responde JSON o redirect según tipo de petición
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            echo json_encode(['error' => 'Token de seguridad inválido. Recarga la página.']);
        } else {
            $base = defined('SITE_URL') ? SITE_URL : '';
            header('Location: ' . $base . '/pages/acceso-cliente.html?error=' . rawurlencode('Sesión expirada. Recarga la página.'));
        }
        exit;
    }

    // Rotar token después de validar (protección replay)
    unset($_SESSION['csrf_token'], $_SESSION['csrf_token_ts']);
}

/** Devuelve el campo hidden listo para insertar en formularios HTML */
function csrfField(): string
{
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrfGenerate(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrfExpired(int $ttlSeconds = 7200): bool
{
    $ts = $_SESSION['csrf_token_ts'] ?? 0;
    return (time() - $ts) > $ttlSeconds;
}
