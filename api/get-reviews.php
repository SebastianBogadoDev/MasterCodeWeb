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

/* Eliminar email si por error estuviera en approved */
$safeReviews = array_map(function ($r) {
    unset($r['email'], $r['ip_hash']);
    return $r;
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
