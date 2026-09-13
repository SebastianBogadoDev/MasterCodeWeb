<?php
/* admin/logout.php — cierra la sesión de administrador. POST-only + CSRF
   (no es una acción destructiva sobre datos, pero se mantiene el mismo
   patrón de seguridad que el resto del panel por consistencia). */

require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once dirname(__DIR__) . '/config/admin-auth.php';

adminPageHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isAdminAuthenticated() && adminCsrfValidate()) {
    adminLogout();
}

header('Location: login.php');
exit;
