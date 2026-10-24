<?php

namespace App\Billing;

use App\Models\CreditTransaction;
use App\Models\Plan;
use App\Models\PlatformMetric;
use App\Models\Refund;
use App\Models\SubscriptionCancellation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The console's billing figures, from Cashier's subscriptions, the plan
 * catalogue's price history, Stripe's cancellation details and the nightly
 * platform_metrics snapshots.
 *
 * Nothing is estimated. A subscription is valued at the price it is on
 * (PlanCatalog::forStripePrice); one on a price this deployment has never
 * recorded is counted and left unvalued. While there are no paid
 * subscriptions every money figure is null and the page says so — $0 would
 * read as a measurement.
 *
 *   MRR          active and past-due subscriptions (past due is still owed;
 *                trials have not paid yet)
 *   ARR          MRR × 12
 *   new MRR      subscriptions started this calendar month, still paying
 *   churned MRR  subscriptions that ended this calendar month, at the price
 *                they were on
 *   net new      new − churned
 *   ARPA         MRR ÷ paying accounts
 */
final class BillingReport
{
    /** Cashier statuses that pay, or are owed. */
    public const PAYING = ['active', 'past_due'];

    public const STATUSES = ['trialing', 'active', 'past_due', 'unpaid', 'canceled', 'incomplete', 'incomplete_expired', 'paused'];

    public function __construct(private readonly PlanCatalog $catalog) {}

    /** @return array<string, mixed> */
    public function kpis(): array
    {
        $now = now();
        $monthStart = $now->copy()->startOfMonth();

        $paying = $this->subscriptions()
            ->whereIn('s.stripe_status', self::PAYING)
            ->where(fn ($q) => $q->whereNull('s.ends_at')->orWhere('s.ends_at', '>', $now))
            ->get();

        $valued = $this->value($paying);
        $mrr = $valued['cents'];
        $accounts = $paying->pluck('user_id')->unique()->count();

        $new = $this->value($paying->filter(fn ($row) => Carbon::parse($row->created_at)->gte($monthStart)));

        $ended = $this->value($this->subscriptions()
            ->whereNotNull('s.ends_at')
            ->whereBetween('s.ends_at', [$monthStart, $now])
            ->get());

        // The month's opening figure, when a snapshot was taken the night
        // before it began: what the platform measured then, not a rebuild.
        $opening = PlatformMetric::where('day', $monthStart->copy()->subDay()->toDateString())->first();
        $openingMrr = isset($opening?->figures['mrr']) ? (int) round($opening->figures['mrr'] * 100) : null;

        $none = $paying->isEmpty();

        return [
            'configured' => filled(config('cashier.secret')),
            'paying_subscriptions' => $paying->count(),
            'paying_accounts' => $accounts,
            'unknown_prices' => $valued['unknown'],
            'mrr_cents' => $none ? null : $mrr,
            'arr_cents' => $none ? null : $mrr * 12,
            'arpa_cents' => $none || $accounts === 0 ? null : intdiv($mrr, $accounts),
            'new_mrr_cents' => $new['cents'],
            'new_subscriptions' => $new['count'],
            'churned_mrr_cents' => $ended['cents'],
            'churned_subscriptions' => $ended['count'],
            'net_new_mrr_cents' => $new['cents'] - $ended['cents'],
            'opening_mrr_cents' => $openingMrr,
            'trialing' => DB::table('subscriptions')->where('stripe_status', 'trialing')->count(),
            'past_due' => DB::table('subscriptions')->whereIn('stripe_status', ['past_due', 'unpaid'])->count(),
            'currency' => 'usd',
            'month' => $monthStart->format('F Y'),
        ];
    }

    /**
     * MRR and paying subscriptions per day, from the snapshots only.
     *
     * @return list<array{day: string, mrr_cents: int, subscriptions: int}>
     */
    public function trend(int $days = 90): array
    {
        return PlatformMetric::where('day', '>=', now()->subDays($days - 1)->toDateString())
            ->orderBy('day')
            ->get()
            ->map(fn (PlatformMetric $m) => [
                'day' => $m->day->toDateString(),
                'mrr_cents' => (int) round(((float) ($m->figures['mrr'] ?? 0)) * 100),
                'subscriptions' => (int) ($m->figures['subscriptions'] ?? 0),
            ])
            ->all();
    }

    /**
     * Every subscription, filterable, with what it adds to MRR.
     *
     * @param  array{status?: ?string, plan?: ?string, q?: ?string}  $filters
     */
    public function list(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $query = $this->subscriptions()
            ->when($filters['status'] ?? null, fn ($q, $status) => $status === 'cancelling'
                ? $q->where('s.cancel_at_period_end', true)->whereIn('s.stripe_status', ['active', 'trialing'])
                : $q->where('s.stripe_status', $status))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('u.email', 'ilike', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('u.name', 'ilike', '%'.addcslashes($term, '%_\\').'%')))
            ->orderByDesc('s.created_at');

        if ($plan = $filters['plan'] ?? null) {
            $query->whereIn('s.stripe_price', $this->pricesOf($plan));
        }

        return $query->paginate($perPage)->through(fn ($row) => $this->row($row));
    }

    /** Trials that end within $days days. @return list<array<string, mixed>> */
    public function trialsEnding(int $days = 7): array
    {
        return $this->subscriptions()
            ->where('s.stripe_status', 'trialing')
            ->whereBetween('s.trial_ends_at', [now(), now()->addDays($days)])
            ->orderBy('s.trial_ends_at')
            ->limit(200)
            ->get()
            ->map(fn ($row) => $this->row($row) + [
                'days_left' => max(0, (int) ceil(now()->diffInHours(Carbon::parse($row->trial_ends_at)) / 24)),
                'card' => $row->pm_last_four !== null,
            ])
            ->all();
    }

    /** In dunning: past due or unpaid, oldest failure first. @return list<array<string, mixed>> */
    public function pastDue(): array
    {
        return $this->subscriptions()
            ->whereIn('s.stripe_status', ['past_due', 'unpaid'])
            ->orderByRaw('coalesce(s.past_due_since, s.updated_at)')
            ->limit(200)
            ->get()
            ->map(function ($row) {
                $since = Carbon::parse($row->past_due_since ?? $row->updated_at);

                return $this->row($row) + [
                    'past_due_since' => $since->toIso8601String(),
                    // From the first failed invoice when the webhook said so;
                    // otherwise from the last change to the row.
                    'since_known' => $row->past_due_since !== null,
                    'days_overdue' => (int) $since->diffInDays(now()),
                ];
            })
            ->all();
    }

    /** @return array{rows: list<array<string, mixed>>, breakdown: list<array<string, mixed>>, summary: array<string, int>} */
    public function cancellations(int $days = 90): array
    {
        $rows = SubscriptionCancellation::with('user:id,name,email')
            ->where('requested_at', '>=', now()->subDays($days))
            ->latest('requested_at')
            ->limit(300)
            ->get();

        $total = $rows->count();

        return [
            'rows' => $rows->map(fn (SubscriptionCancellation $c) => [
                'id' => $c->id,
                'account' => $c->user ? ['id' => $c->user->id, 'name' => $c->user->name, 'email' => $c->user->email] : null,
                'plan' => $c->plan_key ? ($this->catalog->get($c->plan_key)['name'] ?? $c->plan_key) : null,
                'mrr_cents' => $c->mrr_cents,
                'reason' => self::reasonLabel($c->stripe_reason),
                'feedback' => $c->feedback ? SubscriptionCancellation::FEEDBACK[$c->feedback] : null,
                'comment' => $c->comment,
                'requested_at' => $c->requested_at->toIso8601String(),
                'ends_at' => $c->ends_at?->toIso8601String(),
                'state' => $c->resumed_at ? 'resumed' : (($c->ends_at && $c->ends_at->isPast()) ? 'ended' : 'ending'),
            ])->all(),
            'breakdown' => $rows->groupBy(fn (SubscriptionCancellation $c) => $c->feedback ?? 'none')
                ->map(fn (Collection $group, string $key) => [
                    'key' => $key,
                    'label' => $key === 'none' ? 'No answer given' : SubscriptionCancellation::FEEDBACK[$key],
                    'count' => $group->count(),
                    'pct' => $total > 0 ? (int) round($group->count() / $total * 100) : 0,
                ])
                ->sortByDesc('count')
                ->values()
                ->all(),
            'summary' => [
                'total' => $total,
                'ended' => $rows->filter(fn ($c) => ! $c->resumed_at && $c->ends_at?->isPast())->count(),
                'ending' => $rows->filter(fn ($c) => ! $c->resumed_at && ! $c->ends_at?->isPast())->count(),
                'resumed' => $rows->whereNotNull('resumed_at')->count(),
            ],
        ];
    }

    /** Credit packs bought. @return list<array<string, mixed>> */
    public function creditPurchases(int $limit = 100): array
    {
        $refunded = Refund::whereNotNull('credit_transaction_id')->where('status', '!=', 'failed')->pluck('status', 'credit_transaction_id');

        return CreditTransaction::with('user:id,name,email')
            ->where('kind', 'purchase')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (CreditTransaction $row) => [
                'id' => $row->id,
                'account' => $row->user ? ['id' => $row->user->id, 'name' => $row->user->name, 'email' => $row->user->email] : null,
                'pack' => $row->label,
                'credits' => $row->amount,
                'price_cents' => isset(config('billing.packs')[$row->label]) ? (int) round(config("billing.packs.{$row->label}.price") * 100) : null,
                'payment' => $row->external_reference,
                'refundable' => str_starts_with((string) $row->external_reference, 'pi_') && ! isset($refunded[$row->id]),
                'refunded' => $refunded[$row->id] ?? null,
                'at' => $row->created_at->toIso8601String(),
            ])
            ->all();
    }

    /** Refunds started from the console. @return list<array<string, mixed>> */
    public function refunds(int $limit = 50): array
    {
        return Refund::with(['actor:id,name', 'order:id,number'])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (Refund $r) => [
                'id' => $r->id,
                'what' => $r->order ? 'Order '.$r->order->number : ($r->credit_transaction_id ? 'Credit pack #'.$r->credit_transaction_id : '—'),
                'amount_cents' => $r->amount_cents,
                'currency' => $r->currency,
                'status' => $r->status,
                'gateway' => $r->gateway,
                'reason' => $r->reason,
                'by' => $r->actor?->name,
                'at' => $r->created_at->toIso8601String(),
            ])
            ->all();
    }

    // ── Internals ───────────────────────────────────────────────────────

    private function subscriptions()
    {
        return DB::table('subscriptions as s')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->select([
                's.id', 's.user_id', 's.stripe_id', 's.stripe_status', 's.stripe_price', 's.quantity',
                's.trial_ends_at', 's.ends_at', 's.created_at', 's.updated_at',
                's.current_period_end', 's.cancel_at_period_end', 's.past_due_since',
                'u.name', 'u.email', 'u.pm_last_four',
            ]);
    }

    /** @return array<string, mixed> */
    private function row(object $row): array
    {
        $price = $this->catalog->forStripePrice($row->stripe_price);
        $paying = in_array($row->stripe_status, self::PAYING, true) && (! $row->ends_at || Carbon::parse($row->ends_at)->isFuture());

        return [
            'id' => $row->id,
            'account' => ['id' => $row->user_id, 'name' => $row->name, 'email' => $row->email],
            'plan' => $price['plan']['name'] ?? null,
            'plan_key' => $price['key'] ?? null,
            'price_cents' => $price['amount_cents'] ?? null,
            'status' => $row->stripe_status,
            'cancelling' => (bool) $row->cancel_at_period_end && ! $row->ends_at,
            'started_at' => Carbon::parse($row->created_at)->toIso8601String(),
            'renews_at' => ! $row->ends_at && ! $row->cancel_at_period_end && $row->current_period_end ? Carbon::parse($row->current_period_end)->toIso8601String() : null,
            'ends_at' => $row->ends_at
                ? Carbon::parse($row->ends_at)->toIso8601String()
                : ($row->cancel_at_period_end && $row->current_period_end ? Carbon::parse($row->current_period_end)->toIso8601String() : null),
            'trial_ends_at' => $row->trial_ends_at ? Carbon::parse($row->trial_ends_at)->toIso8601String() : null,
            'mrr_cents' => $paying && $price ? $price['amount_cents'] * max(1, (int) $row->quantity) : 0,
            'unknown_price' => $price === null,
        ];
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array{cents: int, count: int, unknown: int}
     */
    private function value(Collection $rows): array
    {
        $cents = 0;
        $unknown = 0;

        foreach ($rows as $row) {
            $price = $this->catalog->forStripePrice($row->stripe_price);

            if ($price === null) {
                $unknown++;

                continue;
            }

            $cents += $price['amount_cents'] * max(1, (int) $row->quantity);
        }

        return ['cents' => $cents, 'count' => $rows->count(), 'unknown' => $unknown];
    }

    /** Every Stripe price a plan has had. @return list<string> */
    private function pricesOf(string $planKey): array
    {
        $plan = Plan::where('key', $planKey)->first();
        $ids = $plan ? $plan->prices()->whereNotNull('stripe_price_id')->pluck('stripe_price_id')->all() : [];

        if ($configured = config("billing.prices.{$planKey}")) {
            $ids[] = $configured;
        }

        return array_values(array_unique($ids)) ?: ['__none__'];
    }

    public static function reasonLabel(?string $reason): ?string
    {
        return match ($reason) {
            'cancellation_requested' => 'Cancelled by the customer',
            'payment_failed' => 'Payment failed',
            'payment_disputed' => 'Payment disputed',
            null => null,
            default => str_replace('_', ' ', $reason),
        };
    }
}
