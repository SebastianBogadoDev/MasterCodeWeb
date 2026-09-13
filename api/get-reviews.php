<?php
/* =====================================================
   GET REVIEWS — MasterCodeWeb
   GET /api/get-reviews.php

   Devuelve únicamente las reseñas con status 'approved'.
   NUNCA expone emails ni datos privados.
   Cache de 5 minutos con Cache-Control.
===================================================== */

@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

$APPROVED_FILE = __DIR__ . '/data/reviews-approved.json';

if (!file_exists($APPROVED_FILE)) {
    echo json_encode(['ok' => true, 'reviews' => [], 'total' => 0, 'average' => null]);
    exit;
}

$content = file_get_contents($APPROVED_FILE);
$reviews = json_decode($content, true) ?? [];

/* Filtro de seguridad: asegurarse de que solo salen approved */
$reviews = array_values(array_filter(
    $reviews,
    fn($r) => ($r['status'] ?? '') === 'approved'
));

/* Ordenar por fecha descendente */
usort($reviews, fn($a, $b) => strcmp(
    $b['created_at'] ?? '',
    $a['created_at'] ?? ''
));

/* Whitelist estricta: solo estos campos salen al público, pase lo que pase
   con el resto de metadatos internos que pudiera tener el registro. */
$safeReviews = array_map(function ($r) {
    // El avatar solo se expone si es exactamente una ruta pública esperada
    // (nunca una ruta de filesystem ni el nombre de archivo interno de pending).
    $avatar = $r['avatar'] ?? null;
    if (!is_string($avatar) || !preg_match('#^/uploads/reviews/[A-Za-z0-9_-]+\.jpg$#', $avatar)) {
        $avatar = null;
    }

    return [
        'id'                => $r['id']                ?? null,
        'name'              => $r['name']               ?? '',
        'rating'            => $r['rating']              ?? 0,
        'comment'           => $r['comment']             ?? '',
        'service'           => $r['service']             ?? '',
        'project_date'      => $r['project_date']        ?? '',
        'status'            => $r['status']              ?? 'approved',
        'created_at'        => $r['created_at']          ?? '',
        'verified_customer' => ($r['verified_customer'] ?? false) === true,
        'avatar'            => $avatar,
    ];
}, $reviews);

/* Calcular media */
$average = null;
if (!empty($safeReviews)) {
    $sum     = array_sum(array_column($safeReviews, 'rating'));
    $average = round($sum / count($safeReviews), 1);
}

echo json_encode([
    'ok'      => true,
    'reviews' => $safeReviews,
    'total'   => count($safeReviews),
    'average' => $average,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
