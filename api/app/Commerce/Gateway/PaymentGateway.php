<?php

namespace App\Commerce\Gateway;

/**
 * Everything the storefront asks of a payment provider.
 *
 * One method, on purpose: the storefront creates a hosted Checkout Session
 * and sends the buyer there. Everything that comes back — paid, refunded,
 * expired — arrives as a signed webhook event, which is the only thing
 * trusted to say money moved (App\Http\Controllers\StripeWebhookController).
 *
 * StripeGateway is the real one. FakeGateway is what tests and local
 * development use: it never makes a network call, and it is refused in
 * production (App\Commerce\Commerce::enabled).
 */
interface PaymentGateway
{
    /**
     * Create a one-off payment Checkout Session.
     *
     * @param  array{
     *     order_id: int,
     *     order_number: string,
     *     currency: string,
     *     line_items: list<array{name: string, description: ?string, amount_cents: int, quantity: int}>,
     *     customer_email: ?string,
     *     success_url: string,
     *     cancel_url: string,
     * }  $params
     */
    public function createCheckoutSession(array $params): CheckoutSession;

    /** 'stripe' or 'fake': which one is answering. */
    public function name(): string;
}
