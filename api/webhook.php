<?php
/* =====================================================
   STRIPE WEBHOOK HANDLER — MasterCodeWeb
   POST /api/webhook.php   (llamado por Stripe, no por el usuario)

   Eventos gestionados:
     - checkout.session.completed  → notifica al equipo
     - invoice.paid                → gestiona ciclos de cuotas
     - invoice.payment_failed      → alerta al equipo
===================================================== */

// ── Cargar config ─────────────────────────────────────
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

// ── Verificar firma Stripe ────────────────────────────
$payload   = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

try {
    $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, STRIPE_WEBHOOK_SECRET);
} catch (\UnexpectedValueException $e) {
    http_response_code(400);
    exit;
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    http_response_code(400);
    exit;
}

// ── Manejar eventos ───────────────────────────────────
switch ($event->type) {

    // Pago completado (único o primera cuota de suscripción)
    case 'checkout.session.completed':
        $session = $event->data->object;
        $plan    = $session->metadata->plan ?? 'desconocido';
        $name    = $session->customer_details->name  ?? 'Sin nombre';
        $email   = $session->customer_details->email ?? 'Sin email';
        $amount  = number_format(($session->amount_total ?? 0) / 100, 2) . ' €';

        notifyOwner(
            "Nuevo pago recibido: $plan",
            "Plan: $plan\nCliente: $name\nEmail: $email\nImporte: $amount\n\n" .
            "Ver en Stripe: https://dashboard.stripe.com/payments/" . ($session->payment_intent ?? $session->id)
        );
        break;

    // Cobro de suscripción (cuotas + mantenimiento)
    case 'invoice.paid':
        $invoice = $event->data->object;
        $subId   = $invoice->subscription;
        if (!$subId) break;

        $sub      = \Stripe\Subscription::retrieve($subId);
        $maxCycles = (int)($sub->metadata->max_cycles  ?? 0);
        if ($maxCycles === 0) break; // mantenimiento sin límite — no hacer nada

        $paidCycles = (int)($sub->metadata->paid_cycles ?? 0) + 1;

        \Stripe\Subscription::update($subId, [
            'metadata' => ['paid_cycles' => (string)$paidCycles],
        ]);

        if ($paidCycles >= $maxCycles) {
            // Última cuota pagada — cancelar al final del período
            \Stripe\Subscription::update($subId, [
                'cancel_at_period_end' => true,
            ]);
        }
        break;

    // Cobro fallido
    case 'invoice.payment_failed':
        $invoice   = $event->data->object;
        $subId     = $invoice->subscription ?? '';
        $custEmail = $invoice->customer_email ?? 'desconocido';
        $attempt   = $invoice->attempt_count ?? 1;

        notifyOwner(
            "Cobro fallido (intento $attempt)",
            "Cliente: $custEmail\nSuscripción: $subId\nAcción recomendada: contactar al cliente."
        );
        break;
}

http_response_code(200);
echo json_encode(['received' => true]);

// ── Helpers ───────────────────────────────────────────

function notifyOwner(string $subject, string $body): void {
    $headers  = "From: noreply@mastercodeweb.com\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    @mail(OWNER_EMAIL, "[MCW] $subject", $body, $headers);
}
