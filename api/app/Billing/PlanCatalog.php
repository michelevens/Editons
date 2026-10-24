<?php

namespace App\Billing;

use App\Models\Plan;
use App\Models\PlanPrice;
use Illuminate\Support\Facades\Schema;

/**
 * The plans, from the `plans` table, with config/billing.php as the fallback.
 *
 * The table is seeded from the config by its migration, so the day this
 * ships every limit is what it was. After that the owner edits it from the
 * console (App\Billing\PlanEditor). The config is read only when the table
 * is missing (a migration not yet run) or empty, or for a key the table does
 * not have — never mixed with it field by field.
 *
 * Each plan comes back in the shape the config always had (name, price,
 * credits, books, seats, formats, tier), plus key, price_cents, currency,
 * visibility and stripe_price_id, so every caller of User::planLimits()
 * reads what it did.
 *
 * Bound scoped: one load per request or queued job, so a long-running
 * worker does not keep an old catalogue.
 */
final class PlanCatalog
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $plans = null;

    private static bool $tableKnown = false;

    /** @return array<string, array<string, mixed>> key => plan, in display order */
    public function all(): array
    {
        return $this->plans ??= $this->load();
    }

    /** @return array<string, mixed>|null */
    public function get(string $key): ?array
    {
        return $this->all()[$key] ?? self::fromConfig($key);
    }

    /** The plans offered to authors: public ones. @return array<string, array<string, mixed>> */
    public function offered(): array
    {
        return array_filter($this->all(), fn (array $plan) => ($plan['visibility'] ?? 'public') === 'public');
    }

    /** Forget what was loaded, after a change. */
    public function forget(): void
    {
        $this->plans = null;
    }

    /**
     * The Stripe price new subscribers to this plan are charged.
     *
     * The catalogue's own price id when it has one. Otherwise the
     * configured STRIPE_PRICE_* — but only while the catalogue still charges
     * what the config says: a price changed in the console without Stripe
     * must not send a checkout to the old Stripe price.
     */
    public function stripePriceId(string $key): ?string
    {
        $plan = $this->get($key);

        if (! $plan) {
            return null;
        }

        if (! empty($plan['stripe_price_id'])) {
            return $plan['stripe_price_id'];
        }

        $configured = config("billing.prices.{$key}") ?: null;
        $configCents = (int) round(((float) config("billing.plans.{$key}.price", -1)) * 100);

        return $configured && $configCents === (int) $plan['price_cents'] ? $configured : null;
    }

    /**
     * The plan a Stripe price belongs to, and what that price charges a
     * month — the price the subscriber is actually on, which after a price
     * change is not the plan's current one.
     *
     * @return array{key: string, plan: array<string, mixed>, amount_cents: int, currency: string}|null
     */
    public function forStripePrice(?string $priceId): ?array
    {
        if (! $priceId) {
            return null;
        }

        if (self::tableExists()) {
            $price = PlanPrice::with('plan')->where('stripe_price_id', $priceId)->latest('id')->first();

            if ($price?->plan) {
                return [
                    'key' => $price->plan->key,
                    'plan' => $this->get($price->plan->key) ?? $price->plan->toLimits(),
                    'amount_cents' => $price->amount_cents,
                    'currency' => $price->currency,
                ];
            }
        }

        // A configured STRIPE_PRICE_* the table has never recorded (set
        // after the migration ran): the plan it is configured for, valued
        // at what the config said that price was.
        $key = array_search($priceId, array_filter((array) config('billing.prices', [])), true);

        if ($key === false || ! ($plan = $this->get($key))) {
            return null;
        }

        return [
            'key' => $key,
            'plan' => $plan,
            'amount_cents' => (int) round(((float) config("billing.plans.{$key}.price", $plan['price'])) * 100),
            'currency' => 'usd',
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function load(): array
    {
        if (self::tableExists()) {
            $rows = Plan::orderBy('sort')->orderBy('id')->get();

            if ($rows->isNotEmpty()) {
                return $rows->mapWithKeys(fn (Plan $plan) => [$plan->key => $plan->toLimits()])->all();
            }
        }

        $plans = [];

        foreach (array_keys((array) config('billing.plans')) as $key) {
            $plans[$key] = self::fromConfig($key);
        }

        return $plans;
    }

    /** @return array<string, mixed>|null */
    public static function fromConfig(string $key): ?array
    {
        $plan = config("billing.plans.{$key}");

        if (! is_array($plan)) {
            return null;
        }

        return $plan + [
            'key' => $key,
            'price_cents' => (int) round(((float) ($plan['price'] ?? 0)) * 100),
            'currency' => 'usd',
            'visibility' => 'public',
            'stripe_price_id' => config("billing.prices.{$key}") ?: null,
        ];
    }

    private static function tableExists(): bool
    {
        // Remembered once true: a table does not disappear under a running
        // process, but one being migrated can appear.
        return self::$tableKnown = self::$tableKnown || Schema::hasTable('plans');
    }
}
