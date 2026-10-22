<?php

namespace App\Http\Controllers;

use ErrorException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Http\Controllers\WebhookController;
use Symfony\Component\HttpFoundation\Response;
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
}
