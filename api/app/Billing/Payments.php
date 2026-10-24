<?php

namespace App\Billing;

use App\Commerce\Gateway\PaymentGateway;

/**
 * Whether the console may move money or create things in Stripe.
 *
 * Through the one gateway interface (App\Commerce\Gateway\PaymentGateway):
 * Stripe when STRIPE_SECRET is set, the fake gateway in tests and local
 * development, and nothing in production without Stripe — the fake gateway
 * there would "refund" money that never moved, which is the one thing a
 * refund button must never do.
 */
final class Payments
{
    public const NOT_CONFIGURED = 'Stripe is not configured on this deployment (STRIPE_SECRET is not set), so nothing can be done in Stripe from here.';

    /** Null when the gateway can be used; otherwise the sentence saying why not. */
    public static function refusal(): ?string
    {
        if (config('commerce.gateway') === 'stripe') {
            return filled(config('cashier.secret')) ? null : self::NOT_CONFIGURED;
        }

        return app()->isProduction() ? self::NOT_CONFIGURED : null;
    }

    public static function available(): bool
    {
        return self::refusal() === null;
    }

    /** 'stripe' | 'fake' | null when unavailable. */
    public static function gatewayName(): ?string
    {
        return self::available() ? app(PaymentGateway::class)->name() : null;
    }
}
