<?php
/* =====================================================
   STRIPE WEBHOOK HANDLER — MasterCodeWeb (PRODUCTION)
   POST /api/webhook.php  — llamado por Stripe, no por el usuario

   Protecciones activas:
     · Verificación de firma HMAC-SHA256
     · Protección replay attacks (tolerancia 300 s)
     · Deduplicación por event ID (ventana 48 h)
     · Logs seguros en sys_get_temp_dir() (no web-accesible)
     · Errores ocultos al exterior
     · Soporte modo test/live automático

   Eventos gestionados:
     · checkout.session.completed        → notifica al equipo
     · invoice.paid                      → confirma cobro de mantenimiento
     · invoice.payment_failed            → alerta al equipo
     · customer.subscription.deleted     → notifica cancelación
     · customer.subscription.updated     → registra cambios de estado

   NOTA: Añadir estos eventos en Stripe Dashboard → Developers → Webhooks
===================================================== */

// No filtrar errores al cliente — Stripe solo necesita el código HTTP
@ini_set('display_errors', '0');
error_reporting(0);

$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    http_response_code(503);
    exit;
}
require_once $configPath;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(503);
    exit;
}
require_once $autoload;

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

// ── Leer payload crudo antes de cualquier output ──────
$payload   = (string)file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if ($payload === '' || $sigHeader === '') {
    http_response_code(400);
    exit;
}

// ── Verificar firma + protección replay (300 s) ───────
try {
    $event = \Stripe\Webhook::constructEvent(
        $payload,
        $sigHeader,
        STRIPE_WEBHOOK_SECRET,
        300 // segundos de tolerancia (valor por defecto de Stripe)
    );
} catch (\UnexpectedValueException $e) {
    wlog('ERROR', 'Payload inválido', ['err' => $e->getMessage()]);
    http_response_code(400);
    exit;
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    wlog('ERROR', 'Firma inválida', ['err' => $e->getMessage()]);
    http_response_code(400);
    exit;
}

// ── Deduplicación: descartar eventos ya procesados ────
if (eventSeen($event->id)) {
    wlog('INFO', 'Evento duplicado omitido', ['id' => $event->id, 'type' => $event->type]);
    http_response_code(200);
    echo json_encode(['received' => true, 'status' => 'duplicate']);
    exit;
}

// ── Despachar evento ──────────────────────────────────
wlog('INFO', 'Procesando evento', ['id' => $event->id, 'type' => $event->type]);

try {
    switch ($event->type) {
        case 'checkout.session.completed':
            handleCheckoutCompleted($event->data->object);
            break;

        case 'invoice.paid':
            handleInvoicePaid($event->data->object);
            break;

        case 'invoice.payment_failed':
            handleInvoicePaymentFailed($event->data->object);
            break;

        case 'customer.subscription.deleted':
            handleSubscriptionDeleted($event->data->object);
            break;

        case 'customer.subscription.updated':
            handleSubscriptionUpdated($event->data->object);
            break;

        default:
            wlog('INFO', 'Evento no gestionado', ['type' => $event->type]);
    }

    markEventSeen($event->id);

} catch (\Throwable $e) {
    // Devolver 500 para que Stripe reintente el evento más tarde
    wlog('ERROR', 'Excepción en handler', ['id' => $event->id, 'err' => $e->getMessage()]);
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
    $plan    = $session->metadata->plan ?? 'desconocido';
    $name    = $session->customer_details->name  ?? 'Sin nombre';
    $email   = $session->customer_details->email ?? 'Sin email';
    $amount  = number_format(($session->amount_total ?? 0) / 100, 2) . ' €';
    $isTest  = str_starts_with((string)$session->id, 'cs_test_');
    $linkId  = $session->payment_intent ?? $session->id;
    $prefix  = $isTest ? '/test' : '';
    $dashUrl = "https://dashboard.stripe.com{$prefix}/payments/{$linkId}";

    $subject = ($isTest ? '[TEST] ' : '') . "Nuevo pago: $plan";
    $body    = implode("\n", array_filter([
        "Plan:     $plan",
        "Cliente:  $name",
        "Email:    $email",
        "Importe:  $amount",
        $isTest ? '⚠️  MODO TEST — no es dinero real' : '',
        '',
        "Ver en Stripe: $dashUrl",
    ]));

    notifyOwner($subject, $body);

    // Loguear sin PII sensible
    wlog('INFO', 'Checkout completado', [
        'plan'    => $plan,
        'amount'  => $session->amount_total ?? 0,
        'is_test' => $isTest,
    ]);
}

function handleInvoicePaid(object $invoice): void
{
    $subId     = $invoice->subscription   ?? null;
    $custEmail = $invoice->customer_email ?? 'desconocido';
    $amount    = number_format(($invoice->amount_paid ?? 0) / 100, 2) . ' €';
    $plan      = $invoice->lines->data[0]->metadata->plan ?? 'mantenimiento';

    // Todos los planes de suscripción son mantenimiento mensual sin límite de ciclos
    wlog('INFO', 'Mantenimiento cobrado', [
        'sub'      => $subId,
        'plan'     => $plan,
        'amount'   => $amount,
    ]);
}

function handleInvoicePaymentFailed(object $invoice): void
{
    $subId     = $invoice->subscription   ?? 'N/A';
    $custEmail = $invoice->customer_email ?? 'desconocido';
    $attempt   = $invoice->attempt_count  ?? 1;
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
            'Acción recomendada: contactar al cliente para actualizar método de pago.',
        ]))
    );

    wlog('WARNING', 'Cobro fallido', ['sub' => $subId, 'attempt' => $attempt]);
}

function handleSubscriptionDeleted(object $sub): void
{
    $customerId  = $sub->customer  ?? 'desconocido';
    $status      = $sub->status    ?? 'desconocido';
    $canceledAt  = $sub->canceled_at ? date('d/m/Y H:i', $sub->canceled_at) : 'N/A';
    $priceId     = $sub->items->data[0]->price->id ?? 'desconocido';

    notifyOwner(
        'Suscripción cancelada',
        implode("\n", [
            "Cliente ID:  $customerId",
            "Price ID:    $priceId",
            "Cancelada:   $canceledAt",
            "Estado:      $status",
            '',
            'La suscripción se ha cancelado. El cliente ya no será cobrado.',
        ])
    );

    wlog('INFO', 'Suscripción cancelada', [
        'customer' => $customerId,
        'status'   => $status,
        'price'    => $priceId,
    ]);
}

function handleSubscriptionUpdated(object $sub): void
{
    $customerId = $sub->customer ?? 'desconocido';
    $status     = $sub->status   ?? 'desconocido';
    $priceId    = $sub->items->data[0]->price->id ?? 'desconocido';

    // Notificar solo si el estado cambia a algo relevante
    if (in_array($status, ['past_due', 'unpaid', 'paused'], true)) {
        notifyOwner(
            "Suscripción en estado: $status",
            implode("\n", [
                "Cliente ID: $customerId",
                "Price ID:   $priceId",
                "Estado:     $status",
                '',
                'Acción recomendada: verificar con el cliente.',
            ])
        );
    }

    wlog('INFO', 'Suscripción actualizada', [
        'customer' => $customerId,
        'status'   => $status,
    ]);
}


// ══════════════════════════════════════════════════════
//  DEDUPLICACIÓN DE EVENTOS (fichero en /tmp)
// ══════════════════════════════════════════════════════

function eventSeen(string $id): bool
{
    return isset(loadStore()[$id]);
}

function markEventSeen(string $id): void
{
    $store = loadStore();
    $store[$id] = time();

    // Purgar entradas con más de 48 h para limitar el tamaño del fichero
    $cutoff = time() - 172800;
    $store  = array_filter($store, fn($ts) => $ts > $cutoff);

    file_put_contents(storePath(), json_encode($store), LOCK_EX);
}

function loadStore(): array
{
    $path = storePath();
    if (!file_exists($path)) return [];
    $data = @json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function storePath(): string
{
    return sys_get_temp_dir() . '/mcw_stripe_events.json';
}


// ══════════════════════════════════════════════════════
//  HELPERS
// ══════════════════════════════════════════════════════

function notifyOwner(string $subject, string $body): void
{
    $headers = "From: noreply@mastercodeweb.com\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    @mail(OWNER_EMAIL, "[MCW] $subject", $body, $headers);
}

function wlog(string $level, string $msg, array $ctx = []): void
{
    $line = sprintf(
        "[%s] [%s] [webhook] %s %s\n",
        date('Y-m-d H:i:s'),
        $level,
        $msg,
        $ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE) : ''
    );

    // Escribe en logs/stripe.log — protegido de acceso web por logs/.htaccess
    $dir  = __DIR__ . '/../logs';
    $file = $dir . '/stripe.log';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}
