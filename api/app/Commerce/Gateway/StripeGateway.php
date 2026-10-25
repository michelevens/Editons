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

        // A paperback: the address and phone the printer needs, and one
        // fixed shipping rate.
        if (! empty($params['shipping'])) {
            $payload['shipping_address_collection'] = ['allowed_countries' => array_values($params['shipping']['countries'])];
            $payload['phone_number_collection'] = ['enabled' => true];
            $payload['shipping_options'] = [[
                'shipping_rate_data' => [
                    'type' => 'fixed_amount',
                    'display_name' => $params['shipping']['label'],
                    'fixed_amount' => ['amount' => $params['shipping']['amount_cents'], 'currency' => $params['currency']],
                ],
            ]];
        }

        if ($params['customer_email']) {
            $payload['customer_email'] = $params['customer_email'];
        }

        $session = Cashier::stripe()->checkout->sessions->create($payload, [
            // A double-click must not open two sessions for one order.
            'idempotency_key' => 'order-'.$params['order_id'],
        ]);

        return new CheckoutSession($session->id, $session->url);
    }

    public function refund(array $params): array
    {
        $refund = Cashier::stripe()->refunds->create([
            'payment_intent' => $params['payment_intent'],
            'amount' => $params['amount_cents'],
            'metadata' => $params['metadata'],
        ], [
            // A double-submitted form must not refund twice.
            'idempotency_key' => $params['idempotency_key'],
        ]);

        return ['id' => $refund->id, 'status' => (string) $refund->status];
    }

    /**
     * A new price on the product of the one it replaces, so Stripe's
     * dashboard keeps one product per plan. The old price is left alone:
     * the subscribers on it stay on it.
     */
    public function createRecurringPrice(array $params): string
    {
        $stripe = Cashier::stripe();
        $payload = [
            'unit_amount' => $params['amount_cents'],
            'currency' => $params['currency'],
            'recurring' => ['interval' => 'month'],
            'metadata' => ['plan' => $params['plan_key']],
            'nickname' => $params['name'].' (monthly)',
        ];

        $product = $params['replaces'] ? $stripe->prices->retrieve($params['replaces'])->product : null;

        if (is_string($product) && $product !== '') {
            $payload['product'] = $product;
        } else {
            $payload['product_data'] = ['name' => 'Écrivain '.$params['name']];
        }

        return $stripe->prices->create($payload)->id;
    }

    public function createCoupon(array $params): string
    {
        $payload = array_filter([
            'name' => $params['code'],
            'percent_off' => $params['percent_off'],
            'amount_off' => $params['amount_off_cents'],
            'currency' => $params['amount_off_cents'] ? $params['currency'] : null,
            'max_redemptions' => $params['max_redemptions'],
            'redeem_by' => $params['redeem_by'],
            'duration' => 'once',
        ], fn ($value) => $value !== null);

        return Cashier::stripe()->coupons->create($payload)->id;
    }

    public function name(): string
    {
        return 'stripe';
    }
}
