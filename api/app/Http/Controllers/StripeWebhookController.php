<?php

namespace App\Http\Controllers;

use App\Commerce\Fulfilment;
use App\Models\ProcessedWebhook;
use Closure;
use ErrorException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Http\Controllers\WebhookController;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use TypeError;

/**
 * Cashier's webhook endpoint, refusing what Cashier would accept.
 *
 * Cashier checks the Stripe-Signature header only when STRIPE_WEBHOOK_SECRET
 * is set. Without it, POST /stripe/webhook processes any JSON from anyone as
 * if Stripe had sent it — and production has no secret yet. Outside local
 * development and the test suite, no secret now means no webhook: a 403,
 * until the secret is configured and Stripe can sign its events.
 *
 * Bound over Cashier's controller in AppServiceProvider, so the route, its
 * name and the signature middleware are all still Cashier's.
 *
 * It also handles the storefront's events (App\Commerce\Fulfilment):
 * a paid, expired or refunded Checkout. Each event id is claimed once
 * (ProcessedWebhook), and fulfilment is idempotent on the order's state
 * besides, so Stripe's retries and duplicate deliveries do nothing twice.
 * Register the endpoint in Stripe as https://app.ecrivain.ai/stripe/webhook
 * with these events: checkout.session.completed,
 * checkout.session.async_payment_succeeded, checkout.session.expired,
 * charge.refunded, and Cashier's subscription and invoice events.
 */
class StripeWebhookController extends WebhookController
{
    public function handleWebhook(Request $request)
    {
        if (! config('cashier.webhook.secret') && ! app()->environment('local', 'testing')) {
            return new Response('Webhook signing is not configured.', 403);
        }

        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload) || ! is_string($payload['type'] ?? null) || ! is_array($payload['data']['object'] ?? null)) {
            return new Response('Malformed webhook event.', 400);
        }

        // Cashier's handlers index straight into the payload, so an event
        // missing a field it expects ended in a 500 "Undefined array key".
        // That is the sender's mistake, not ours: a 400, and a log line,
        // rather than an error report. Anything else — the database, Stripe
        // itself — still fails loudly and is retried.
        try {
            return parent::handleWebhook($request);
        } catch (ErrorException|TypeError $e) {
            Log::warning('Stripe webhook event could not be handled.', [
                'type' => $payload['type'],
                'error' => $e->getMessage(),
            ]);

            return new Response('Malformed webhook event.', 400);
        }
    }

    // ── Storefront ──────────────────────────────────────────────────────

    protected function handleCheckoutSessionCompleted(array $payload)
    {
        return $this->once($payload, fn (array $session) => app(Fulfilment::class)->checkoutCompleted($session));
    }

    /** A delayed payment method (a bank debit) has now paid. */
    protected function handleCheckoutSessionAsyncPaymentSucceeded(array $payload)
    {
        return $this->once($payload, fn (array $session) => app(Fulfilment::class)->checkoutCompleted($session));
    }

    protected function handleCheckoutSessionExpired(array $payload)
    {
        return $this->once($payload, fn (array $session) => app(Fulfilment::class)->checkoutExpired($session));
    }

    protected function handleChargeRefunded(array $payload)
    {
        return $this->once($payload, fn (array $charge) => app(Fulfilment::class)->chargeRefunded($charge));
    }

    // ── Billing emails ──────────────────────────────────────────────────
    // Cashier fires WebhookHandled only for events it has a method for, and
    // it has none for these two. Acknowledging them here is what lets
    // App\Billing\SubscriptionEmails hear about them.

    protected function handleInvoicePaymentFailed(array $payload)
    {
        return $this->successMethod();
    }

    protected function handleCustomerSubscriptionTrialWillEnd(array $payload)
    {
        return $this->successMethod();
    }

    /**
     * Run $work on the event's object at most once per event id.
     *
     * The claim is released if the work throws, so Stripe's retry gets a
     * real second attempt rather than a "done" for work that never was.
     */
    private function once(array $payload, Closure $work): Response
    {
        $eventId = is_string($payload['id'] ?? null) ? $payload['id'] : null;

        if ($eventId && ! ProcessedWebhook::claim($eventId, 'commerce', (string) $payload['type'])) {
            return $this->successMethod();
        }

        try {
            $work($payload['data']['object']);
        } catch (Throwable $e) {
            if ($eventId) {
                ProcessedWebhook::release($eventId, 'commerce');
            }

            throw $e;
        }

        return $this->successMethod();
    }
}
