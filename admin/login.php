<?php
/* admin/login.php — acceso al panel privado de moderación de reseñas.
   GET: muestra el formulario. POST: valida credenciales + CSRF + rate limit. */

require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once dirname(__DIR__) . '/config/admin-auth.php';

adminPageHeaders();

if (isAdminAuthenticated()) {
    header('Location: reviews.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Throttle intentos de login antes de tocar credenciales: 5 intentos / 15 min por IP.
    rateLimitIp('admin-login', 5, 900);

    if (!adminCsrfValidate()) {
        $error = 'Sesión expirada. Recarga la página e inténtalo de nuevo.';
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($username !== '' && $password !== '' && attemptAdminLogin($username, $password)) {
            header('Location: reviews.php');
            exit;
        }
        $error = 'Usuario o contraseña incorrectos.';
    }
}

$csrfToken = csrfGenerate();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso privado | MasterCodeWeb</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="/css/main.bundle.css">
    <link rel="stylesheet" href="/css/pages/admin-reviews.css">
</head>
<body class="admin-body">

    <main class="admin-login">
        <form class="admin-login__card" method="post" novalidate>
            <h1 class="admin-login__title">Panel de moderación</h1>
            <p class="admin-login__subtitle">Acceso restringido a administradores de MasterCodeWeb.</p>

            <?php if ($error !== ''): ?>
                <p class="admin-alert admin-alert--error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>

            <div class="form-group">
                <label for="username">Usuario</label>
                <input type="text" id="username" name="username" autocomplete="username" required autofocus>
            </div>

            <div class="form-group">
                <label for="password">Contraseña</label>
                <input type="password" id="password" name="password" autocomplete="current-password" required>
            </div>

            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <button type="submit" class="btn btn--primary admin-login__submit">Entrar</button>
        </form>
    </main>

</body>
</html>
