<?php
/* config/reviews-store.php — helper de moderación de reseñas.
   Usado por admin/reviews.php. Mismo patrón de file locking (flock) que
   api/reviews.php: leer con lock exclusivo, truncar, reescribir, liberar.

   Garantías:
   · El email y el ip_hash se eliminan SIEMPRE antes de escribir en approved.
   · verified_customer solo lo puede fijar el admin autenticado (nunca viene
     del cuerpo de una petición pública).
   · La aprobación/rechazo son idempotentes: si el id ya no está en pending
     (porque otra petición ya lo procesó), se devuelve error en vez de
     duplicar la reseña — previene aprobación doble.
   · Todas las lecturas validan que el JSON decodifique a un array; si no,
     se falla de forma segura sin sobrescribir el archivo.
*/

require_once __DIR__ . '/review-avatar.php';

define('REVIEWS_PENDING_FILE',  dirname(__DIR__) . '/api/data/reviews-pending.json');
define('REVIEWS_APPROVED_FILE', dirname(__DIR__) . '/api/data/reviews-approved.json');
define('REVIEWS_LOG_FILE',      dirname(__DIR__) . '/api/data/reviews-moderation-log.json');

function reviewsReadPending(): array
{
    return reviewsReadFile(REVIEWS_PENDING_FILE);
}

function reviewsReadApproved(): array
{
    return reviewsReadFile(REVIEWS_APPROVED_FILE);
}

function reviewsReadFile(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }
    $raw  = file_get_contents($path);
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Aprueba una reseña: la mueve de pending a approved, elimina campos
 * privados y fija verified_customer según la decisión explícita del admin.
 */
function reviewApprove(string $id, bool $verifiedCustomer): array
{
    $extracted = reviewsExtractById(REVIEWS_PENDING_FILE, $id);
    if ($extracted === null) {
        return ['ok' => false, 'error' => 'Reseña no encontrada o ya procesada.'];
    }

    $review = $extracted;
    unset($review['email'], $review['ip_hash']);
    $review['status']            = 'approved';
    $review['verified_customer'] = $verifiedCustomer;
    $review['approved_at']       = date('c');

    // Foto (si la hay): trasladar de la carpeta privada de pendientes a la
    // pública /uploads/reviews/. El campo pasa de "nombre de archivo" a
    // "ruta pública" solo en este momento — nunca antes de la aprobación.
    $review['avatar'] = reviewAvatarPromote($review['avatar'] ?? null);

    if (!reviewsAppend(REVIEWS_APPROVED_FILE, $review)) {
        // No se pudo guardar el registro aprobado: no dejar la imagen huérfana en público.
        reviewAvatarDeletePublic($review['avatar'] ?? null);
        return ['ok' => false, 'error' => 'No se pudo guardar en reviews-approved.json.'];
    }

    reviewsLogAction($id, 'approve', $verifiedCustomer);
    return ['ok' => true];
}

/**
 * Rechaza una reseña: la elimina de pending sin archivarla.
 * Solo se conserva un registro mínimo (id, acción, fecha) en el log interno.
 * Si tenía foto de perfil sin usar, se elimina del disco (nunca huérfana).
 */
function reviewReject(string $id): array
{
    $extracted = reviewsExtractById(REVIEWS_PENDING_FILE, $id);
    if ($extracted === null) {
        return ['ok' => false, 'error' => 'Reseña no encontrada o ya procesada.'];
    }

    reviewAvatarDeletePending($extracted['avatar'] ?? null);
    reviewsLogAction($id, 'reject');
    return ['ok' => true];
}

/**
 * Despublica una reseña ya aprobada (la retira de approved).
 * No se reintegra a pending: el email ya fue eliminado al aprobar, por lo
 * que una reseña despublicada no puede volver a pasar por verificación.
 * Su foto pública (si la hay) se elimina también — evita huérfanos y, al
 * retirar la reseña, retira también su foto asociada.
 */
function reviewUnpublish(string $id): array
{
    $extracted = reviewsExtractById(REVIEWS_APPROVED_FILE, $id);
    if ($extracted === null) {
        return ['ok' => false, 'error' => 'Reseña no encontrada en publicadas.'];
    }

    reviewAvatarDeletePublic($extracted['avatar'] ?? null);
    reviewsLogAction($id, 'unpublish');
    return ['ok' => true];
}

/**
 * Abre $path con lock exclusivo, extrae el primer registro cuyo 'id'
 * coincida, reescribe el archivo sin ese registro y libera el lock.
 * Devuelve el registro extraído, o null si no se encontró (idempotencia:
 * previene procesar dos veces la misma reseña bajo peticiones concurrentes).
 */
function reviewsExtractById(string $path, string $id): ?array
{
    $fp = fopen($path, 'c+');
    if (!$fp) {
        return null;
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return null;
    }

    $size    = filesize($path);
    $content = ($size > 2) ? fread($fp, $size) : '[]';
    $items   = json_decode($content, true);

    if (!is_array($items)) {
        flock($fp, LOCK_UN);
        fclose($fp);
        appLog('ERROR', 'reviews-store', 'JSON corrupto, abortando', ['file' => basename($path)]);
        return null;
    }

    $found     = null;
    $remaining = [];
    foreach ($items as $item) {
        if ($found === null && is_array($item) && ($item['id'] ?? null) === $id) {
            $found = $item;
            continue;
        }
        $remaining[] = $item;
    }

    if ($found === null) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return null;
    }

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode(array_values($remaining), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return $found;
}

/** Añade un registro a $path bajo lock exclusivo. */
function reviewsAppend(string $path, array $record): bool
{
    $fp = fopen($path, 'c+');
    if (!$fp) {
        return false;
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }

    $size    = filesize($path);
    $content = ($size > 2) ? fread($fp, $size) : '[]';
    $items   = json_decode($content, true);
    if (!is_array($items)) {
        $items = [];
    }
    $items[] = $record;

    ftruncate($fp, 0);
    rewind($fp);
    $ok = fwrite($fp, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false;
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return $ok;
}

/**
 * Registro interno mínimo de trazabilidad: id, acción, fecha y (si aplica)
 * la decisión de verified_customer. Nunca incluye email ni otros datos
 * personales del cliente.
 */
function reviewsLogAction(string $id, string $action, ?bool $verifiedCustomer = null): void
{
    $entry = [
        'review_id' => $id,
        'action'    => $action,
        'timestamp' => date('c'),
    ];
    if ($verifiedCustomer !== null) {
        $entry['verified_customer'] = $verifiedCustomer;
    }
    reviewsAppend(REVIEWS_LOG_FILE, $entry);
}
