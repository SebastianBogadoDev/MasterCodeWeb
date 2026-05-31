<?php
/* config/validator.php — helpers de validación y sanitización de inputs */

/**
 * Valida método HTTP. Gestiona preflight OPTIONS automáticamente.
 * Uso: validateMethod('POST');  validateMethod('GET', 'POST');
 */
function validateMethod(string ...$allowed): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    if (!in_array($_SERVER['REQUEST_METHOD'], $allowed, true)) {
        http_response_code(405);
        header('Allow: ' . implode(', ', $allowed));
        echo json_encode(['error' => 'Método no permitido.']);
        exit;
    }
}

/**
 * Lee y valida body JSON. Termina con 400 si no es JSON válido.
 */
function parseJsonBody(): array
{
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['error' => 'JSON no válido en el body.']);
        exit;
    }
    return $body;
}

/**
 * Verifica que los campos existen y no están vacíos.
 */
function requireFields(array $body, string ...$fields): void
{
    foreach ($fields as $f) {
        $val = $body[$f] ?? null;
        if ($val === null || $val === '' || (is_string($val) && trim($val) === '')) {
            http_response_code(400);
            echo json_encode(['error' => "Campo requerido: $f"]);
            exit;
        }
    }
}

/**
 * Limpia string: trim + strip_tags + longitud máxima.
 */
function sanitize(string $value, int $maxLen = 255): string
{
    return substr(trim(strip_tags($value)), 0, $maxLen);
}

/**
 * Valida y normaliza email. Termina con 400 si es inválido.
 */
function validateEmail(string $raw): string
{
    $email = strtolower(trim($raw));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        http_response_code(400);
        echo json_encode(['error' => 'Email no válido.']);
        exit;
    }
    return $email;
}

/**
 * Valida que la clave de plan existe en el catálogo PLANS.
 */
function validatePlan(string $key): void
{
    if (!isset(PLANS[$key])) {
        http_response_code(400);
        echo json_encode([
            'error'  => "Plan no reconocido: '$key'.",
            'validos' => array_keys(PLANS),
        ]);
        exit;
    }
}

/**
 * Valida formato de ID de Stripe (cus_..., sub_..., cs_..., etc).
 */
function validateStripeId(string $value, string $prefix, string $field = 'id'): string
{
    $value = trim($value);
    if (!str_starts_with($value, $prefix)) {
        http_response_code(400);
        echo json_encode(['error' => "Formato inválido para '$field'. Esperado: {$prefix}..."]);
        exit;
    }
    // Solo caracteres alfanuméricos y _ después del prefijo
    $suffix = substr($value, strlen($prefix));
    if (!preg_match('/^[A-Za-z0-9_]+$/', $suffix) || strlen($suffix) < 4) {
        http_response_code(400);
        echo json_encode(['error' => "ID de Stripe inválido para '$field'."]);
        exit;
    }
    return $value;
}

/**
 * Emite headers CORS + seguridad estándar para endpoints API.
 */
function apiHeaders(string $methods = 'POST'): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: '  . SITE_URL);
    header('Access-Control-Allow-Methods: ' . $methods);
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Cache-Control: no-store, no-cache, must-revalidate');
}
