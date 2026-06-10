<?php
/* =====================================================
   SEND FORM — MasterCodeWeb
   POST /api/send-form.php

   Recibe JSON del formulario de contacto/presupuesto,
   envía dos correos vía Resend API (cURL):
     1. Notificación al propietario  → OWNER_EMAIL
     2. Confirmación al cliente      → email del usuario

   Protecciones:
     · Método POST obligatorio
     · Rate limit por IP
     · Validación de campos mínimos
     · Sanitización de inputs
     · RESEND_API_KEY nunca expuesta al cliente
     · Logs en storage/logs/stripe.log
===================================================== */

@ini_set('display_errors', '0');

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . SITE_URL);
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');

validateMethod('POST');
rateLimitIp('send-form', 10, 60);

// ── Leer body JSON ────────────────────────────────────
$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'JSON no válido.']);
    exit;
}

// ── Campos mínimos requeridos ─────────────────────────
$nombre  = sanitize($body['nombre']  ?? '');
$email   = trim($body['email']       ?? '');
$mensaje = sanitize($body['mensaje'] ?? '');

if ($nombre === '' || $mensaje === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Campos requeridos: nombre, mensaje.']);
    exit;
}

$email = validateEmail($email);   // termina con 400 si inválido

// ── Campos opcionales ─────────────────────────────────
$negocio     = sanitize($body['negocio']     ?? '');
$telefono    = sanitize($body['telefono']    ?? '');
$tipo        = sanitize($body['tipo']        ?? '');
$presupuesto = sanitize($body['presupuesto'] ?? '');
$demo_ref    = sanitize($body['demo_ref']    ?? '');
$paleta_ref  = sanitize($body['paleta_ref']  ?? '');
$colores_ref = sanitize($body['colores_ref'] ?? '');
$vista_ref   = sanitize($body['vista_ref']   ?? '');
$origen      = sanitize($body['origen']      ?? 'formulario');

// ── Verificar API key ─────────────────────────────────
$apiKey = RESEND_API_KEY;
if ($apiKey === '') {
    appLog('ERROR', 'send-form', 'RESEND_API_KEY no configurada');
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Servicio de correo no disponible.']);
    exit;
}

// ── Enviar correos ────────────────────────────────────
$ownerOk = sendResend(
    $apiKey,
    'MasterCodeWeb <noreply@mastercodeweb.com>',
    [OWNER_EMAIL],
    '[MCW] Nuevo mensaje: ' . $nombre,
    buildOwnerHtml($nombre, $negocio, $email, $telefono, $tipo, $presupuesto, $mensaje, $demo_ref, $paleta_ref, $colores_ref, $vista_ref, $origen),
    buildOwnerText($nombre, $negocio, $email, $telefono, $tipo, $presupuesto, $mensaje, $origen),
    $email   // reply-to
);

// Confirmación al cliente (no bloquea si falla)
$clientOk = sendResend(
    $apiKey,
    'MasterCodeWeb <noreply@mastercodeweb.com>',
    [$email],
    'Hemos recibido tu mensaje, ' . $nombre,
    buildClientHtml($nombre, $tipo, $presupuesto, $mensaje),
    buildClientText($nombre, $tipo, $presupuesto, $mensaje)
);

if (!$ownerOk) {
    appLog('ERROR', 'send-form', 'Resend: fallo en correo al propietario', [
        'from'  => $email,
        'ip'    => clientIp(),
        'origen'=> $origen,
    ]);
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'No se pudo enviar el mensaje. Inténtalo de nuevo.']);
    exit;
}

appLog('INFO', 'send-form', 'Formulario enviado', [
    'origen'  => $origen,
    'client_ok' => $clientOk,
    'ip'      => clientIp(),
]);

echo json_encode(['success' => true]);


// ══════════════════════════════════════════════════════
//  RESEND — envío via cURL
// ══════════════════════════════════════════════════════

function sendResend(
    string $apiKey,
    string $from,
    array  $to,
    string $subject,
    string $html,
    string $text,
    string $replyTo = ''
): bool {
    $payload = [
        'from'    => $from,
        'to'      => $to,
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
        appLog('ERROR', 'send-form', 'Resend cURL error', ['err' => $curlErr]);
        return false;
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        appLog('ERROR', 'send-form', 'Resend HTTP error', [
            'code'     => $httpCode,
            'response' => substr((string) $response, 0, 300),
        ]);
        return false;
    }

    return true;
}


// ══════════════════════════════════════════════════════
//  PLANTILLAS
// ══════════════════════════════════════════════════════

function buildOwnerHtml(
    string $nombre, string $negocio, string $email, string $telefono,
    string $tipo, string $presupuesto, string $mensaje,
    string $demo_ref, string $paleta_ref, string $colores_ref, string $vista_ref,
    string $origen
): string {
    $h = fn(string $v) => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $extraRows = '';
    if ($negocio    !== '') $extraRows .= row('Negocio',    $h($negocio));
    if ($telefono   !== '') $extraRows .= row('Teléfono',   $h($telefono));
    if ($tipo       !== '') $extraRows .= row('Servicio',   $h($tipo));
    if ($presupuesto!== '') $extraRows .= row('Presupuesto',$h($presupuesto));
    if ($demo_ref   !== '') $extraRows .= row('Demo',       $h($demo_ref));
    if ($paleta_ref !== '') $extraRows .= row('Paleta',     $h($paleta_ref));
    if ($colores_ref!== '') $extraRows .= row('Colores',    $h($colores_ref));
    if ($vista_ref  !== '') $extraRows .= row('Vista',      $h($vista_ref));

    $replyHref  = 'mailto:' . $h($email) . '?subject=' . rawurlencode('Re: ' . $nombre);
    $waHref     = 'https://wa.me/34680762047?text=' . rawurlencode('Hola ' . $nombre . ', he recibido tu mensaje.');

    return <<<HTML
    <!DOCTYPE html>
    <html lang="es">
    <head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
      body{margin:0;padding:0;background:#080d18;font-family:Inter,Arial,sans-serif;color:#e2e8f0}
      .wrap{max-width:600px;margin:32px auto;background:#111827;border-radius:12px;overflow:hidden}
      .head{background:#0d6cf2;padding:28px 32px}
      .head h1{margin:0;font-size:20px;color:#fff}
      .body{padding:28px 32px}
      table{width:100%;border-collapse:collapse;margin-bottom:20px}
      td{padding:10px 14px;border-bottom:1px solid #1f2937;font-size:14px;vertical-align:top}
      td:first-child{color:#94a3b8;width:130px;white-space:nowrap}
      .msg{background:#1f2937;border-radius:8px;padding:16px;font-size:14px;line-height:1.7;white-space:pre-wrap}
      .btn{display:inline-block;padding:12px 24px;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none;margin-right:12px;margin-top:20px}
      .btn-blue{background:#0d6cf2;color:#fff}
      .btn-green{background:#10b981;color:#fff}
      .foot{padding:16px 32px;font-size:12px;color:#6b7280;border-top:1px solid #1f2937}
    </style>
    </head>
    <body>
    <div class="wrap">
      <div class="head"><h1>Nuevo mensaje de {$h($origen)}</h1></div>
      <div class="body">
        <table>
          {$extraRows}
          <tr><td>Nombre</td><td>{$h($nombre)}</td></tr>
          <tr><td>Email</td><td><a href="{$replyHref}" style="color:#0d6cf2">{$h($email)}</a></td></tr>
        </table>
        <p style="margin:0 0 8px;font-size:13px;color:#94a3b8">Mensaje:</p>
        <div class="msg">{$h($mensaje)}</div>
        <div>
          <a href="{$replyHref}" class="btn btn-blue">Responder a {$h($nombre)}</a>
          <a href="{$waHref}" class="btn btn-green">WhatsApp</a>
        </div>
      </div>
      <div class="foot">MasterCodeWeb · noreply@mastercodeweb.com</div>
    </div>
    </body></html>
    HTML;
}

function buildOwnerText(
    string $nombre, string $negocio, string $email, string $telefono,
    string $tipo, string $presupuesto, string $mensaje, string $origen
): string {
    $lines = ["Nuevo mensaje de: $origen", str_repeat('-', 40)];
    $lines[] = "Nombre:      $nombre";
    $lines[] = "Email:       $email";
    if ($telefono    !== '') $lines[] = "Teléfono:    $telefono";
    if ($negocio     !== '') $lines[] = "Negocio:     $negocio";
    if ($tipo        !== '') $lines[] = "Servicio:    $tipo";
    if ($presupuesto !== '') $lines[] = "Presupuesto: $presupuesto";
    $lines[] = '';
    $lines[] = "Mensaje:";
    $lines[] = $mensaje;
    return implode("\n", $lines);
}

function buildClientHtml(
    string $nombre, string $tipo, string $presupuesto, string $mensaje
): string {
    $h = fn(string $v) => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $extraRows = '';
    if ($tipo       !== '') $extraRows .= row('Servicio',    $h($tipo));
    if ($presupuesto!== '') $extraRows .= row('Presupuesto', $h($presupuesto));

    return <<<HTML
    <!DOCTYPE html>
    <html lang="es">
    <head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
      body{margin:0;padding:0;background:#080d18;font-family:Inter,Arial,sans-serif;color:#e2e8f0}
      .wrap{max-width:600px;margin:32px auto;background:#111827;border-radius:12px;overflow:hidden}
      .head{background:#0d6cf2;padding:28px 32px}
      .head h1{margin:0;font-size:20px;color:#fff}
      .body{padding:28px 32px;font-size:15px;line-height:1.7}
      table{width:100%;border-collapse:collapse;margin:16px 0}
      td{padding:10px 14px;border-bottom:1px solid #1f2937;font-size:14px;vertical-align:top}
      td:first-child{color:#94a3b8;width:130px}
      .msg{background:#1f2937;border-radius:8px;padding:16px;font-size:14px;line-height:1.7;white-space:pre-wrap}
      .btn{display:inline-block;padding:12px 24px;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none;background:#0d6cf2;color:#fff;margin-top:20px}
      .foot{padding:16px 32px;font-size:12px;color:#6b7280;border-top:1px solid #1f2937}
    </style>
    </head>
    <body>
    <div class="wrap">
      <div class="head"><h1>¡Mensaje recibido, {$h($nombre)}!</h1></div>
      <div class="body">
        <p>Gracias por contactar con MasterCodeWeb. Te responderemos en menos de 24 horas.</p>
        <p>Resumen de tu solicitud:</p>
        <table>
          {$extraRows}
          <tr><td>Mensaje</td><td>{$h($mensaje)}</td></tr>
        </table>
        <a href="https://www.mastercodeweb.com/pages/presupuesto.html" class="btn">Ver nuestros servicios</a>
      </div>
      <div class="foot">© MasterCodeWeb · Todos los derechos reservados.<br><a href="https://mastercodeweb.com" style="color:#6b7280;text-decoration:none;">https://mastercodeweb.com</a></div>
    </div>
    </body></html>
    HTML;
}

function buildClientText(
    string $nombre, string $tipo, string $presupuesto, string $mensaje
): string {
    $lines   = ["Hola $nombre,", ''];
    $lines[] = 'Gracias por contactar con MasterCodeWeb. Te responderemos en menos de 24 horas.';
    $lines[] = '';
    $lines[] = 'Resumen de tu solicitud:';
    $lines[] = str_repeat('-', 40);
    if ($tipo        !== '') $lines[] = "Servicio:    $tipo";
    if ($presupuesto !== '') $lines[] = "Presupuesto: $presupuesto";
    $lines[] = "Mensaje:     $mensaje";
    $lines[] = '';
    $lines[] = '— MasterCodeWeb';
    return implode("\n", $lines);
}

function row(string $label, string $value): string
{
    return "<tr><td>{$label}</td><td>{$value}</td></tr>";
}
