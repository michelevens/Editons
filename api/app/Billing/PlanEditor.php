<?php

namespace App\Billing;

use App\Commerce\Gateway\PaymentGateway;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\User;
use App\Platform\AdminAudit;
use Illuminate\Validation\ValidationException;

/**
 * The owner's changes to the plan catalogue.
 *
 * A price is never edited in place. A new price is a new plan_prices row
 * and, when Stripe is configured, a new Stripe price created through the
 * gateway; the old price is retired, not changed, so everybody subscribed to
 * it stays on it (Stripe keeps charging it) and is still valued at it.
 *
 * Free is the plan every account falls back to: its price stays 0 and it
 * stays public.
 *
 * Every change is audited with the fields that changed, in the transaction
 * that makes it.
 */
final class PlanEditor
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PlanCatalog $catalog,
    ) {}

    /**
     * @param  array{name: string, price_cents: int, credits: int, books: ?int, seats: ?int, formats: list<string>, tier: int, visibility: string}  $data
     * @return array{plan: Plan, stripe: ?string} stripe: 'created' | 'not_configured' | null (price unchanged)
     */
    public function update(Plan $plan, array $data, string $reason, User $actor): array
    {
        if ($plan->key === 'free') {
            if ((int) $data['price_cents'] !== 0) {
                throw ValidationException::withMessages(['price_cents' => 'Free is what every account falls back to; its price stays 0.']);
            }

            if ($data['visibility'] !== 'public') {
                throw ValidationException::withMessages(['visibility' => 'Free is the plan new accounts start on; it stays public.']);
            }
        }

        $priceChanged = (int) $data['price_cents'] !== $plan->price_cents;
        [$stripe, $priceId] = $priceChanged ? $this->newStripePrice($plan->key, $data['name'], (int) $data['price_cents'], $plan->currency) : [null, null];

        $plan = AdminAudit::transaction(function () use ($plan, $data, $reason, $actor, $priceChanged, $priceId) {
            $before = self::auditable($plan);

            $plan->fill([
                'name' => $data['name'],
                'price_cents' => (int) $data['price_cents'],
                'credits' => (int) $data['credits'],
                'books' => $data['books'],
                'seats' => $data['seats'],
                'formats' => array_values($data['formats']),
                'tier' => (int) $data['tier'],
                'visibility' => $data['visibility'],
                'updated_by' => $actor->id,
            ]);

            if ($priceChanged) {
                PlanPrice::where('plan_id', $plan->id)->whereNull('retired_at')->update(['retired_at' => now()]);
                PlanPrice::create([
                    'plan_id' => $plan->id,
                    'amount_cents' => (int) $data['price_cents'],
                    'currency' => $plan->currency,
                    'stripe_price_id' => $priceId,
                    'active_from' => now(),
                    'created_by' => $actor->id,
                ]);
                $plan->stripe_price_id = $priceId;
            }

            $plan->save();

            [$from, $to] = AdminAudit::diff($before, self::auditable($plan));

            if ($from !== [] || $to !== []) {
                AdminAudit::record('plan.update', $plan, $from, $to, $reason, $actor);
            }

            return $plan;
        });

        $this->catalog->forget();

        return ['plan' => $plan, 'stripe' => $stripe];
    }

    /**
     * A new plan. Its key is permanent: accounts store it.
     *
     * @param  array{key: string, name: string, price_cents: int, credits: int, books: ?int, seats: ?int, formats: list<string>, tier: int, visibility: string}  $data
     * @return array{plan: Plan, stripe: ?string}
     */
    public function create(array $data, string $reason, User $actor): array
    {
        [$stripe, $priceId] = (int) $data['price_cents'] > 0
            ? $this->newStripePrice($data['key'], $data['name'], (int) $data['price_cents'], 'usd')
            : [null, null];

        $plan = AdminAudit::transaction(function () use ($data, $reason, $actor, $priceId) {
            $plan = Plan::create([
                'key' => $data['key'],
                'name' => $data['name'],
                'price_cents' => (int) $data['price_cents'],
                'currency' => 'usd',
                'credits' => (int) $data['credits'],
                'books' => $data['books'],
                'seats' => $data['seats'],
                'formats' => array_values($data['formats']),
                'tier' => (int) $data['tier'],
                'visibility' => $data['visibility'],
                'sort' => (int) Plan::max('sort') + 1,
                'stripe_price_id' => $priceId,
                'updated_by' => $actor->id,
            ]);

            PlanPrice::create([
                'plan_id' => $plan->id,
                'amount_cents' => $plan->price_cents,
                'currency' => $plan->currency,
                'stripe_price_id' => $priceId,
                'active_from' => now(),
                'created_by' => $actor->id,
            ]);

            AdminAudit::record('plan.create', $plan, null, self::auditable($plan), $reason, $actor);

            return $plan;
        });

        $this->catalog->forget();

        return ['plan' => $plan, 'stripe' => $stripe];
    }

    /**
     * A new Stripe price, before anything is written: if Stripe refuses,
     * nothing changes. A price Stripe made for a change whose database write
     * then failed is harmless — nothing points at it.
     *
     * @return array{0: string, 1: ?string} [stripe outcome, price id]
     */
    private function newStripePrice(string $key, string $name, int $cents, string $currency): array
    {
        if ($cents === 0) {
            return ['free', null];
        }

        if (! Payments::available()) {
            return ['not_configured', null];
        }

        $id = $this->gateway->createRecurringPrice([
            'plan_key' => $key,
            'name' => $name,
            'amount_cents' => $cents,
            'currency' => $currency,
            'replaces' => $this->catalog->stripePriceId($key),
        ]);

        return ['created', $id];
    }

    /** @return array<string, mixed> */
    private static function auditable(Plan $plan): array
    {
        return [
            'name' => $plan->name,
            'price_cents' => $plan->price_cents,
            'credits' => $plan->credits,
            'books' => $plan->books,
            'seats' => $plan->seats,
            'formats' => array_values($plan->formats ?? []),
            'tier' => $plan->tier,
            'visibility' => $plan->visibility,
            'stripe_price_id' => $plan->stripe_price_id,
        ];
    }
}
