<?php

namespace App\Billing;

use App\Mail\Notify;
use App\Models\ProcessedWebhook;
use App\Models\User;
use Carbon\Carbon;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookHandled;
use Throwable;

/**
 * Subscription emails, from the Stripe events Cashier has just handled.
 *
 * A listener on Cashier's WebhookHandled rather than more webhook handlers:
 * Cashier keeps the subscription rows in step, and this only tells the
 * customer what happened. It runs after Cashier, so a malformed event that
 * Cashier refused never reaches it.
 *
 * Stripe retries a webhook until it gets a 2xx and can deliver one event
 * twice, so each event id is claimed (ProcessedWebhook, purpose
 * "billing_mail") before anything is sent: a duplicate delivery sends
 * nothing. Registered in MailServiceProvider — deliberately not in
 * app/Listeners, where event discovery would register it a second time.
 *
 * Amounts are Stripe's integer minor units; plan names come from the plan
 * catalogue (App\Billing\PlanCatalog::forStripePrice), which knows every
 * price a plan has had, so a subscriber on an older price is still named.
 */
final class SubscriptionEmails
{
    private const PURPOSE = 'billing_mail';

    private const TYPES = [
        'invoice.payment_succeeded',
        'invoice.payment_failed',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'customer.subscription.trial_will_end',
    ];

    public function handle(WebhookHandled $event): void
    {
        $payload = $event->payload;
        $type = $payload['type'] ?? null;
        $object = $payload['data']['object'] ?? null;

        if (! in_array($type, self::TYPES, true) || ! is_array($object) || ! is_string($object['customer'] ?? null)) {
            return;
        }

        $user = Cashier::findBillable($object['customer']);

        if (! $user instanceof User) {
            return;
        }

        $eventId = is_string($payload['id'] ?? null) ? $payload['id'] : null;

        if ($eventId && ! ProcessedWebhook::claim($eventId, self::PURPOSE, $type)) {
            return;
        }

        try {
            match ($type) {
                'invoice.payment_succeeded' => $this->paid($user, $object),
                'invoice.payment_failed' => $this->failed($user, $object),
                'customer.subscription.updated' => $this->updated($user, $object, $payload['data']['previous_attributes'] ?? []),
                'customer.subscription.deleted' => $this->deleted($user, $object),
                'customer.subscription.trial_will_end' => $this->trialEnding($user, $object),
            };
        } catch (Throwable $e) {
            // Let Stripe's retry try again rather than finding it claimed.
            if ($eventId) {
                ProcessedWebhook::release($eventId, self::PURPOSE);
            }

            throw $e;
        }
    }

    /** @param  array<string, mixed>  $invoice */
    private function paid(User $user, array $invoice): void
    {
        // Subscription invoices only, and only ones that took money: a
        // trial's first $0 invoice is not a receipt.
        if (! str_starts_with((string) ($invoice['billing_reason'] ?? ''), 'subscription') || (int) ($invoice['amount_paid'] ?? 0) <= 0) {
            return;
        }

        $line = $invoice['lines']['data'][0] ?? [];
        $plan = $this->plan(self::priceOfLine($line));

        Notify::subscriptionReceipt(
            $user,
            planName: $plan['name'],
            amountCents: (int) $invoice['amount_paid'],
            currency: (string) ($invoice['currency'] ?? 'usd'),
            periodStart: self::time($line['period']['start'] ?? null) ?? now(),
            periodEnd: self::time($line['period']['end'] ?? null) ?? now()->addMonth(),
            invoiceUrl: $invoice['hosted_invoice_url'] ?? null,
            invoiceNumber: $invoice['number'] ?? null,
            isFirstPayment: ($invoice['billing_reason'] ?? null) === 'subscription_create',
            monthlyCredits: $plan['credits'],
        );
    }

    /** @param  array<string, mixed>  $invoice */
    private function failed(User $user, array $invoice): void
    {
        $plan = $this->plan(self::priceOfLine($invoice['lines']['data'][0] ?? []));

        Notify::paymentFailed(
            $user,
            planName: $plan['name'],
            amountCents: (int) ($invoice['amount_due'] ?? 0),
            currency: (string) ($invoice['currency'] ?? 'usd'),
            nextAttemptAt: self::time($invoice['next_payment_attempt'] ?? null),
            updateUrl: route('billing.show'),
        );
    }

    /**
     * Two things worth an email arrive as "updated": a cancellation
     * scheduled for the end of the period, and a change of price.
     *
     * @param  array<string, mixed>  $subscription
     * @param  array<string, mixed>  $previous  the fields that changed, as they were
     */
    private function updated(User $user, array $subscription, array $previous): void
    {
        $item = $subscription['items']['data'][0] ?? [];
        $plan = $this->plan($item['price']['id'] ?? null);

        if (array_key_exists('cancel_at_period_end', $previous)
            && ! $previous['cancel_at_period_end']
            && ($subscription['cancel_at_period_end'] ?? false)) {
            Notify::subscriptionCancelled($user, $plan['name'], self::periodEnd($subscription) ?? now());

            return;
        }

        $before = $previous['items']['data'][0]['price']['id'] ?? null;

        if ($before && $before !== ($item['price']['id'] ?? null)) {
            $from = $this->plan($before);

            Notify::planChanged(
                $user,
                fromPlan: $from['name'],
                toPlan: $plan['name'],
                effectiveAt: now(),
                newPriceCents: isset($item['price']['unit_amount']) ? (int) $item['price']['unit_amount'] : null,
                currency: strtoupper((string) ($item['price']['currency'] ?? 'usd')),
                monthlyCredits: $plan['credits'],
                isUpgrade: $plan['tier'] >= $from['tier'],
            );
        }
    }

    /** @param  array<string, mixed>  $subscription */
    private function deleted(User $user, array $subscription): void
    {
        // One that was set to cancel at period end was announced when it
        // was set; this is it running out, not news.
        if ($subscription['cancel_at_period_end'] ?? false) {
            return;
        }

        $plan = $this->plan($subscription['items']['data'][0]['price']['id'] ?? null);

        Notify::subscriptionCancelled($user, $plan['name'], self::time($subscription['ended_at'] ?? null) ?? now());
    }

    /** @param  array<string, mixed>  $subscription */
    private function trialEnding(User $user, array $subscription): void
    {
        $price = $subscription['items']['data'][0]['price'] ?? [];
        $plan = $this->plan($price['id'] ?? null);

        Notify::trialEnding(
            $user,
            planName: $plan['name'],
            trialEndsAt: self::time($subscription['trial_end'] ?? null) ?? now()->addDays(3),
            priceCents: isset($price['unit_amount']) ? (int) $price['unit_amount'] : null,
            currency: strtoupper((string) ($price['currency'] ?? 'usd')),
            hasPaymentMethod: $user->hasDefaultPaymentMethod(),
        );
    }

    /**
     * The configured plan a Stripe price belongs to.
     *
     * @return array{name: string, credits: ?int, tier: int}
     */
    private function plan(?string $priceId): array
    {
        $plan = app(PlanCatalog::class)->forStripePrice($priceId)['plan'] ?? null;

        return $plan
            ? ['name' => $plan['name'], 'credits' => $plan['credits'], 'tier' => $plan['tier']]
            : ['name' => __('your plan'), 'credits' => null, 'tier' => 0];
    }

    /**
     * An invoice line's price id: `pricing.price_details.price` since
     * Stripe's 2025 API versions, `price.id` before.
     *
     * @param  array<string, mixed>  $line
     */
    private static function priceOfLine(array $line): ?string
    {
        $price = $line['pricing']['price_details']['price'] ?? $line['price']['id'] ?? $line['price'] ?? null;

        return is_string($price) ? $price : null;
    }

    /**
     * When the current period ends: on the item since Stripe's 2025 API
     * versions, on the subscription before.
     *
     * @param  array<string, mixed>  $subscription
     */
    private static function periodEnd(array $subscription): ?Carbon
    {
        return self::time($subscription['items']['data'][0]['current_period_end']
            ?? $subscription['current_period_end']
            ?? $subscription['cancel_at']
            ?? null);
    }

    private static function time(mixed $timestamp): ?Carbon
    {
        return is_numeric($timestamp) ? Carbon::createFromTimestamp((int) $timestamp) : null;
    }
}
