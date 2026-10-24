<?php

namespace App\Growth;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sign-up to first payment, from the rows that record each step.
 *
 *   signed up       users.created_at
 *   first book      a book they own
 *   first chapter   a version they saved themselves (not during View as)
 *   first export    an export of a book they own
 *   first paid      a subscription that took money: active or past due
 *                   now, or ended after a trial (or without one)
 *
 * Each step counts accounts that reached it at any time since signing up,
 * so a cohort's later weeks keep filling in. Staff accounts are left out.
 * Grouped by first-touch source (users.signup_source) and by the week the
 * account was created.
 */
final class Funnel
{
    public const STEPS = [
        'signed_up' => 'Signed up',
        'first_book' => 'First book',
        'first_chapter' => 'First chapter saved',
        'first_export' => 'First export',
        'first_paid' => 'First payment',
    ];

    /** @return array{steps: array<string, string>, total: array<string, int>, by_source: list<array<string, mixed>>, by_week: list<array<string, mixed>>, since: string} */
    public function report(int $weeks = 12): array
    {
        $since = now()->startOfWeek()->subWeeks($weeks - 1);
        $rows = $this->rows($since);

        return [
            'steps' => self::STEPS,
            'total' => self::count($rows),
            'by_source' => $rows->groupBy(fn ($r) => Attribution::label($r->signup_source))
                ->map(fn (Collection $g, string $source) => ['source' => $source] + self::count($g))
                ->sortByDesc('signed_up')
                ->values()
                ->all(),
            'by_week' => collect(range(0, $weeks - 1))
                ->map(function (int $i) use ($since, $rows) {
                    $start = $since->copy()->addWeeks($i);
                    $group = $rows->filter(fn ($r) => Carbon::parse($r->created_at)->between($start, $start->copy()->endOfWeek()));

                    return ['week' => $start->toDateString()] + self::count($group);
                })
                ->reverse()
                ->values()
                ->all(),
            'since' => $since->toDateString(),
        ];
    }

    private function rows(Carbon $since): Collection
    {
        return DB::table('users')
            ->whereNull('platform_role')
            ->where('created_at', '>=', $since)
            ->select(['id', 'created_at', 'signup_source'])
            ->selectRaw('exists (select 1 from books where books.user_id = users.id) as first_book')
            ->selectRaw('exists (select 1 from manuscript_versions v where v.user_id = users.id and v.impersonator_id is null) as first_chapter')
            ->selectRaw('exists (select 1 from book_exports e join books b on b.id = e.book_id where b.user_id = users.id) as first_export')
            ->selectRaw("exists (select 1 from subscriptions s where s.user_id = users.id and (s.stripe_status in ('active','past_due') or (s.stripe_status in ('canceled','unpaid') and (s.trial_ends_at is null or s.ends_at > s.trial_ends_at)))) as first_paid")
            ->get();
    }

    /** @return array<string, int> */
    private static function count(Collection $rows): array
    {
        return [
            'signed_up' => $rows->count(),
            'first_book' => $rows->filter(fn ($r) => (bool) $r->first_book)->count(),
            'first_chapter' => $rows->filter(fn ($r) => (bool) $r->first_chapter)->count(),
            'first_export' => $rows->filter(fn ($r) => (bool) $r->first_export)->count(),
            'first_paid' => $rows->filter(fn ($r) => (bool) $r->first_paid)->count(),
        ];
    }
}
