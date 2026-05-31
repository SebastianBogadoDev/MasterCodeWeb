<?php
/* config/rate-limiter.php — Rate limiting por IP usando sliding window en /tmp
   Sin Redis ni APCu: compatible con Hostinger shared hosting.               */

/**
 * Limita requests por identificador (endpoint + IP).
 * Termina con 429 si se supera el límite.
 */
function rateLimit(string $identifier, int $maxRequests = 20, int $windowSeconds = 60): void
{
    $path = sys_get_temp_dir() . '/mcw_rl_' . md5($identifier) . '.json';
    $now  = time();

    $timestamps = [];
    if (file_exists($path)) {
        $raw        = @file_get_contents($path);
        $timestamps = $raw ? (json_decode($raw, true) ?? []) : [];
    }

    // Sliding window: descartar timestamps fuera de la ventana
    $timestamps = array_values(array_filter(
        $timestamps,
        fn(int $ts) => ($now - $ts) < $windowSeconds
    ));

    if (count($timestamps) >= $maxRequests) {
        $oldestTs  = min($timestamps);
        $retryAfter = $windowSeconds - ($now - $oldestTs);

        appLog('WARNING', 'rate-limiter', 'Límite excedido', [
            'id'      => substr($identifier, 0, 40),
            'count'   => count($timestamps),
            'limit'   => $maxRequests,
            'window'  => $windowSeconds,
        ]);

        http_response_code(429);
        header('Retry-After: ' . max(1, $retryAfter));
        echo json_encode([
            'error'       => 'Demasiadas solicitudes. Inténtalo en unos segundos.',
            'retry_after' => max(1, $retryAfter),
        ]);
        exit;
    }

    $timestamps[] = $now;
    @file_put_contents($path, json_encode($timestamps), LOCK_EX);
}

/**
 * Atajo: rate limit automático por IP del cliente + nombre de endpoint.
 */
function rateLimitIp(string $endpoint, int $maxRequests = 20, int $windowSeconds = 60): void
{
    rateLimit(clientIp() . ':' . $endpoint, $maxRequests, $windowSeconds);
}

/**
 * IP real del cliente, respetando Cloudflare y proxies.
 * Función compartida — también usada en csrf.php y validator.php.
 */
function clientIp(): string
{
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP']    // Cloudflare (más fiable)
       ?? $_SERVER['HTTP_X_FORWARDED_FOR']
       ?? $_SERVER['REMOTE_ADDR']
       ?? '0.0.0.0';

    // Tomar solo la primera IP si hay lista (X-Forwarded-For puede tener varias)
    return trim(explode(',', $ip)[0]);
}
