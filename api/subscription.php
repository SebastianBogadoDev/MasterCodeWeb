<?php
/* =====================================================
   SUBSCRIPTION MANAGEMENT — MasterCodeWeb
   GET  /api/subscription.php?customer_id=cus_...
        → lista suscripciones activas del customer

   POST /api/subscription.php
   Body: { "action": "cancel"|"reactivate"|"status",
           "subscription_id": "sub_..." }
        → gestiona una suscripción específica

   SEGURIDAD: rate-limit + validación estricta de IDs
===================================================== */

require_once __DIR__ . '/config.php';

apiHeaders('GET, POST');
rateLimitIp('subscription', 30, 60);
validateMethod('GET', 'POST');

// ══════════════════════════════════════════════════════
//  GET — estado de suscripciones de un customer
// ══════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $customerId = trim($_GET['customer_id'] ?? '');
    validateStripeId($customerId, 'cus_', 'customer_id');

    try {
        $subs = stripeRetry(fn() => \Stripe\Subscription::all([
            'customer' => $customerId,
            'status'   => 'all',
            'limit'    => 10,
            'expand'   => ['data.default_payment_method'],
        ]));

        $result = array_map(fn($s) => [
            'id'                  => $s->id,
            'status'              => $s->status,
            'plan'                => $s->metadata->plan ?? null,
            'current_period_end'  => $s->current_period_end,
            'cancel_at_period_end'=> $s->cancel_at_period_end,
            'canceled_at'         => $s->canceled_at,
            'price_id'            => $s->items->data[0]->price->id ?? null,
            'amount'              => ($s->items->data[0]->price->unit_amount ?? 0) / 100,
            'currency'            => $s->items->data[0]->price->currency ?? 'eur',
            'interval'            => $s->items->data[0]->price->recurring->interval ?? null,
        ], $subs->data);

        echo json_encode(['subscriptions' => $result, 'mode' => STRIPE_MODE]);

    } catch (\Stripe\Exception\InvalidRequestException $e) {
        jsonError(404, 'Customer no encontrado en Stripe.');
    } catch (\Throwable $e) {
        appLog('ERROR', 'subscription', 'Error listando suscripciones', ['err' => $e->getMessage()]);
        jsonError(502, 'Error al obtener suscripciones.');
    }
    exit;
}

// ══════════════════════════════════════════════════════
//  POST — acción sobre una suscripción
// ══════════════════════════════════════════════════════

$body   = parseJsonBody();
requireFields($body, 'action', 'subscription_id');

$action = sanitize($body['action']);
$subId  = validateStripeId(sanitize($body['subscription_id']), 'sub_', 'subscription_id');

if (!in_array($action, ['cancel', 'reactivate', 'status'], true)) {
    jsonError(400, "Acción no válida: '$action'. Válidas: cancel, reactivate, status.");
}

try {
    $sub = stripeRetry(fn() => \Stripe\Subscription::retrieve($subId));

    switch ($action) {

        case 'status':
            echo json_encode([
                'id'                   => $sub->id,
                'status'               => $sub->status,
                'cancel_at_period_end' => $sub->cancel_at_period_end,
                'current_period_end'   => $sub->current_period_end,
                'canceled_at'          => $sub->canceled_at,
                'plan'                 => $sub->metadata->plan ?? null,
            ]);
            break;

        case 'cancel':
            if (in_array($sub->status, ['canceled', 'incomplete_expired'], true)) {
                jsonError(409, 'La suscripción ya está cancelada.');
            }
            // Cancelar al final del período (no de inmediato)
            $updated = stripeRetry(fn() => \Stripe\Subscription::update($subId, [
                'cancel_at_period_end' => true,
            ]));
            appLog('INFO', 'subscription', 'Suscripción programada para cancelar', [
                'sub'  => $subId,
                'ends' => $updated->current_period_end,
            ]);
            echo json_encode([
                'status'              => 'scheduled_cancel',
                'cancel_at'           => $updated->current_period_end,
                'cancel_at_formatted' => date('d/m/Y', $updated->current_period_end),
            ]);
            break;

        case 'reactivate':
            if (!$sub->cancel_at_period_end) {
                jsonError(409, 'La suscripción no está pendiente de cancelación.');
            }
            if ($sub->status === 'canceled') {
                jsonError(409, 'La suscripción ya está cancelada definitivamente. Crea una nueva.');
            }
            $updated = stripeRetry(fn() => \Stripe\Subscription::update($subId, [
                'cancel_at_period_end' => false,
            ]));
            appLog('INFO', 'subscription', 'Suscripción reactivada', ['sub' => $subId]);
            echo json_encode([
                'status'             => 'active',
                'current_period_end' => $updated->current_period_end,
            ]);
            break;
    }

} catch (\Stripe\Exception\InvalidRequestException $e) {
    $msg = $e->getMessage();
    appLog('ERROR', 'subscription', 'InvalidRequest', ['action' => $action, 'err' => $msg]);

    if (str_contains($msg, 'No such subscription')) {
        jsonError(404, 'Suscripción no encontrada.');
    }
    jsonError(400, 'Error de Stripe: ' . $msg);

} catch (\Throwable $e) {
    appLog('ERROR', 'subscription', 'Error inesperado', ['action' => $action, 'err' => $e->getMessage()]);
    jsonError(500, 'Error interno del servidor.');
}
