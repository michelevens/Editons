<?php

namespace App\Billing;

use App\Models\SubscriptionCancellation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookHandled;

/**
 * What Cashier does not keep about a subscription, from the same webhooks.
 *
 * Cashier's subscriptions row has the status, price and end date. The
 * console also needs when the current period ends (the renewal date), that
 * a cancellation is scheduled for it, when a payment first failed (how long
 * an account has been in dunning) and why somebody cancelled — Stripe's
 * cancellation_details, which its billing portal and cancellation flow fill
 * in from the customer's answer.
 *
 * Every write here sets state from the event rather than adding to it, so a
 * repeated or out-of-order delivery leaves the same rows. Listens after
 * Cashier (WebhookHandled), so the subscription row already exists.
 */
final class SubscriptionSync
{
    public function handle(WebhookHandled $event): void
    {
        $payload = $event->payload;
        $type = $payload['type'] ?? null;
        $object = $payload['data']['object'] ?? null;

        if (! is_array($object)) {
            return;
        }

        match ($type) {
            'customer.subscription.created', 'customer.subscription.updated' => $this->subscription($object, $payload['data']['previous_attributes'] ?? []),
            'customer.subscription.deleted' => $this->subscription($object, [], deleted: true),
            'invoice.payment_failed' => $this->invoice($object, failed: true),
            'invoice.payment_succeeded', 'invoice.paid' => $this->invoice($object, failed: false),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $subscription
     * @param  array<string, mixed>  $previous
     */
    private function subscription(array $subscription, array $previous, bool $deleted = false): void
    {
        $stripeId = $subscription['id'] ?? null;

        if (! is_string($stripeId)) {
            return;
        }

        $item = $subscription['items']['data'][0] ?? [];
        $periodEnd = self::time($item['current_period_end'] ?? $subscription['current_period_end'] ?? null);
        $cancelAtEnd = (bool) ($subscription['cancel_at_period_end'] ?? false);

        DB::table('subscriptions')->where('stripe_id', $stripeId)->update(array_filter([
            'current_period_end' => $periodEnd,
            'cancel_at_period_end' => $cancelAtEnd,
        ], fn ($value) => $value !== null));

        $details = is_array($subscription['cancellation_details'] ?? null) ? $subscription['cancellation_details'] : [];
        $cancelled = $deleted || $cancelAtEnd || isset($subscription['canceled_at']) || isset($subscription['cancel_at']);

        if ($cancelled) {
            $this->recordCancellation($subscription, $item, $details, $periodEnd, $deleted);

            return;
        }

        // A scheduled cancellation that was undone: the customer stayed.
        if (($previous['cancel_at_period_end'] ?? false) === true) {
            SubscriptionCancellation::where('stripe_subscription_id', $stripeId)
                ->whereNull('resumed_at')
                ->update(['resumed_at' => now()]);
        }
    }

    /**
     * @param  array<string, mixed>  $subscription
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $details
     */
    private function recordCancellation(array $subscription, array $item, array $details, ?Carbon $periodEnd, bool $deleted): void
    {
        $user = is_string($subscription['customer'] ?? null) ? Cashier::findBillable($subscription['customer']) : null;
        $price = app(PlanCatalog::class)->forStripePrice($item['price']['id'] ?? null);
        $quantity = max(1, (int) ($item['quantity'] ?? $subscription['quantity'] ?? 1));
        $feedback = $details['feedback'] ?? null;

        SubscriptionCancellation::updateOrCreate(
            ['stripe_subscription_id' => $subscription['id']],
            [
                'user_id' => $user instanceof User ? $user->id : null,
                'plan_key' => $price['key'] ?? null,
                'mrr_cents' => $price ? $price['amount_cents'] * $quantity : null,
                'stripe_reason' => is_string($details['reason'] ?? null) ? mb_substr($details['reason'], 0, 40) : null,
                'feedback' => is_string($feedback) && array_key_exists($feedback, SubscriptionCancellation::FEEDBACK) ? $feedback : null,
                'comment' => is_string($details['comment'] ?? null) ? mb_substr($details['comment'], 0, 2000) : null,
                'requested_at' => self::time($subscription['canceled_at'] ?? null) ?? now(),
                'ends_at' => self::time($subscription['ended_at'] ?? null)
                    ?? self::time($subscription['cancel_at'] ?? null)
                    ?? ($deleted ? now() : $periodEnd),
                'resumed_at' => null,
            ],
        );
    }

    /** @param  array<string, mixed>  $invoice */
    private function invoice(array $invoice, bool $failed): void
    {
        // Where the subscription id lives moved in Stripe's 2025 API versions.
        $subscription = $invoice['subscription']
            ?? $invoice['parent']['subscription_details']['subscription']
            ?? null;

        if (! is_string($subscription)) {
            return;
        }

        $query = DB::table('subscriptions')->where('stripe_id', $subscription);

        $failed
            ? $query->whereNull('past_due_since')->update(['past_due_since' => now()])
            : $query->update(['past_due_since' => null]);
    }

    private static function time(mixed $timestamp): ?Carbon
    {
        return is_numeric($timestamp) ? Carbon::createFromTimestamp((int) $timestamp) : null;
    }
}
