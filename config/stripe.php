<?php
/* config/stripe.php
   Inicialización Stripe + entorno + planes
*/

// ─────────────────────────────────────────────
// ENTORNO
// ─────────────────────────────────────────────

// $_ENV puede estar vacío en Hostinger (variables_order sin 'E').
// getenv() lee del proceso y siempre funciona como fallback.
define('APP_ENV',
    (($_ENV['APP_ENV'] ?? '') !== '')
        ? $_ENV['APP_ENV']
        : ((string)(getenv('APP_ENV') ?: 'production'))
);

define(
    'STRIPE_MODE',
    str_starts_with(STRIPE_SECRET_KEY, 'sk_test_')
        ? 'test'
        : 'live'
);

// Clave pública Stripe.js
define('STRIPE_PUBLIC_KEY',
    (($_ENV['STRIPE_PUBLIC_KEY'] ?? '') !== '')
        ? $_ENV['STRIPE_PUBLIC_KEY']
        : ((string)(getenv('STRIPE_PUBLIC_KEY') ?: ''))
);

// ─────────────────────────────────────────────
// SDK STRIPE
// ─────────────────────────────────────────────

try {
    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
    \Stripe\Stripe::setMaxNetworkRetries(2);
} catch (\Throwable $e) {
    throw new \RuntimeException('Stripe SDK init failed: ' . $e->getMessage(), 0, $e);
}

// ─────────────────────────────────────────────
// PLANES
// ─────────────────────────────────────────────

define('PLANS', [

    // Pago único
    'basico' => [
        PRICE_BASICO,
        'payment'
    ],

    'profesional' => [
        PRICE_PRO,
        'payment'
    ],

    'premium' => [
        PRICE_PREMIUM,
        'payment'
    ],

    // Suscripciones mensuales
    'mant-basico' => [
        PRICE_MANT_BASICO,
        'subscription'
    ],

    'mant-pro' => [
        PRICE_MANT_PRO,
        'subscription'
    ],

    'mant-premium' => [
        PRICE_MANT_PREMIUM,
        'subscription'
    ],

]);

// ─────────────────────────────────────────────
// RETRY HELPER
// ─────────────────────────────────────────────

function stripeRetry(callable $fn, int $maxAttempts = 3): mixed
{
    $attempt = 0;

    while (true) {

        try {

            return $fn();

        } catch (\Stripe\Exception\RateLimitException $e) {

            $attempt++;

            if ($attempt >= $maxAttempts) {
                throw $e;
            }

            usleep($attempt * 1000000);

        } catch (\Stripe\Exception\ApiConnectionException $e) {

            $attempt++;

            if ($attempt >= $maxAttempts) {
                throw $e;
            }

            usleep($attempt * 500000);

        } catch (\Throwable $e) {

            throw $e;
        }
    }
}
