<?php
/* =====================================================
   DIAGNÓSTICO TEMPORAL — api/diag-form.php
   ELIMINAR DEL SERVIDOR DESPUÉS DE USAR.
   No modifica ningún flujo de producción.

   Uso: GET /api/diag-form.php?token=mcw_diag_2026
===================================================== */
@ini_set('display_errors', '0');

// Token antes de cargar nada — si alguien lo llama sin token, sale aquí
$token = $_GET['token'] ?? '';
if ($token !== 'mcw_diag_2026') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Token requerido: ?token=mcw_diag_2026']);
    exit;
}

// Si bootstrap falla (VENDOR_NOT_FOUND, ENV_ERROR…) su JSON de error
// es diagnóstico válido — sale antes de llegar al resto del script.
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$r = [];

// ── 1. Entorno ────────────────────────────────────────
$r['php_version'] = PHP_VERSION;
$r['app_env']     = defined('APP_ENV') ? APP_ENV : ($_ENV['APP_ENV'] ?? 'unknown');
$r['site_url']    = SITE_URL;

// ── 2. Archivos clave ─────────────────────────────────
$root    = dirname(__DIR__);
$r['files'] = [
    '.env'            => file_exists($root . '/.env')                ? 'found' : 'MISSING',
    'vendor/autoload' => file_exists($root . '/vendor/autoload.php') ? 'found' : 'MISSING',
    'send-form.php'   => file_exists(__DIR__ . '/send-form.php')     ? 'found' : 'MISSING',
];

// ── 3. Variables de entorno (sin exponer valores) ─────
$apiKey     = RESEND_API_KEY;
$ownerEmail = OWNER_EMAIL;

// Detectar fuente real de RESEND_API_KEY
$keyFromEnv    = $_ENV['RESEND_API_KEY']        ?? '';
$keyFromGetenv = (string)(getenv('RESEND_API_KEY') ?: '');
$keyFromServer = $_SERVER['RESEND_API_KEY']     ?? '';

if ($keyFromEnv !== '')    $keySource = '$_ENV';
elseif ($keyFromGetenv !== '') $keySource = 'getenv()';
elseif ($keyFromServer !== '') $keySource = '$_SERVER';
else                           $keySource = 'MISSING en todas las fuentes';

// Detectar caracteres no imprimibles (espacios, newlines, BOM) en la clave
$keyLen     = strlen($apiKey);
$keyTrimmed = trim($apiKey);
$hasPadding = ($keyLen !== strlen($keyTrimmed));
$hasNonPrintable = $keyLen !== strlen(preg_replace('/[^\x20-\x7E]/', '', $apiKey));

$r['env'] = [
    'RESEND_API_KEY' => $apiKey === ''
        ? 'MISSING — no configurada en .env'
        : [
            'status'          => 'presente',
            'longitud'        => $keyLen,
            'primeros_10'     => substr($apiKey, 0, 10),
            'ultimos_10'      => substr($apiKey, -10),
            'fuente'          => $keySource,
            'tiene_espacios_o_newlines' => $hasPadding,
            'tiene_no_imprimibles'      => $hasNonPrintable,
            'env_file_path'   => $root . '/.env',
            'env_line'        => (function() use ($root): string {
                $file = $root . '/.env';
                if (!file_exists($file)) return '.env no encontrado';
                foreach (file($file, FILE_IGNORE_NEW_LINES) as $i => $line) {
                    if (str_starts_with(ltrim($line), 'RESEND_API_KEY')) {
                        return 'línea ' . ($i + 1) . ': ' . preg_replace('/=.{4}(.+).{4}$/', '=****$1****', $line);
                    }
                }
                return 'RESEND_API_KEY no encontrada en .env';
            })(),
          ],
    'OWNER_EMAIL'    => $ownerEmail === ''
        ? 'MISSING'
        : (explode('@', $ownerEmail)[0][0] ?? '?') . '***@' . (explode('@', $ownerEmail)[1] ?? '?'),
    'SITE_URL'       => SITE_URL !== '' ? 'ok' : 'MISSING',
];

// ── 4. cURL ───────────────────────────────────────────
$r['curl'] = function_exists('curl_init') ? 'available' : 'NOT AVAILABLE';

// ── 5. Resend API — GET /domains (no envía correo) ────
$resend = ['reachable' => false, 'http_code' => null, 'auth' => 'unknown', 'domain' => null];

if ($apiKey !== '' && function_exists('curl_init')) {
    $ch = curl_init('https://api.resend.com/domains');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET        => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $body     = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    $resend['http_code']  = $httpCode;
    $resend['reachable']  = $httpCode > 0;
    $resend['curl_error'] = $curlErr ?: null;

    if ($httpCode === 200) {
        $resend['auth'] = 'ok — API key válida';
        $data    = json_decode((string) $body, true);
        $domains = $data['data'] ?? [];
        $found   = null;
        foreach ($domains as $d) {
            if (str_contains($d['name'] ?? '', 'mastercodeweb')) {
                $found = ['name' => $d['name'], 'status' => $d['status']];
                break;
            }
        }
        $resend['domain'] = $found
            ? $found
            : 'mastercodeweb.com NO encontrado en Resend — dominio sin verificar';
    } elseif ($httpCode === 401) {
        $resend['auth'] = 'API KEY INVÁLIDA (401)';
    } elseif ($httpCode === 403) {
        $resend['auth'] = 'FORBIDDEN (403) — sin permisos';
    } elseif ($httpCode === 0) {
        $resend['auth'] = 'SIN CONEXIÓN — error de red o DNS';
    } else {
        $resend['auth'] = "HTTP inesperado: $httpCode";
    }
} elseif ($apiKey === '') {
    $resend['auth'] = 'OMITIDO — RESEND_API_KEY vacía';
} else {
    $resend['auth'] = 'OMITIDO — cURL no disponible';
}
$r['resend'] = $resend;

// ── 6. Últimas líneas del log ─────────────────────────
$logPath = $root . '/storage/logs/stripe.log';
if (file_exists($logPath) && is_readable($logPath)) {
    $size = filesize($logPath);
    $fh   = fopen($logPath, 'r');
    fseek($fh, max(0, $size - 2000));
    $tail = fread($fh, 2000);
    fclose($fh);
    $lines               = array_values(array_filter(explode("\n", $tail)));
    $r['log_tail']       = array_slice($lines, -12);
    $r['log_size_bytes'] = $size;
} else {
    $r['log_tail']       = 'sin log todavía (ningún request procesado)';
    $r['log_size_bytes'] = 0;
}

echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
