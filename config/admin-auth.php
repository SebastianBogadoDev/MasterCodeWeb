<?php
/* config/admin-auth.php — autenticación mínima para el panel privado de
   moderación de reseñas (/admin/).

   Diseño:
   · Una única cuenta de administrador (ADMIN_USERNAME / ADMIN_PASSWORD_HASH
     en .env — nunca en git, nunca en texto plano).
   · Sesión PHP nativa con las mismas cookies seguras que config/csrf.php
     (httponly, secure en HTTPS, SameSite=Strict, use_strict_mode).
   · password_verify() contra un hash bcrypt (password_hash()).
   · Reutiliza el mismo $_SESSION['csrf_token'] que csrfGenerate()/csrfField()
     para no duplicar la generación del token; adminCsrfValidate() solo
     cambia el comportamiento en caso de fallo (aquí no tiene sentido
     redirigir a /pages/acceso-cliente.html como hace csrfValidate()).
*/

function adminSessionInit(): void
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

function isAdminAuthenticated(): bool
{
    adminSessionInit();
    return !empty($_SESSION['admin_authenticated']);
}

/** Redirige al login si no hay sesión de administrador activa. */
function requireAdminAuth(): void
{
    if (!isAdminAuthenticated()) {
        header('Location: login.php');
        exit;
    }
}

/** Variante JSON para endpoints que responden con application/json. */
function requireAdminAuthApi(): void
{
    if (!isAdminAuthenticated()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'No autenticado.']);
        exit;
    }
}

function adminCredentialsConfigured(): bool
{
    $user = $_ENV['ADMIN_USERNAME']      ?? getenv('ADMIN_USERNAME')      ?: '';
    $hash = $_ENV['ADMIN_PASSWORD_HASH'] ?? getenv('ADMIN_PASSWORD_HASH') ?: '';
    return $user !== '' && $hash !== '';
}

function attemptAdminLogin(string $username, string $password): bool
{
    adminSessionInit();

    $expectedUser = (string)($_ENV['ADMIN_USERNAME']      ?? getenv('ADMIN_USERNAME')      ?: '');
    $expectedHash = (string)($_ENV['ADMIN_PASSWORD_HASH'] ?? getenv('ADMIN_PASSWORD_HASH') ?: '');

    if ($expectedUser === '' || $expectedHash === '') {
        appLog('ERROR', 'admin-auth', 'ADMIN_USERNAME/ADMIN_PASSWORD_HASH no configurados en .env');
        return false;
    }

    $userOk = hash_equals($expectedUser, $username);
    $passOk = password_verify($password, $expectedHash);

    if ($userOk && $passOk) {
        // Regenerar el ID de sesión en cada login evita session fixation.
        session_regenerate_id(true);
        $_SESSION['admin_authenticated'] = true;
        $_SESSION['admin_user']          = $expectedUser;
        $_SESSION['admin_login_at']      = time();
        return true;
    }

    appLog('WARNING', 'admin-auth', 'Intento de login fallido', [
        'ip'   => clientIp(),
        'user' => substr($username, 0, 60),
    ]);
    return false;
}

function adminLogout(): void
{
    adminSessionInit();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

/**
 * Validación de CSRF específica del panel admin.
 * Reutiliza el mismo token de sesión que csrfGenerate()/csrfField(),
 * pero sin el redirect a acceso-cliente.html que hace csrfValidate()
 * (pensado para el flujo del portal de cliente, no para el admin).
 */
function adminCsrfValidate(): bool
{
    csrfInit();

    $submitted = $_POST['_csrf'] ?? '';
    $stored    = $_SESSION['csrf_token'] ?? '';

    $valid = !empty($submitted)
          && !empty($stored)
          && hash_equals($stored, $submitted)
          && !csrfExpired();

    if ($valid) {
        // Rotar el token tras un uso válido (protección replay), igual que csrfValidate().
        unset($_SESSION['csrf_token'], $_SESSION['csrf_token_ts']);
    } else {
        appLog('WARNING', 'admin-csrf', 'Token inválido o expirado', ['ip' => clientIp()]);
    }

    return $valid;
}

/** Cabeceras comunes para páginas del panel admin: nunca cachear, nunca indexar. */
function adminPageHeaders(): void
{
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
}
