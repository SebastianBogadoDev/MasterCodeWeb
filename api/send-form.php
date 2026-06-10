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

    $contactRows  = emailRow('Nombre', $h($nombre));
    $contactRows .= emailRow('Email',
        '<a href="mailto:' . $h($email) . '" style="color:#1a56db;text-decoration:none">'
        . $h($email) . '</a>');
    if ($telefono !== '') $contactRows .= emailRow('Teléfono',
        '<a href="tel:' . $h($telefono) . '" style="color:#1a56db;text-decoration:none">'
        . $h($telefono) . '</a>');
    if ($negocio  !== '') $contactRows .= emailRow('Negocio', $h($negocio), true);

    $interestRows = '';
    if ($tipo        !== '') $interestRows .= emailRow('Servicio',    $h($tipo));
    if ($presupuesto !== '') $interestRows .= emailRow('Presupuesto', $h($presupuesto));
    if ($demo_ref    !== '') $interestRows .= emailRow('Demo',        $h($demo_ref));
    if ($paleta_ref  !== '') $interestRows .= emailRow('Paleta',      $h($paleta_ref));
    if ($colores_ref !== '') $interestRows .= emailRow('Colores',     $h($colores_ref));
    if ($vista_ref   !== '') $interestRows .= emailRow('Vista',       $h($vista_ref), true);

    $msg       = nl2br($h($mensaje));
    $replyHref = 'mailto:' . $h($email) . '?subject=' . rawurlencode('Re: tu consulta en MasterCodeWeb');
    $waHref    = 'https://wa.me/34680762047?text=' . rawurlencode('Hola ' . $nombre . ', he recibido tu mensaje en MasterCodeWeb.');

    $interestBlock = '';
    if ($interestRows !== '') {
        $interestBlock = '<p style="margin:0 0 10px;font-family:Arial,Helvetica,sans-serif;font-size:11px;'
            . 'font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#9ca3af">Solicitud</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" '
            . 'style="margin-bottom:20px;background-color:#f9fafb;border-radius:12px;border:1px solid #e2e8f0;overflow:hidden" class="dm-field">'
            . $interestRows . '</table>';
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="es" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>Nuevo lead — MasterCodeWeb</title>
  <style>
    *{-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%}
    body{margin:0;padding:0;background-color:#f5f7fb}
    table{border-collapse:collapse;mso-table-lspace:0pt;mso-table-rspace:0pt}
    a{text-decoration:none}
    @media(prefers-color-scheme:dark){
      .dm-bg{background-color:#0f172a!important}
      .dm-card{background-color:#1e293b!important;border-color:#334155!important}
      .dm-body{background-color:#1e293b!important}
      .dm-field{background-color:#111827!important;border-color:#334155!important}
      .dm-msg{background-color:#0f172a!important;border-color:#334155!important}
      .dm-foot{background-color:#0f172a!important;border-color:#334155!important}
      .dm-val{color:#e2e8f0!important}
      .dm-p{color:#94a3b8!important}
      .dm-link{color:#94a3b8!important}
    }
    @media only screen and (max-width:620px){
      .r-pad{padding:28px 20px!important}
      .r-head{padding:28px 20px!important;border-radius:14px 14px 0 0!important}
      .r-foot{padding:20px!important}
      .btn-col{display:block!important;text-align:center!important;margin-right:0!important;margin-bottom:12px!important}
    }
  </style>
</head>
<body class="dm-bg" style="margin:0;padding:0;background-color:#f5f7fb">
  <div aria-hidden="true" style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;color:#f5f7fb">
    {$h($nombre)} ha enviado un mensaje desde {$h($origen)} en MasterCodeWeb.&#8199;&#65279;&#847;&#8199;&#65279;
  </div>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
         style="background-color:#f5f7fb;width:100%">
    <tr>
      <td align="center" style="padding:48px 16px">
        <!--[if mso]><table role="presentation" align="center" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
        <table role="presentation" cellpadding="0" cellspacing="0" border="0"
               width="600" style="max-width:600px;width:100%;background-color:#ffffff;
               border-radius:16px;border:1px solid #e2e8f0" class="dm-card">
          <tr>
            <td class="r-head" style="background-color:#1a56db;padding:28px 40px;
                border-radius:16px 16px 0 0">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                <tr>
                  <td valign="middle">
                    <p style="margin:0 0 4px;font-family:Arial,Helvetica,sans-serif;
                               font-size:13px;font-weight:700;color:rgba(255,255,255,0.7);
                               text-transform:uppercase;letter-spacing:0.08em">MasterCodeWeb</p>
                    <p style="margin:0;font-family:Arial,Helvetica,sans-serif;
                               font-size:20px;font-weight:700;color:#ffffff;
                               letter-spacing:-0.3px;line-height:1.25">
                      Nuevo lead — {$h($nombre)}
                    </p>
                  </td>
                  <td align="right" valign="middle" style="padding-left:16px;white-space:nowrap">
                    <span style="display:inline-block;background-color:rgba(255,255,255,0.18);
                                 border-radius:6px;padding:5px 11px;
                                 font-family:Arial,Helvetica,sans-serif;font-size:12px;
                                 font-weight:700;color:#ffffff;letter-spacing:0.04em">
                      {$h($origen)}
                    </span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td class="r-pad dm-body" style="background-color:#ffffff;padding:32px 40px">
              <p style="margin:0 0 10px;font-family:Arial,Helvetica,sans-serif;font-size:11px;
                         font-weight:700;text-transform:uppercase;letter-spacing:0.1em;
                         color:#9ca3af">Datos de contacto</p>
              <table role="presentation" cellpadding="0" cellspacing="0" border="0"
                     width="100%" style="margin-bottom:20px;background-color:#f9fafb;
                     border-radius:12px;border:1px solid #e2e8f0;overflow:hidden" class="dm-field">
                {$contactRows}
              </table>
              {$interestBlock}
              <p style="margin:0 0 10px;font-family:Arial,Helvetica,sans-serif;font-size:11px;
                         font-weight:700;text-transform:uppercase;letter-spacing:0.1em;
                         color:#9ca3af">Mensaje</p>
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
                     style="margin-bottom:28px">
                <tr>
                  <td style="background-color:#f9fafb;border-radius:12px;border:1px solid #e2e8f0;
                              padding:16px 20px" class="dm-msg">
                    <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:14px;
                               color:#374151;line-height:1.7;white-space:pre-wrap" class="dm-p">
                      {$msg}
                    </p>
                  </td>
                </tr>
              </table>
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                <tr>
                  <td>
                    <!--[if mso]>
                    <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml"
                      href="{$replyHref}"
                      style="height:46px;v-text-anchor:middle;width:210px;"
                      arcsize="20%" stroke="f" fillcolor="#1a56db">
                    <w:anchorlock/>
                    <center style="color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:700">Responder a {$h($nombre)}</center>
                    </v:roundrect><![endif]-->
                    <!--[if !mso]><!-->
                    <a href="{$replyHref}" class="btn-col"
                       style="display:inline-block;padding:13px 28px;background-color:#1a56db;
                              color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:14px;
                              font-weight:700;line-height:1;text-decoration:none;border-radius:9px;
                              margin-right:10px">
                      Responder a {$h($nombre)}
                    </a>
                    <a href="{$waHref}" class="btn-col"
                       style="display:inline-block;padding:13px 28px;background-color:#dcfce7;
                              color:#15803d;font-family:Arial,Helvetica,sans-serif;font-size:14px;
                              font-weight:700;line-height:1;text-decoration:none;border-radius:9px;
                              border:1px solid #bbf7d0">
                      WhatsApp
                    </a>
                    <!--<![endif]-->
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td class="r-foot dm-foot"
                style="background-color:#f9fafb;padding:20px 40px;text-align:center;
                       border-top:1px solid #e2e8f0;border-radius:0 0 16px 16px">
              <p style="margin:0;font-family:Arial,Helvetica,sans-serif;
                         font-size:12px;color:#9ca3af;line-height:1.5">
                © MasterCodeWeb &nbsp;·&nbsp;
                <a href="mailto:contacto@mastercodeweb.com"
                   style="color:#9ca3af;text-decoration:none">contacto@mastercodeweb.com</a>
                &nbsp;·&nbsp;
                <a href="https://mastercodeweb.com"
                   style="color:#9ca3af;text-decoration:none">mastercodeweb.com</a>
              </p>
            </td>
          </tr>
        </table>
        <!--[if mso]></td></tr></table><![endif]-->
      </td>
    </tr>
  </table>
</body>
</html>
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
    $h   = fn(string $v) => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $msg = nl2br($h($mensaje));

    $rows = '';
    if ($tipo !== '')        $rows .= emailRow('Servicio solicitado', $h($tipo));
    if ($presupuesto !== '') $rows .= emailRow('Presupuesto indicado', $h($presupuesto));
    $rows .= emailRow('Mensaje', $msg, true);

    return <<<HTML
<!DOCTYPE html>
<html lang="es" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>Hemos recibido tu mensaje — MasterCodeWeb</title>
  <style>
    *{-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%}
    body{margin:0;padding:0;background-color:#f5f7fb}
    table{border-collapse:collapse;mso-table-lspace:0pt;mso-table-rspace:0pt}
    a{text-decoration:none}
    @media(prefers-color-scheme:dark){
      .dm-bg{background-color:#0f172a!important}
      .dm-card{background-color:#1e293b!important;border-color:#334155!important}
      .dm-body{background-color:#1e293b!important}
      .dm-field{background-color:#111827!important;border-color:#334155!important}
      .dm-foot{background-color:#0f172a!important;border-color:#334155!important}
      .dm-p{color:#cbd5e1!important}
      .dm-val{color:#e2e8f0!important}
      .dm-link{color:#94a3b8!important}
    }
    @media only screen and (max-width:620px){
      .r-pad{padding:28px 20px!important}
      .r-head{padding:32px 20px!important;border-radius:14px 14px 0 0!important}
      .r-foot{padding:20px!important}
    }
  </style>
</head>
<body class="dm-bg" style="margin:0;padding:0;background-color:#f5f7fb">
  <div aria-hidden="true" style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;color:#f5f7fb">
    Hemos recibido tu solicitud y te responderemos en menos de 24 horas.&#8199;&#65279;&#847;&#8199;&#65279;
  </div>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
         style="background-color:#f5f7fb;width:100%">
    <tr>
      <td align="center" style="padding:48px 16px">
        <!--[if mso]><table role="presentation" align="center" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
        <table role="presentation" cellpadding="0" cellspacing="0" border="0"
               width="600" style="max-width:600px;width:100%;background-color:#ffffff;
               border-radius:16px;border:1px solid #e2e8f0" class="dm-card">
          <tr>
            <td class="r-head" style="background-color:#1a56db;padding:36px 40px;
                border-radius:16px 16px 0 0;text-align:center">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                <tr>
                  <td align="center" style="padding-bottom:14px">
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                      <tr>
                        <td style="background-color:rgba(255,255,255,0.18);border-radius:10px;
                                   padding:8px 15px;line-height:1">
                          <span style="font-family:Arial,Helvetica,sans-serif;font-size:17px;
                                       font-weight:700;color:#ffffff;letter-spacing:-0.2px">MCW</span>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
                <tr>
                  <td align="center">
                    <p style="margin:0 0 8px;font-family:Arial,Helvetica,sans-serif;
                               font-size:22px;font-weight:700;color:#ffffff;
                               letter-spacing:-0.4px;line-height:1.25">
                      ¡Mensaje recibido, {$h($nombre)}!
                    </p>
                    <p style="margin:0;font-family:Arial,Helvetica,sans-serif;
                               font-size:15px;color:rgba(255,255,255,0.85);line-height:1.5">
                      Te respondemos en menos de <strong style="color:#ffffff">24&nbsp;horas</strong>.
                    </p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td class="r-pad dm-body" style="background-color:#ffffff;padding:36px 40px">
              <p style="margin:0 0 28px;font-family:Arial,Helvetica,sans-serif;
                         font-size:15px;color:#374151;line-height:1.7" class="dm-p">
                Gracias por contactar con nosotros. Hemos recibido tu solicitud y
                nuestro equipo se pondrá en contacto contigo en breve.
              </p>
              <table role="presentation" cellpadding="0" cellspacing="0" border="0"
                     width="100%" style="margin-bottom:32px;background-color:#f9fafb;
                     border-radius:12px;border:1px solid #e2e8f0;overflow:hidden"
                     class="dm-field">
                <tr>
                  <td style="padding:13px 20px 11px;border-bottom:1px solid #e2e8f0">
                    <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:11px;
                               font-weight:700;text-transform:uppercase;letter-spacing:0.1em;
                               color:#9ca3af">Resumen de tu solicitud</p>
                  </td>
                </tr>
                {$rows}
              </table>
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                <tr>
                  <td align="center">
                    <!--[if mso]>
                    <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml"
                      href="https://mastercodeweb.com"
                      style="height:50px;v-text-anchor:middle;width:240px;"
                      arcsize="20%" stroke="f" fillcolor="#1a56db">
                    <w:anchorlock/>
                    <center style="color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700">Visitar MasterCodeWeb</center>
                    </v:roundrect><![endif]-->
                    <!--[if !mso]><!-->
                    <a href="https://mastercodeweb.com"
                       style="display:inline-block;padding:15px 36px;background-color:#1a56db;
                              color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:15px;
                              font-weight:700;line-height:1;text-decoration:none;border-radius:10px">
                      Visitar MasterCodeWeb
                    </a>
                    <!--<![endif]-->
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td class="r-foot dm-foot"
                style="background-color:#f9fafb;padding:24px 40px;text-align:center;
                       border-top:1px solid #e2e8f0;border-radius:0 0 16px 16px">
              <p style="margin:0 0 5px;font-family:Arial,Helvetica,sans-serif;
                         font-size:13px;color:#6b7280;line-height:1.5">
                © MasterCodeWeb · Todos los derechos reservados
              </p>
              <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:13px">
                <a href="mailto:contacto@mastercodeweb.com"
                   style="color:#6b7280;text-decoration:none" class="dm-link">
                  contacto@mastercodeweb.com
                </a>
                <span style="color:#d1d5db">&nbsp;·&nbsp;</span>
                <a href="https://mastercodeweb.com"
                   style="color:#6b7280;text-decoration:none" class="dm-link">
                  mastercodeweb.com
                </a>
              </p>
            </td>
          </tr>
        </table>
        <!--[if mso]></td></tr></table><![endif]-->
      </td>
    </tr>
  </table>
</body>
</html>
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

function emailRow(string $label, string $value, bool $last = false): string
{
    $border = $last ? '' : 'border-bottom:1px solid #e2e8f0;';
    return "<tr><td style=\"padding:13px 20px;{$border}\">"
        . "<p style=\"margin:0 0 3px;font-family:Arial,Helvetica,sans-serif;font-size:11px;"
        . "font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#9ca3af\">{$label}</p>"
        . "<p style=\"margin:0;font-family:Arial,Helvetica,sans-serif;font-size:14px;"
        . "color:#111827;line-height:1.6\" class=\"dm-val\">{$value}</p>"
        . "</td></tr>\n";
}
