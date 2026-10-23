<?php

namespace App\Commerce\Gateway;

use Laravel\Cashier\Cashier;

/**
 * Stripe Checkout, through Cashier's configured client.
 *
 * Guest checkout: buyers do not need an account, so no Stripe customer is
 * created for them and Stripe collects the email. The order id travels in
 * three places — the session's metadata and client_reference_id, and the
 * payment intent's metadata — so checkout.session.completed and, later,
 * charge.refunded can each find the order on their own.
 *
 * Prices are sent inline (price_data) rather than as Stripe Price objects:
 * the price is the product row's, and a copy in Stripe would drift.
 */
class StripeGateway implements PaymentGateway
{
    public function createCheckoutSession(array $params): CheckoutSession
    {
        $metadata = [
            'order_id' => (string) $params['order_id'],
            'order_number' => $params['order_number'],
        ];

        $payload = [
            'mode' => 'payment',
            'client_reference_id' => $params['order_number'],
            'metadata' => $metadata,
            'payment_intent_data' => ['metadata' => $metadata],
            'line_items' => array_map(fn (array $line) => [
                'quantity' => $line['quantity'],
                'price_data' => [
                    'currency' => $params['currency'],
                    'unit_amount' => $line['amount_cents'],
                    'product_data' => array_filter([
                        'name' => $line['name'],
                        'description' => $line['description'] ?: null,
                    ]),
                ],
            ], $params['line_items']),
            'billing_address_collection' => 'auto',
            'success_url' => $params['success_url'],
            'cancel_url' => $params['cancel_url'],
            // An abandoned session holds nothing, but a pending order is
            // tidier closed sooner: Stripe's minimum is 30 minutes.
            'expires_at' => now()->addHour()->getTimestamp(),
        ];

        if ($params['customer_email']) {
            $payload['customer_email'] = $params['customer_email'];
        }

        $session = Cashier::stripe()->checkout->sessions->create($payload, [
            // A double-click must not open two sessions for one order.
            'idempotency_key' => 'order-'.$params['order_id'],
        ]);

        return new CheckoutSession($session->id, $session->url);
    }

    public function name(): string
    {
        return 'stripe';
    }
}
