<?php
/* admin/avatar.php — sirve la foto de perfil de una reseña PENDIENTE al
   admin autenticado. Las fotos pendientes viven en api/data/uploads-pending/
   (bloqueado por .htaccess a cualquier acceso HTTP directo); este es el
   único camino para verlas, y solo funciona con sesión admin activa.

   GET /admin/avatar.php?id=<review_id>

   Seguridad:
   · requireAdminAuth() — sin sesión, no hay imagen.
   · El nombre de archivo NUNCA viene de la query string: se resuelve
     internamente a partir del registro 'id' en reviews-pending.json
     (que ya es de confianza, generado por el propio servidor) — un
     atacante no puede pedir un archivo arbitrario por path traversal
     porque no hay ningún parámetro de ruta/archivo en la petición.
*/

require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once dirname(__DIR__) . '/config/admin-auth.php';
require_once dirname(__DIR__) . '/config/reviews-store.php';

adminPageHeaders();
requireAdminAuth();

$id = (string)($_GET['id'] ?? '');
if ($id === '') {
    http_response_code(400);
    exit;
}

$pending = reviewsReadPending();
$review  = null;
foreach ($pending as $r) {
    if (($r['id'] ?? null) === $id) {
        $review = $r;
        break;
    }
}

$filename = $review['avatar'] ?? null;
$path     = reviewAvatarPendingPath($filename);

if ($path === null) {
    http_response_code(404);
    exit;
}

$info = @getimagesize($path);
if ($info === false || $info[2] !== IMAGETYPE_JPEG) {
    // Defensa en profundidad: si por lo que sea el archivo ya no es un JPEG
    // válido, no lo servimos como imagen.
    http_response_code(404);
    exit;
}

header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-store, no-cache, must-revalidate');
readfile($path);
