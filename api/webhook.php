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
    $email  = sanitize($session->customer_details->email ?? '');
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

    if ($email !== '') {
        notifyClient($email, $name, $plan, $amount, $isTest);
    }

    appLog('INFO', 'webhook', 'Checkout completado', [
        'plan'       => $plan,
        'amount'     => $session->amount_total ?? 0,
        'is_test'    => $isTest,
        'client_ok'  => $email !== '',
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
    $apiKey = RESEND_API_KEY;
    if ($apiKey === '') {
        appLog('ERROR', 'webhook', 'notifyOwner: RESEND_API_KEY no configurada', ['subject' => $subject]);
        return;
    }

    $html = '<pre style="font-family:monospace;font-size:14px;line-height:1.6;color:#1f2937">'
          . htmlspecialchars($body, ENT_QUOTES, 'UTF-8')
          . '</pre>';

    $ok = sendResendWebhook($apiKey, OWNER_EMAIL, '[MCW] ' . $subject, $html, $body);

    if (!$ok) {
        appLog('ERROR', 'webhook', 'notifyOwner: Resend falló', [
            'subject' => $subject,
            'to'      => OWNER_EMAIL,
        ]);
    }
}

function notifyClient(string $email, string $name, string $plan, string $amount, bool $isTest): void
{
    $apiKey = RESEND_API_KEY;
    if ($apiKey === '') return;

    $planLabels = [
        'basico'        => 'Plan Básico',
        'profesional'   => 'Plan Profesional',
        'premium'       => 'Plan Premium',
        'mant-basico'   => 'Mantenimiento Básico',
        'mant-pro'      => 'Mantenimiento Profesional',
        'mant-premium'  => 'Mantenimiento Premium',
    ];
    $deliveryMap = [
        'basico'        => '5 días laborables',
        'profesional'   => '7 días laborables',
        'premium'       => '10 días laborables',
        'mant-basico'   => 'activación inmediata',
        'mant-pro'      => 'activación inmediata',
        'mant-premium'  => 'activación inmediata',
    ];

    $planLabel = $planLabels[$plan]  ?? $plan;
    $delivery  = $deliveryMap[$plan] ?? '7 días laborables';
    $firstName = explode(' ', trim($name))[0] ?: 'cliente';
    $h         = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    $testBanner = $isTest
        ? '<tr><td style="background:#fef9c3;padding:10px 40px;text-align:center;font-size:12px;color:#92400e">⚠️ MODO TEST — Este es un pago de prueba, no se ha cobrado dinero real.</td></tr>'
        : '';

    $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f8fafc;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;padding:40px 20px">
<tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);max-width:560px">
  <tr><td style="background:#0d6cf2;padding:28px 40px;text-align:center">
    <span style="color:#ffffff;font-size:22px;font-weight:800;letter-spacing:-.5px">MasterCode<span style="color:#7dd3fc">Web</span></span>
  </td></tr>
  {$testBanner}
  <tr><td style="padding:40px">
    <h1 style="margin:0 0 8px;font-size:22px;font-weight:700;color:#111827">¡Pedido confirmado!</h1>
    <p style="margin:0 0 28px;color:#6b7280;font-size:15px">Hola {$h($firstName)}, hemos recibido tu pago correctamente.</p>

    <table width="100%" cellpadding="0" cellspacing="0" style="background:#eff6ff;border-radius:8px;margin-bottom:28px">
      <tr><td style="padding:20px 24px">
        <p style="margin:0 0 4px;font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.5px">Tu pedido</p>
        <p style="margin:0 0 4px;font-size:20px;font-weight:700;color:#111827">{$h($planLabel)}</p>
        <p style="margin:0;font-size:18px;color:#0d6cf2;font-weight:700">{$h($amount)}</p>
      </td></tr>
    </table>

    <p style="margin:0 0 14px;font-size:15px;font-weight:600;color:#111827">¿Qué pasa ahora?</p>
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px">
      <tr><td style="padding:10px 0;border-bottom:1px solid #f3f4f6;font-size:14px;color:#374151">
        <span style="color:#0d6cf2;font-weight:700">01 ·</span>&nbsp; Te enviamos el briefing en menos de 1 hora
      </td></tr>
      <tr><td style="padding:10px 0;border-bottom:1px solid #f3f4f6;font-size:14px;color:#374151">
        <span style="color:#0d6cf2;font-weight:700">02 ·</span>&nbsp; Acordamos los detalles por videollamada o WhatsApp
      </td></tr>
      <tr><td style="padding:10px 0;font-size:14px;color:#374151">
        <span style="color:#0d6cf2;font-weight:700">03 ·</span>&nbsp; Entrega en <strong>{$h($delivery)}</strong> desde la aprobación del briefing
      </td></tr>
    </table>

    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;border-radius:8px;margin-bottom:32px">
      <tr><td style="padding:18px 22px">
        <p style="margin:0 0 8px;font-size:13px;color:#6b7280">¿Tienes alguna duda?</p>
        <p style="margin:0;font-size:14px;color:#374151;line-height:1.8">
          WhatsApp: <a href="https://wa.me/34680762047" style="color:#0d6cf2;text-decoration:none">+34 680 762 047</a><br>
          Email: <a href="mailto:contact@mastercodeweb.com" style="color:#0d6cf2;text-decoration:none">contact@mastercodeweb.com</a>
        </p>
      </td></tr>
    </table>
  </td></tr>
  <tr><td style="background:#f9fafb;padding:18px 40px;text-align:center;border-top:1px solid #e5e7eb">
    <p style="margin:0;font-size:12px;color:#9ca3af">MasterCodeWeb · Base operativa en Algarrobo (Málaga) · <a href="https://www.mastercodeweb.com" style="color:#9ca3af">mastercodeweb.com</a></p>
  </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;

    $prefix    = $isTest ? '[TEST] ' : '';
    $subject   = $prefix . 'Pedido confirmado — ' . $planLabel . ' · MasterCodeWeb';
    $textBody  = "Hola $firstName,\n\nHemos recibido tu pago correctamente.\n\nPlan: $planLabel\nImporte: $amount\n\nSiguientes pasos:\n01. Te enviamos el briefing en menos de 1 hora.\n02. Acordamos los detalles por videollamada o WhatsApp.\n03. Entrega en $delivery desde la aprobación del briefing.\n\n¿Dudas? WhatsApp: +34 680 762 047 · contact@mastercodeweb.com\n\nMasterCodeWeb";

    $ok = sendResendWebhook($apiKey, $email, $subject, $html, $textBody, OWNER_EMAIL);

    if (!$ok) {
        appLog('ERROR', 'webhook', 'notifyClient: Resend falló', [
            'plan' => $plan,
            'to'   => $email,
        ]);
    }
}

function sendResendWebhook(
    string $apiKey,
    string $to,
    string $subject,
    string $html,
    string $text,
    string $replyTo = ''
): bool {
    $payload = [
        'from'    => 'MasterCodeWeb <noreply@mastercodeweb.com>',
        'to'      => [$to],
        'subject' => $subject,
        'html'    => $html,
        'text'    => $text,
    ];
    if ($replyTo !== '') {
        $payload['reply_to'] = $replyTo;
    }

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        appLog('ERROR', 'webhook', 'sendResend: cURL error', ['err' => $curlErr, 'to' => $to]);
        return false;
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        appLog('ERROR', 'webhook', 'sendResend: HTTP error', [
            'code'     => $httpCode,
            'response' => substr((string) $response, 0, 300),
            'to'       => $to,
        ]);
        return false;
    }

    return true;
}
