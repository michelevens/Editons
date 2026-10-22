<?php

namespace App\Commerce;

/**
 * The feature flag for direct sales.
 *
 * On when config('commerce.enabled') says so — which defaults to "Stripe
 * keys are configured" — and never in production on the fake gateway: that
 * gateway's test checkout completes orders without taking a payment, which
 * is what local development wants and what a live store must never do.
 */
final class Commerce
{
    public static function enabled(): bool
    {
        if (! config('commerce.enabled')) {
            return false;
        }

        return config('commerce.gateway') === 'stripe' || ! app()->isProduction();
    }

    /** Whether the local test checkout may complete orders. */
    public static function fakeCheckoutAllowed(): bool
    {
        return self::enabled() && config('commerce.gateway') === 'fake' && ! app()->isProduction();
    }
}
