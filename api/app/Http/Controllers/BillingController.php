<?php

namespace App\Http\Controllers;

use App\Billing\CreditLedger;
use App\Billing\Entitlements;
use App\Billing\MonthlyAllowance;
use App\Billing\PlanCatalog;
use App\Editorial\EditorialAgent;
use App\Models\CreditTransaction;
use App\Models\EditorialReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plans & Billing.
 *
 * Everything shown here is read from the ledger and the plan table. The
 * prototype fills its usage chart with generated history and labels it
 * "Sample history"; this shows the real rows and an empty state when there
 * are none, because a number on a billing screen that is not the truth is
 * worse than no number.
 *
 * Changing a plan is not implemented here. That is a Stripe Checkout
 * round-trip, the keys are not set, and a button that appeared to upgrade an
 * account without charging for it would be the worst kind of placeholder. The
 * screen says what will happen instead.
 */
class BillingController extends Controller
{
    public function __construct(
        private readonly CreditLedger $ledger,
        private readonly Entitlements $entitlements,
        private readonly MonthlyAllowance $allowance,
    ) {}

    public function show(Request $request): Response
    {
        return Inertia::render('Billing', $this->payload($request));
    }

    /** The same data, for the credit strip and the account menu. */
    public function summary(Request $request): JsonResponse
    {
        return response()->json($this->entitlements->summaryFor($request->user()));
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $user = $request->user();

        // So the screen never shows a balance of zero to somebody whose
        // allowance simply has not been granted yet.
        $this->allowance->ensureGranted($user);

        $limits = $user->planLimits();
        $balance = $this->ledger->balanceFor($user);
        $monthly = (int) ($limits['credits'] ?? 0);

        return [
            'current' => [
                'plan' => $user->plan,
                'name' => $limits['name'],
                'price' => $limits['price'],
                'tier' => $limits['tier'],
                'credits' => $balance,
                'monthly_credits' => $monthly,
                // Spent this calendar month, from the ledger. Not
                // allowance-minus-balance: carried-over or purchased credits
                // would make that subtraction a lie.
                'used_this_month' => $this->usedThisMonth($user->id),
                'books' => $user->activeBookCount(),
                'book_limit' => $limits['books'],
                'seat_limit' => $user->seatLimit(),
                'renews_at' => $user->credits_granted_at
                    ? $user->credits_granted_at->addMonth()->toFormattedDateString()
                    : null,
                // Cashier's columns. Null when no card is on file, which is
                // every free account.
                'card' => $user->pm_last_four
                    ? ucfirst((string) $user->pm_type).' ending '.$user->pm_last_four
                    : null,
            ],
            'plans' => collect(app(PlanCatalog::class)->all())
                // Offered plans, and the one this account is on even when it
                // is no longer offered (hidden or legacy).
                ->filter(fn (array $plan, string $key) => ($plan['visibility'] ?? 'public') === 'public' || $key === $user->plan)
                ->map(fn (array $plan, string $key) => [
                    'key' => $key,
                    'name' => $plan['name'],
                    'price' => $plan['price'],
                    'credits' => $plan['credits'],
                    'books' => $plan['books'],
                    'seats' => $plan['seats'],
                    'formats' => $plan['formats'],
                    'tier' => $plan['tier'],
                    'current' => $key === $user->plan,
                ])
                ->values()
                ->all(),
            'costs' => $this->costRows($user),
            'packs' => collect(config('billing.packs'))
                ->map(fn (array $pack, string $key) => [
                    'key' => $key,
                    'credits' => $pack['credits'],
                    'price' => $pack['price'],
                ])
                ->values()
                ->all(),
            'activity' => $this->activity($user->id),
            'daily' => $this->dailyUse($user->id),
            // Said plainly rather than shown as a working button: the keys
            // are not configured, and an upgrade that does not charge is
            // worse than one that is honestly unavailable.
            'checkout_available' => (bool) config('cashier.secret'),
        ];
    }

    private function usedThisMonth(int $userId): int
    {
        // Negative rows only, so a refund reduces the figure rather than
        // being counted as use.
        return (int) abs((int) CreditTransaction::where('user_id', $userId)
            ->where('created_at', '>=', now()->startOfMonth())
            ->where('amount', '<', 0)
            ->sum('amount'));
    }

    /**
     * What each action costs, labelled for a person.
     *
     * A review is charged per agent per chapter (ReviewRunner spends
     * `agent_run` or `specialist_run` for each), so that is what is listed,
     * with what a chapter and a whole book come to. The old rows said "Review
     * one chapter 10" and "Review the whole book 40" while a chapter review
     * charged 52–60 and one agent over a book 136 (ADR — Safe apply, honest
     * prices, citation fixes). Those three keys stay in config/billing.php,
     * unlisted.
     *
     * @return array<int, array<string, mixed>>
     */
    private function costRows($user = null): array
    {
        $unlisted = ['chapter_review', 'part_review', 'book_review'];
        $agent = (int) config('billing.costs.agent_run');
        $specialist = (int) config('billing.costs.specialist_run');

        // "Review this chapter" runs every agent the plan includes and that
        // suits the book; the examiners only on a thesis.
        $chapter = collect(EditorialAgent::cases())
            ->reject(fn (EditorialAgent $a) => $a->coachesOnly())
            ->filter(fn (EditorialAgent $a) => ! $user || app(Entitlements::class)->canRunAgent($user, $a))
            ->sum(fn (EditorialAgent $a) => (int) config("billing.costs.{$a->costLabel()}"));

        $labels = [
            'chapter_review' => 'Review one chapter',
            'part_review' => 'Review a part',
            'book_review' => 'Review the whole book',
            'agent_run' => 'Run one editor',
            'specialist_run' => 'Run a specialist reviewer',
            'narrate_chapter' => 'Narrate one chapter',
            'generate_course' => 'Generate a course',
            'marketing_kit' => 'Build a marketing kit',
            'cover_concepts' => 'Suggest three cover concepts',
            'cover_artwork' => 'Paint cover artwork',
        ];

        $labels['agent_run'] = __('Run one editor on one chapter');
        $labels['specialist_run'] = __('Run a specialist reviewer on one chapter');

        return collect(config('billing.costs'))
            ->except($unlisted)
            ->map(fn (int $cost, string $key) => [
                'key' => $key,
                'label' => $labels[$key] ?? $key,
                'cost' => $cost,
            ])
            ->values()
            ->merge([
                ['key' => 'review_chapter', 'label' => __('Review this chapter (every editor your plan includes)'), 'cost' => $chapter],
                ['key' => 'review_book_agent', 'label' => __('One editor over the whole book, per chapter'), 'cost' => "{$agent}–{$specialist}"],
                ['key' => 'review_examiner', 'label' => __('A thesis examiner, on a thesis, per chapter'), 'cost' => $specialist],
            ])
            ->all();
    }

    /**
     * Recent ledger rows.
     *
     * @return array<int, array<string, mixed>>
     */
    private function activity(int $userId): array
    {
        $rows = CreditTransaction::where('user_id', $userId)
            ->latest()
            ->latest('id')
            ->limit(500)
            ->get();

        // A run over many chapters is one line, "Fact Checker — whole book,
        // 17 chapters −136", not seventeen "Specialist reviewer −8" lines
        // (ADR — Safe apply). Runs are read from the reviews the spends paid
        // for; a refund stays its own line.
        $reviews = EditorialReview::whereIn('id', $rows->where('subject_type', (new EditorialReview)->getMorphClass())->pluck('subject_id'))
            ->get(['id', 'agent', 'options'])
            ->keyBy('id');
        $runOf = function (CreditTransaction $row) use ($reviews): ?string {
            $review = $row->kind === 'spend' && $row->subject_type === (new EditorialReview)->getMorphClass() ? $reviews->get($row->subject_id) : null;
            $run = $review?->options['run_id'] ?? null;

            return $run && in_array($review->options['scope'] ?? null, ['book', 'range'], true) ? $run : null;
        };

        $lines = [];
        $index = [];

        foreach ($rows as $row) {
            $run = $runOf($row);

            if ($run !== null && isset($index[$run])) {
                $line = &$lines[$index[$run]];
                $line['amount'] += $row->amount;
                $line['chapters']++;
                unset($line);

                continue;
            }

            if ($run !== null) {
                $index[$run] = count($lines);
            }

            $lines[] = ['row' => $row, 'amount' => $row->amount, 'chapters' => 1, 'run' => $run];
        }

        return collect($lines)
            ->take(20)
            ->map(function (array $line) use ($reviews) {
                $row = $line['row'];
                $label = $this->describe($row);

                if ($line['run'] !== null) {
                    $review = $reviews->get($row->subject_id);
                    $label = __(($review->options['scope'] ?? null) === 'book'
                        ? ':agent — whole book, :count chapters'
                        : ':agent — :count chapters', ['agent' => $review->agent->displayLabel(), 'count' => $line['chapters']]);
                }

                return [
                    'id' => $row->id,
                    'label' => $label,
                    'amount' => $line['amount'],
                    'kind' => $row->kind,
                    'at' => $row->created_at->diffForHumans(),
                ];
            })
            ->all();
    }

    private function describe(CreditTransaction $row): string
    {
        $labels = [
            'agent_run' => 'Editor run',
            'specialist_run' => 'Specialist reviewer',
            'chapter_review' => 'Chapter review',
            'book_review' => 'Whole-book review',
            'test_fixture' => 'Test credit',
        ];

        $base = $labels[$row->label] ?? ucfirst(str_replace('_', ' ', $row->label));

        return match ($row->kind) {
            'refund' => $base.' — refunded',
            'grant' => 'Monthly credits',
            'purchase' => 'Credits purchased',
            'expiry' => 'Credits expired',
            // Adjustments made by Ecrivain staff from the admin console.
            'admin_grant' => 'Credits added by '.config('platform.staff_name'),
            'admin_deduct' => 'Credits adjusted by '.config('platform.staff_name'),
            default => $base,
        };
    }

    /**
     * Credits spent per day over the last fortnight.
     *
     * Real rows, zero-filled so the chart has an even axis. The prototype
     * generates a plausible-looking history and tags it as sample data; an
     * account with no history here shows an empty chart, which is accurate.
     *
     * @return array<int, array<string, mixed>>
     */
    private function dailyUse(int $userId): array
    {
        $spends = CreditTransaction::where('user_id', $userId)
            ->where('amount', '<', 0)
            // A staff correction is not use.
            ->where('kind', '!=', 'admin_deduct')
            ->where('created_at', '>=', now()->subDays(13)->startOfDay())
            ->get()
            ->groupBy(fn (CreditTransaction $row) => $row->created_at->toDateString())
            ->map(fn ($rows) => abs((int) $rows->sum('amount')));

        return collect(range(13, 0))
            ->map(function (int $daysAgo) use ($spends) {
                $day = now()->subDays($daysAgo);

                return [
                    'date' => $day->toDateString(),
                    'label' => $day->format('j M'),
                    'credits' => (int) ($spends[$day->toDateString()] ?? 0),
                    'today' => $daysAgo === 0,
                ];
            })
            ->all();
    }
}
