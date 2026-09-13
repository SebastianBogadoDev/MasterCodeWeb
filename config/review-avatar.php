<?php
/* config/review-avatar.php — foto de perfil opcional para reseñas.

   Diseño de seguridad:
   · Solo JPEG/PNG/WebP, verificados por CONTENIDO (finfo + getimagesize +
     decodificación GD real), nunca por extensión ni Content-Type declarado
     por el cliente. Un SVG, un ejecutable renombrado, o un polyglot que
     falle cualquiera de las tres capas es rechazado.
   · Toda imagen aceptada se vuelve a codificar con GD como JPEG nuevo —
     esto por sí solo descarta EXIF/metadatos (GD no los copia) y confirma
     que el contenido son píxeles reales decodificables, no solo un header
     válido.
   · Nombre de archivo siempre aleatorio (random_bytes) — nunca se usa el
     nombre original ni ninguna entrada del usuario en la ruta del archivo.
   · Mientras la reseña está pendiente, la imagen se guarda en
     api/data/uploads-pending/ (protegida por el mismo .htaccess
     "Deny from all" que el resto de api/data/) — NO es accesible por HTTP.
     Solo se traslada a la carpeta pública /uploads/reviews/ al aprobar.
   · Si GD o fileinfo no están disponibles, se falla de forma segura
     (se rechaza la foto, nunca se guarda sin procesar).
*/

function reviewAvatarPendingDir(): string
{
    return dirname(__DIR__) . '/api/data/uploads-pending';
}

function reviewAvatarPublicDir(): string
{
    return dirname(__DIR__) . '/uploads/reviews';
}

const REVIEW_AVATAR_MAX_BYTES = 2 * 1024 * 1024; // 2 MB
const REVIEW_AVATAR_MAX_DIM   = 512;              // lado más largo, en px

/**
 * Validación rápida (sin escritura a disco). Se ejecuta junto al resto de
 * validaciones del formulario para poder acumular errores sin generar
 * archivos huérfanos si otro campo del formulario resulta inválido.
 *
 * Devuelve ['ok' => true] si no hay foto (campo opcional) o si la foto es
 * válida; ['ok' => false, 'error' => '...'] en caso contrario.
 */
function reviewAvatarPrecheck(?array $file): array
{
    if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true];
    }

    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        return ['ok' => false, 'error' => 'La foto de perfil no puede superar 2 MB.'];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'No se pudo subir la foto de perfil. Inténtalo de nuevo.'];
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'Archivo no válido.'];
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        return ['ok' => false, 'error' => 'Archivo vacío o no válido.'];
    }
    if ($size > REVIEW_AVATAR_MAX_BYTES) {
        return ['ok' => false, 'error' => 'La foto de perfil no puede superar 2 MB.'];
    }

    if (!extension_loaded('gd') || !function_exists('finfo_open')) {
        appLog('ERROR', 'review-avatar', 'gd/fileinfo no disponibles en este entorno PHP');
        return ['ok' => false, 'error' => 'El procesamiento de imágenes no está disponible ahora mismo. Puedes enviar tu reseña sin foto.'];
    }

    $format = reviewAvatarRealFormat($file['tmp_name']);
    if ($format === null) {
        return ['ok' => false, 'error' => 'Formato de imagen no permitido. Usa JPEG, PNG o WebP.'];
    }

    return ['ok' => true];
}

/**
 * Detecta el formato real por contenido (finfo + getimagesize), no por
 * extensión ni por el Content-Type que declare el cliente.
 * Devuelve 'jpeg' | 'png' | 'webp' | null.
 */
function reviewAvatarRealFormat(string $tmpPath): ?string
{
    $allowedMime = [
        'image/jpeg' => 'jpeg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $realMime = $finfo ? finfo_file($finfo, $tmpPath) : false;
    if ($finfo) {
        finfo_close($finfo);
    }

    if ($realMime === false || !isset($allowedMime[$realMime])) {
        return null;
    }

    $format = $allowedMime[$realMime];

    $info = @getimagesize($tmpPath);
    if ($info === false) {
        return null;
    }

    $expectedType = [
        'jpeg' => IMAGETYPE_JPEG,
        'png'  => IMAGETYPE_PNG,
        'webp' => IMAGETYPE_WEBP,
    ][$format];

    if ($info[2] !== $expectedType) {
        return null;
    }

    return $format;
}

/**
 * Procesa y guarda la foto en la carpeta PRIVADA de pendientes (nunca
 * accesible por HTTP). Asume que reviewAvatarPrecheck() ya devolvió ok.
 * Devuelve ['ok' => true, 'filename' => '<random>.jpg' | null] o
 * ['ok' => false, 'error' => '...'].
 */
function reviewAvatarStore(?array $file): array
{
    if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'filename' => null];
    }

    $format = reviewAvatarRealFormat($file['tmp_name']);
    if ($format === null) {
        return ['ok' => false, 'error' => 'Formato de imagen no permitido.'];
    }

    $source = match ($format) {
        'jpeg'  => @imagecreatefromjpeg($file['tmp_name']),
        'png'   => @imagecreatefrompng($file['tmp_name']),
        'webp'  => @imagecreatefromwebp($file['tmp_name']),
        default => false,
    };

    if ($source === false) {
        return ['ok' => false, 'error' => 'No se pudo procesar la imagen.'];
    }

    $srcW = imagesx($source);
    $srcH = imagesy($source);
    $scale = min(1, REVIEW_AVATAR_MAX_DIM / max($srcW, $srcH));
    $dstW  = max(1, (int) round($srcW * $scale));
    $dstH  = max(1, (int) round($srcH * $scale));

    $canvas = imagecreatetruecolor($dstW, $dstH);
    // Fondo blanco por si el origen (PNG/WebP) tenía transparencia — la salida es siempre JPEG opaco.
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefill($canvas, 0, 0, $white);
    imagecopyresampled($canvas, $source, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
    // imagedestroy() es un no-op deprecado desde PHP 8.0/8.5 (GC automático) — se omite.

    $pendingDir = reviewAvatarPendingDir();
    if (!is_dir($pendingDir)) {
        @mkdir($pendingDir, 0750, true);
    }

    $filename = bin2hex(random_bytes(16)) . '.jpg';
    $destPath = $pendingDir . '/' . $filename;

    // Re-codificar como JPEG nuevo: esto descarta EXIF/metadatos por diseño (GD no los preserva).
    $saved = imagejpeg($canvas, $destPath, 85);

    if (!$saved) {
        return ['ok' => false, 'error' => 'No se pudo guardar la foto de perfil.'];
    }

    @chmod($destPath, 0640);

    return ['ok' => true, 'filename' => $filename];
}

/** Ruta absoluta al archivo pendiente (para servirlo solo al admin autenticado). */
function reviewAvatarPendingPath(?string $filename): ?string
{
    if ($filename === null || $filename === '') {
        return null;
    }
    $path = reviewAvatarPendingDir() . '/' . basename($filename);
    return is_file($path) ? $path : null;
}

/** Traslada un avatar de pending a la carpeta pública al aprobar. Devuelve la ruta pública o null. */
function reviewAvatarPromote(?string $pendingFilename): ?string
{
    if ($pendingFilename === null || $pendingFilename === '') {
        return null;
    }

    $src = reviewAvatarPendingDir() . '/' . basename($pendingFilename);
    if (!is_file($src)) {
        return null;
    }

    $publicDir = reviewAvatarPublicDir();
    if (!is_dir($publicDir)) {
        @mkdir($publicDir, 0755, true);
    }

    $dest = $publicDir . '/' . basename($pendingFilename);
    if (!@rename($src, $dest)) {
        return null;
    }
    @chmod($dest, 0644);

    return '/uploads/reviews/' . basename($pendingFilename);
}

/** Elimina un avatar pendiente no usado (rechazo, o error tras el guardado). */
function reviewAvatarDeletePending(?string $pendingFilename): void
{
    if ($pendingFilename === null || $pendingFilename === '') {
        return;
    }
    $path = reviewAvatarPendingDir() . '/' . basename($pendingFilename);
    if (is_file($path)) {
        @unlink($path);
    }
}

/** Elimina un avatar público (al despublicar), a partir de la ruta pública guardada. */
function reviewAvatarDeletePublic(?string $publicPath): void
{
    if ($publicPath === null || $publicPath === '') {
        return;
    }
    $path = reviewAvatarPublicDir() . '/' . basename($publicPath);
    if (is_file($path)) {
        @unlink($path);
    }
}
