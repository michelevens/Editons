<?php

namespace App\Platform;

use App\Billing\PlanCatalog;
use App\Commerce\Commerce;
use App\Models\Plan;
use App\Models\PlatformMetric;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The platform's figures, read from the tables that already hold them.
 *
 * Nothing here is estimated. MRR is the plan price of each active Stripe
 * subscription; while there are none (Stripe is not configured, or nobody has
 * subscribed) the dashboard says so rather than showing $0 as if it were a
 * measurement. Trends come only from platform_metrics, which starts the day
 * this ships: there is no back-filled history (after MemberMD's lesson that a
 * churn chart needs snapshots, not a reconstruction).
 */
final class PlatformMetrics
{
    /** @return array<string, mixed> */
    public function live(): array
    {
        $now = now();

        $exports = DB::table('book_exports')
            ->select('status', DB::raw('count(*) as n'))
            ->groupBy('status')
            ->pluck('n', 'status')
            ->map(fn ($n) => (int) $n)
            ->all();

        return [
            'accounts_total' => User::count(),
            'accounts_new_7d' => User::where('created_at', '>=', $now->copy()->subDays(7))->count(),
            'accounts_new_30d' => User::where('created_at', '>=', $now->copy()->subDays(30))->count(),
            'active_writers_7d' => $this->activeWriters($now->copy()->subDays(7)),
            'active_writers_30d' => $this->activeWriters($now->copy()->subDays(30)),
            'books' => (int) DB::table('books')->where('status', '!=', 'archived')->count(),
            'words' => (int) DB::table('books')->where('status', '!=', 'archived')->sum('word_count'),
            'exports' => [
                'ready' => $exports['ready'] ?? 0,
                'generating' => $exports['generating'] ?? 0,
                'failed' => $exports['failed'] ?? 0,
                'expired' => $exports['expired'] ?? 0,
            ],
            'ai_reviews' => (int) DB::table('editorial_reviews')->count(),
            'ai_reviews_30d' => (int) DB::table('editorial_reviews')->where('created_at', '>=', $now->copy()->subDays(30))->count(),
            // Spends less what was refunded for failed work.
            'credits_spent' => (int) -DB::table('credit_transactions')->whereIn('kind', ['spend', 'refund'])->sum('amount'),
            'commerce' => $this->commerce(),
            'mrr' => $this->mrr(),
        ];
    }

    /**
     * Distinct accounts that saved a version since $since. Saving is the
     * signal: opening the editor is not writing.
     */
    public function activeWriters(Carbon $since): int
    {
        return (int) DB::table('manuscript_versions')
            ->where('created_at', '>=', $since)
            ->whereNotNull('user_id')
            ->whereNull('impersonator_id')
            ->distinct()
            ->count('user_id');
    }

    /**
     * Monthly recurring revenue from active subscriptions, each valued at
     * the price it is actually on (App\Billing\PlanCatalog::forStripePrice:
     * every price a plan has had, so a price change does not revalue the
     * people still paying the old one).
     *
     * `amount` is whole dollars, as the dashboard has always read it;
     * `amount_cents` is exact.
     *
     * @return array{configured: bool, subscriptions: int, amount: int|float, amount_cents: int, currency: string, unknown_prices: int}
     */
    public function mrr(): array
    {
        $catalog = app(PlanCatalog::class);

        $rows = DB::table('subscriptions')
            ->whereIn('stripe_status', ['active', 'past_due'])
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->get(['stripe_price', 'quantity']);

        $cents = 0;
        $unknown = 0;

        foreach ($rows as $row) {
            $price = $catalog->forStripePrice($row->stripe_price);

            if ($price === null) {
                // A price this deployment does not know: counted, not valued,
                // rather than guessed at.
                $unknown++;

                continue;
            }

            $cents += $price['amount_cents'] * max(1, (int) $row->quantity);
        }

        return [
            'configured' => filled(config('cashier.secret')),
            'subscriptions' => $rows->count(),
            'amount' => Plan::dollars($cents),
            'amount_cents' => $cents,
            'currency' => 'usd',
            'unknown_prices' => $unknown,
        ];
    }

    /** Storefront orders and gross, per currency; null while direct sales are off and nothing was ever sold. */
    public function commerce(): ?array
    {
        $paid = DB::table('orders')
            ->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
            ->select('currency', DB::raw('count(*) as orders'), DB::raw('sum(total_cents) as gross'), DB::raw('sum(refunded_cents) as refunded'))
            ->groupBy('currency')
            ->get();

        if (! Commerce::enabled() && $paid->isEmpty()) {
            return null;
        }

        return [
            'enabled' => Commerce::enabled(),
            'orders' => (int) $paid->sum('orders'),
            'by_currency' => $paid->map(fn ($row) => [
                'currency' => $row->currency,
                'orders' => (int) $row->orders,
                'gross_cents' => (int) $row->gross,
                'refunded_cents' => (int) $row->refunded,
            ])->values()->all(),
        ];
    }

    /**
     * Sign-ups per day for the last $days days, zero-filled.
     *
     * From users.created_at, which is a fact about every account, so this
     * one chart can reach back before the snapshots began.
     *
     * @return list<array{day: string, count: int}>
     */
    public function signups(int $days = 30): array
    {
        $from = now()->subDays($days - 1)->startOfDay();

        $counts = DB::table('users')
            ->where('created_at', '>=', $from)
            ->select(DB::raw('DATE(created_at) as day'), DB::raw('count(*) as n'))
            ->groupBy('day')
            ->pluck('n', 'day')
            ->all();

        $series = [];

        for ($i = 0; $i < $days; $i++) {
            $day = $from->copy()->addDays($i)->toDateString();
            $series[] = ['day' => $day, 'count' => (int) ($counts[$day] ?? 0)];
        }

        return $series;
    }

    /**
     * Write today's snapshot. Re-running on the same day replaces it, so a
     * retried schedule never makes two points for one day.
     */
    public function snapshot(?Carbon $day = null): PlatformMetric
    {
        $day ??= now();
        $live = $this->live();

        $figures = [
            'accounts_total' => $live['accounts_total'],
            'accounts_new_7d' => $live['accounts_new_7d'],
            'active_writers_7d' => $live['active_writers_7d'],
            'active_writers_30d' => $live['active_writers_30d'],
            'books' => $live['books'],
            'words' => $live['words'],
            'exports_ready' => $live['exports']['ready'],
            'exports_failed' => $live['exports']['failed'],
            'ai_reviews' => $live['ai_reviews'],
            'credits_spent' => $live['credits_spent'],
            'orders' => $live['commerce']['orders'] ?? 0,
            'mrr' => $live['mrr']['amount'],
            'subscriptions' => $live['mrr']['subscriptions'],
        ];

        return PlatformMetric::updateOrCreate(
            ['day' => $day->toDateString()],
            ['figures' => $figures, 'captured_at' => now()],
        );
    }

    /** The snapshots, oldest first. @return list<array<string, mixed>> */
    public function trend(int $days = 30): array
    {
        return PlatformMetric::where('day', '>=', now()->subDays($days - 1)->toDateString())
            ->orderBy('day')
            ->get()
            ->map(fn (PlatformMetric $m) => ['day' => $m->day->toDateString(), ...$m->figures])
            ->all();
    }
}
