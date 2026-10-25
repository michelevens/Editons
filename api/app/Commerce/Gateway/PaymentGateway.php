<?php

namespace App\Commerce\Gateway;

/**
 * Everything Écrivain asks of a payment provider.
 *
 * The storefront creates a hosted Checkout Session and sends the buyer
 * there. Everything that comes back — paid, refunded, expired — arrives as a
 * signed webhook event, which is the only thing trusted to say money moved
 * (App\Http\Controllers\StripeWebhookController).
 *
 * The console adds three calls (ADR — console business): a refund, a new
 * recurring price when the owner changes a plan's price (a price is never
 * edited in place), and a coupon for a discount code that applies to plans.
 *
 * StripeGateway is the real one. FakeGateway is what tests and local
 * development use: it never makes a network call, and it is refused in
 * production (App\Commerce\Commerce::enabled, App\Billing\Payments).
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
     *     shipping?: array{countries: list<string>, amount_cents: int, label: string}|null,
     * }  $params
     */
    public function createCheckoutSession(array $params): CheckoutSession;

    /**
     * Refund (part of) a payment.
     *
     * @param  array{payment_intent: string, amount_cents: int, idempotency_key: string, metadata: array<string, string>}  $params
     * @return array{id: string, status: string} the provider's refund id and status (succeeded | pending | failed)
     */
    public function refund(array $params): array;

    /**
     * Create a monthly recurring price. `replaces` is the price it
     * supersedes, whose product it joins; that price is left as it is.
     *
     * @param  array{plan_key: string, name: string, amount_cents: int, currency: string, replaces: ?string}  $params
     * @return string the price id
     */
    public function createRecurringPrice(array $params): string;

    /**
     * Create a coupon (for subscriptions).
     *
     * @param  array{code: string, percent_off: ?int, amount_off_cents: ?int, currency: ?string, max_redemptions: ?int, redeem_by: ?int}  $params
     * @return string the coupon id
     */
    public function createCoupon(array $params): string;

    /** 'stripe' or 'fake': which one is answering. */
    public function name(): string;
}
