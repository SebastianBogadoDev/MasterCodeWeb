<?php
/* =====================================================
   STRIPE WEBHOOK HANDLER — MasterCodeWeb (PRODUCTION)
   POST /api/webhook.php  — llamado por Stripe, no por el usuario

   Protecciones activas:
     · Verificación de firma HMAC-SHA256 (Stripe::constructEvent)
     · Tolerancia replay attacks: 300 s
     · Deduplicación por event ID (ventana 48 h en /tmp)
     · Rate limit por IP (barrera DDoS, no reemplaza firma)
     · Logs estructurados en storage/logs/stripe.log
     · Errores nunca expuestos al exterior

   Eventos gestionados:
     · checkout.session.completed
     · invoice.paid
     · invoice.payment_failed
     · customer.subscription.deleted
     · customer.subscription.updated

   Dashboard → Developers → Webhooks → Add endpoint:
     URL: https://www.mastercodeweb.com/api/webhook.php
===================================================== */

// Crítico: ningún output antes de leer php://input
// display_errors off aquí como seguridad extra (el handler global no siempre
// actúa antes del primer byte de output en errores de parse)
@ini_set('display_errors', '0');

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

// ── Validaciones de entrada ───────────────────────────
validateMethod('POST');
rateLimitIp('webhook', 200, 60);   // barrera DDoS — la firma Stripe es la protección real

// Leer payload RAW antes de cualquier posible output
$payload   = (string) file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if ($payload === '' || $sigHeader === '') {
    appLog('WARNING', 'webhook', 'Request sin payload o firma', ['ip' => clientIp()]);
    http_response_code(400);
    exit;
}

// ── Verificar firma + replay protection (300 s) ───────
try {
    $event = \Stripe\Webhook::constructEvent(
        $payload,
        $sigHeader,
        STRIPE_WEBHOOK_SECRET,
        300
    );
} catch (\UnexpectedValueException $e) {
    appLog('ERROR', 'webhook', 'Payload inválido', [
        'err' => $e->getMessage(),
        'ip'  => clientIp(),
    ]);
    http_response_code(400);
    exit;
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    appLog('ERROR', 'webhook', 'Firma inválida — posible intrusión', [
        'err' => $e->getMessage(),
        'ip'  => clientIp(),
    ]);
    http_response_code(400);
    exit;
}

// ── Deduplicación: evitar procesar el mismo evento 2 veces ──
if (eventSeen($event->id)) {
    appLog('INFO', 'webhook', 'Evento duplicado omitido', [
        'id'   => $event->id,
        'type' => $event->type,
    ]);
    http_response_code(200);
    echo json_encode(['received' => true, 'status' => 'duplicate']);
    exit;
}

// ── Despachar evento ──────────────────────────────────
appLog('INFO', 'webhook', 'Procesando', [
    'id'   => $event->id,
    'type' => $event->type,
    'mode' => STRIPE_MODE,
]);

try {
    match ($event->type) {
        'checkout.session.completed'    => handleCheckoutCompleted($event->data->object),
        'invoice.paid'                  => handleInvoicePaid($event->data->object),
        'invoice.payment_failed'        => handleInvoicePaymentFailed($event->data->object),
        'customer.subscription.deleted' => handleSubscriptionDeleted($event->data->object),
        'customer.subscription.updated' => handleSubscriptionUpdated($event->data->object),
        default                         => appLog('INFO', 'webhook', 'Evento no gestionado', ['type' => $event->type]),
    };

    markEventSeen($event->id);

} catch (\Throwable $e) {
    // 500 → Stripe reintentará el evento más tarde
    appLog('ERROR', 'webhook', 'Excepción en handler', [
        'id'    => $event->id,
        'type'  => $event->type,
        'class' => get_class($e),
        'err'   => $e->getMessage(),
        'line'  => $e->getLine(),
    ]);
    http_response_code(500);
    exit;
}

http_response_code(200);
echo json_encode(['received' => true]);


// ══════════════════════════════════════════════════════
//  HANDLERS DE EVENTOS
// ══════════════════════════════════════════════════════

function handleCheckoutCompleted(object $session): void
{
    $plan   = sanitize($session->metadata->plan ?? 'desconocido');
    $name   = sanitize($session->customer_details->name  ?? 'Sin nombre');
    $email  = sanitize($session->customer_details->email ?? 'Sin email');
    $amount = number_format(($session->amount_total ?? 0) / 100, 2) . ' €';
    $isTest = str_starts_with((string) $session->id, 'cs_test_');
    $linkId = $session->payment_intent ?? $session->id;
    $prefix = $isTest ? '/test' : '';
    $dashUrl = "https://dashboard.stripe.com{$prefix}/payments/{$linkId}";

    notifyOwner(
        ($isTest ? '[TEST] ' : '') . "Nuevo pago: $plan",
        implode("\n", array_filter([
            "Plan:     $plan",
            "Cliente:  $name",
            "Email:    $email",
            "Importe:  $amount",
            $isTest ? '⚠️  MODO TEST — no es dinero real' : '',
            '',
            "Ver en Stripe: $dashUrl",
        ]))
    );

    appLog('INFO', 'webhook', 'Checkout completado', [
        'plan'    => $plan,
        'amount'  => $session->amount_total ?? 0,
        'is_test' => $isTest,
    ]);
}

function handleInvoicePaid(object $invoice): void
{
    $subId      = $invoice->subscription        ?? null;
    $custEmail  = sanitize($invoice->customer_email ?? 'desconocido');
    $amount     = number_format(($invoice->amount_paid ?? 0) / 100, 2) . ' €';
    $plan       = sanitize($invoice->lines->data[0]->metadata->plan ?? 'mantenimiento');
    $invoiceId  = $invoice->id ?? 'N/A';
    $invoiceUrl = $invoice->hosted_invoice_url ?? null;

    if ($subId) {
        notifyOwner(
            "Mantenimiento cobrado: $amount",
            implode("\n", array_filter([
                "Plan:        $plan",
                "Importe:     $amount",
                "Cliente:     $custEmail",
                "Suscripción: $subId",
                "Factura:     $invoiceId",
                $invoiceUrl ? "Ver factura: $invoiceUrl" : '',
                '',
                'Stripe envía el PDF al cliente automáticamente (Dashboard → Settings → Emails).',
            ]))
        );
    }

    appLog('INFO', 'webhook', 'Mantenimiento cobrado', [
        'sub'    => $subId,
        'plan'   => $plan,
        'amount' => $amount,
    ]);
}

function handleInvoicePaymentFailed(object $invoice): void
{
    $subId     = $invoice->subscription        ?? 'N/A';
    $custEmail = sanitize($invoice->customer_email ?? 'desconocido');
    $attempt   = (int) ($invoice->attempt_count ?? 1);
    $nextRetry = $invoice->next_payment_attempt ?? null;

    notifyOwner(
        "Cobro fallido (intento $attempt)",
        implode("\n", array_filter([
            "Cliente:      $custEmail",
            "Suscripción:  $subId",
            "Intentos:     $attempt",
            $nextRetry
                ? 'Próximo intento: ' . date('d/m/Y H:i', $nextRetry)
                : 'Sin reintentos programados',
            '',
            'Acción: contactar al cliente para actualizar método de pago.',
        ]))
    );

    appLog('WARNING', 'webhook', 'Cobro fallido', [
        'sub'     => $subId,
        'attempt' => $attempt,
        'retry'   => $nextRetry,
    ]);
}

function handleSubscriptionDeleted(object $sub): void
{
    $customerId = $sub->customer ?? 'desconocido';
    $status     = sanitize($sub->status ?? 'desconocido');
    $canceledAt = $sub->canceled_at ? date('d/m/Y H:i', $sub->canceled_at) : 'N/A';
    $priceId    = $sub->items->data[0]->price->id ?? 'desconocido';

    notifyOwner(
        'Suscripción cancelada',
        implode("\n", [
            "Cliente:   $customerId",
            "Price ID:  $priceId",
            "Cancelada: $canceledAt",
            "Estado:    $status",
            '',
            'El cliente ya no será cobrado.',
        ])
    );

    appLog('INFO', 'webhook', 'Suscripción cancelada', [
        'customer' => $customerId,
        'status'   => $status,
        'price'    => $priceId,
    ]);
}

function handleSubscriptionUpdated(object $sub): void
{
    $customerId = $sub->customer ?? 'desconocido';
    $status     = sanitize($sub->status ?? 'desconocido');
    $priceId    = $sub->items->data[0]->price->id ?? 'desconocido';

    if (in_array($status, ['past_due', 'unpaid', 'paused'], true)) {
        notifyOwner(
            "Suscripción en estado: $status",
            implode("\n", [
                "Cliente:  $customerId",
                "Price ID: $priceId",
                "Estado:   $status",
                '',
                'Acción: verificar con el cliente.',
            ])
        );
    }

    appLog('INFO', 'webhook', 'Suscripción actualizada', [
        'customer' => $customerId,
        'status'   => $status,
    ]);
}


// ══════════════════════════════════════════════════════
//  DEDUPLICACIÓN (sliding store en /tmp, ventana 48 h)
// ══════════════════════════════════════════════════════

function eventSeen(string $id): bool
{
    return isset(loadStore()[$id]);
}

function markEventSeen(string $id): void
{
    $store       = loadStore();
    $store[$id]  = time();
    $cutoff      = time() - 172800; // 48 h
    $store       = array_filter($store, fn(int $ts) => $ts > $cutoff);

    file_put_contents(storePath(), json_encode($store), LOCK_EX);
}

function loadStore(): array
{
    $path = storePath();
    if (!file_exists($path)) return [];
    $data = @json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function storePath(): string
{
    return sys_get_temp_dir() . '/mcw_stripe_events.json';
}


// ══════════════════════════════════════════════════════
//  HELPERS LOCALES
// ══════════════════════════════════════════════════════

function notifyOwner(string $subject, string $body): void
{
    $sent = mail(
        OWNER_EMAIL,
        '[MCW] ' . $subject,
        $body,
        "From: noreply@mastercodeweb.com\r\nContent-Type: text/plain; charset=UTF-8\r\n"
    );

    if (!$sent) {
        appLog('ERROR', 'webhook', 'notifyOwner: mail() returned false — notification not delivered', [
            'subject' => $subject,
            'to'      => OWNER_EMAIL,
        ]);
    }
}
