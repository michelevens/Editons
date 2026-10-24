<?php

namespace App\Growth;

use App\Billing\Payments;
use App\Commerce\Gateway\PaymentGateway;
use App\Models\DiscountCode;
use App\Models\DiscountRedemption;
use App\Models\Order;
use App\Models\User;
use App\Platform\AdminAudit;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Discount codes: made by the owner, used by buyers at checkout.
 *
 * Storefront: a code entered on a product page lowers the order's price
 * before the Checkout Session is created, so the session, the amount check
 * when Stripe's webhook arrives and the author's royalty all see the price
 * actually paid — the author shares the discount, as at any bookshop. The
 * redemption is recorded when the order is paid (Fulfilment), once per
 * order; limits count paid orders and those still at checkout.
 *
 * Plans: subscription checkout is not built yet, so a plan code is stored
 * and, when Stripe is configured, given a Stripe coupon for the day it is.
 * The console says so rather than implying it works today.
 *
 * Kind and value are fixed once a code exists: a code people were given
 * must not change what it means. Active, expiry, the limit and the
 * description can change, audited.
 */
final class Discounts
{
    /** Stripe will not take a card payment smaller than this. */
    public const MINIMUM_CHARGE_CENTS = 50;

    /** How long a checkout that used a code holds it before it lapses. */
    private const PENDING_HOLD_HOURS = 2;

    public function __construct(private readonly PaymentGateway $gateway) {}

    /** @param  array<string, mixed>  $data  validated */
    public function create(array $data, string $reason, User $actor): DiscountCode
    {
        $code = strtoupper(trim($data['code']));

        if (DiscountCode::whereRaw('upper(code) = ?', [$code])->exists()) {
            throw ValidationException::withMessages(['code' => 'That code already exists.']);
        }

        $values = [
            'code' => $code,
            'description' => $data['description'] ?? null,
            'kind' => $data['kind'],
            'percent_off' => $data['kind'] === 'percent' ? (int) $data['percent_off'] : null,
            'amount_off_cents' => $data['kind'] === 'amount' ? (int) $data['amount_off_cents'] : null,
            'currency' => $data['kind'] === 'amount' ? strtolower($data['currency']) : null,
            'applies_to_plans' => (bool) ($data['applies_to_plans'] ?? false),
            'applies_to_storefront' => (bool) ($data['applies_to_storefront'] ?? false),
            'plan_keys' => ! empty($data['plan_keys']) ? array_values($data['plan_keys']) : null,
            'max_redemptions' => $data['max_redemptions'] ?? null,
            'expires_at' => ! empty($data['expires_at']) ? Carbon::parse($data['expires_at'])->endOfDay() : null,
            'first_time_only' => (bool) ($data['first_time_only'] ?? false),
            'active' => true,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ];

        if (! $values['applies_to_plans'] && ! $values['applies_to_storefront']) {
            throw ValidationException::withMessages(['applies_to' => 'Choose what the code applies to: plans, the storefront, or both.']);
        }

        // A coupon for plan checkout, made before anything is written so a
        // refusal from Stripe changes nothing.
        if ($values['applies_to_plans'] && Payments::available()) {
            $values['stripe_coupon_id'] = $this->gateway->createCoupon([
                'code' => $code,
                'percent_off' => $values['percent_off'],
                'amount_off_cents' => $values['amount_off_cents'],
                'currency' => $values['currency'],
                'max_redemptions' => $values['max_redemptions'],
                'redeem_by' => $values['expires_at']?->getTimestamp(),
            ]);
        }

        return AdminAudit::transaction(function () use ($values, $reason, $actor) {
            $discount = DiscountCode::create($values);

            AdminAudit::record('discount.created', $discount, null, self::auditable($discount), $reason, $actor);

            return $discount;
        });
    }

    /** @param  array{active: bool, max_redemptions: ?int, expires_at: ?string, description: ?string}  $data */
    public function update(DiscountCode $discount, array $data, string $reason, User $actor): DiscountCode
    {
        return AdminAudit::transaction(function () use ($discount, $data, $reason, $actor) {
            $before = self::auditable($discount);
            $discount->fill([
                'active' => (bool) $data['active'],
                'max_redemptions' => $data['max_redemptions'] ?? null,
                'expires_at' => ! empty($data['expires_at']) ? Carbon::parse($data['expires_at'])->endOfDay() : null,
                'description' => $data['description'] ?? null,
                'updated_by' => $actor->id,
            ])->save();

            [$from, $to] = AdminAudit::diff($before, self::auditable($discount));
            AdminAudit::record('discount.updated', $discount, $from, $to, $reason, $actor);

            return $discount;
        });
    }

    /**
     * What a code takes off a purchase, or why it cannot be used.
     *
     * @param  'storefront'|'plan'  $context
     * @return array{discount: DiscountCode, discount_cents: int, total_cents: int}
     *
     * @throws ValidationException under `code`
     */
    public function quote(string $code, string $context, int $amountCents, string $currency, ?User $buyer = null, ?string $email = null, ?string $planKey = null): array
    {
        $refuse = fn (string $message) => throw ValidationException::withMessages(['code' => $message]);
        $discount = DiscountCode::whereRaw('upper(code) = ?', [strtoupper(trim($code))])->first();

        if (! $discount || ! $discount->active) {
            $refuse('That code is not valid.');
        }

        if ($discount->expires_at && $discount->expires_at->isPast()) {
            $refuse('That code has expired.');
        }

        if ($context === 'storefront' && ! $discount->applies_to_storefront) {
            $refuse('That code cannot be used on books.');
        }

        if ($context === 'plan' && (! $discount->applies_to_plans || ($discount->plan_keys && ! in_array($planKey, $discount->plan_keys, true)))) {
            $refuse('That code cannot be used on this plan.');
        }

        if ($discount->kind === 'amount' && $discount->currency !== strtolower($currency)) {
            $refuse('That code is for prices in '.strtoupper((string) $discount->currency).'.');
        }

        if ($discount->max_redemptions !== null && $this->used($discount) >= $discount->max_redemptions) {
            $refuse('That code has been used up.');
        }

        if ($discount->first_time_only && $this->hasBoughtBefore($context, $buyer, $email)) {
            $refuse('That code is for a first purchase only.');
        }

        $off = $discount->kind === 'percent'
            ? (int) round($amountCents * $discount->percent_off / 100)
            : min($discount->amount_off_cents, $amountCents);
        $total = $amountCents - $off;

        if ($total < self::MINIMUM_CHARGE_CENTS) {
            $refuse('With that code the price is below what a card payment can take.');
        }

        return ['discount' => $discount, 'discount_cents' => $off, 'total_cents' => $total];
    }

    /** Record the code on a paid order. Once per order, whatever repeats. */
    public function redeem(Order $order): void
    {
        if (! $order->discount_code_id || $order->discount_cents <= 0) {
            return;
        }

        DiscountRedemption::firstOrCreate(['order_id' => $order->id], [
            'discount_code_id' => $order->discount_code_id,
            'user_id' => $order->buyer_user_id,
            'email' => $order->buyer_email,
            'context' => 'storefront',
            'amount_off_cents' => $order->discount_cents,
            'currency' => $order->currency,
        ]);
    }

    /** Redemptions, plus checkouts still open that used it. */
    public function used(DiscountCode $discount): int
    {
        return DiscountRedemption::where('discount_code_id', $discount->id)->count()
            + Order::where('discount_code_id', $discount->id)
                ->where('status', 'pending')
                ->where('created_at', '>=', now()->subHours(self::PENDING_HOLD_HOURS))
                ->count();
    }

    private function hasBoughtBefore(string $context, ?User $buyer, ?string $email): bool
    {
        if ($context === 'plan') {
            return $buyer !== null && $buyer->subscriptions()->exists();
        }

        if (! $buyer && ! $email) {
            return false;
        }

        return Order::whereNotNull('paid_at')
            ->where(fn ($q) => $q
                ->when($buyer, fn ($w) => $w->orWhere('buyer_user_id', $buyer->id))
                ->when($email ?? $buyer?->email, fn ($w, $address) => $w->orWhereRaw('lower(buyer_email) = ?', [strtolower($address)])))
            ->exists();
    }

    /** @return array<string, mixed> */
    private static function auditable(DiscountCode $d): array
    {
        return [
            'code' => $d->code,
            'kind' => $d->kind,
            'percent_off' => $d->percent_off,
            'amount_off_cents' => $d->amount_off_cents,
            'currency' => $d->currency,
            'applies_to_plans' => $d->applies_to_plans,
            'applies_to_storefront' => $d->applies_to_storefront,
            'plan_keys' => $d->plan_keys,
            'max_redemptions' => $d->max_redemptions,
            'expires_at' => $d->expires_at?->toIso8601String(),
            'first_time_only' => $d->first_time_only,
            'active' => $d->active,
            'description' => $d->description,
            'stripe_coupon_id' => $d->stripe_coupon_id,
        ];
    }
}
