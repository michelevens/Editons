<?php

namespace App\Growth;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * How far an account has got through setting up, from real rows.
 *
 *   verified    the email address was confirmed
 *   book        it owns a book
 *   chapter     it saved a version itself (not during View as)
 *   export      it exported a book it owns
 *   two_factor  two-factor sign-in is on
 *   cover       a book's cover was designed in Cover Studio
 *
 * The steps are in the order most people take them, and "stuck at" is the
 * first one not done — what support would nudge somebody towards next.
 */
final class Onboarding
{
    public const STEPS = [
        'verified' => 'Verified email',
        'book' => 'First book',
        'chapter' => 'First chapter saved',
        'export' => 'First export',
        'two_factor' => 'Two-factor on',
        'cover' => 'Cover designed',
    ];

    /** @return list<array{key: string, label: string, done: bool}> */
    public function stepsFor(User $user): array
    {
        $row = $this->query()->where('users.id', $user->id)->first();

        return array_map(fn (string $key) => [
            'key' => $key,
            'label' => self::STEPS[$key],
            'done' => (bool) ($row->{$key} ?? false),
        ], array_keys(self::STEPS));
    }

    /**
     * Across accounts (staff left out), optionally only those created in the
     * last $days days: how many have done each step, and how many are stuck
     * at each (it is the first step they have not done).
     *
     * @return array{accounts: int, complete: int, steps: list<array<string, mixed>>}
     */
    public function overview(?int $days = null): array
    {
        $rows = $this->query()
            ->whereNull('users.platform_role')
            ->where('users.account_kind', '!=', 'house')
            ->when($days, fn ($q) => $q->where('users.created_at', '>=', now()->subDays($days)))
            ->get();

        $keys = array_keys(self::STEPS);
        $stuck = array_fill_keys($keys, 0);
        $done = array_fill_keys($keys, 0);
        $complete = 0;

        foreach ($rows as $row) {
            $first = null;

            foreach ($keys as $key) {
                if ($row->{$key}) {
                    $done[$key]++;
                } elseif ($first === null) {
                    $first = $key;
                }
            }

            $first === null ? $complete++ : $stuck[$first]++;
        }

        $total = $rows->count();

        return [
            'accounts' => $total,
            'complete' => $complete,
            'steps' => array_map(fn (string $key) => [
                'key' => $key,
                'label' => self::STEPS[$key],
                'done' => $done[$key],
                'done_pct' => $total > 0 ? (int) round($done[$key] / $total * 100) : 0,
                'stuck' => $stuck[$key],
            ], $keys),
        ];
    }

    /**
     * The accounts stuck at $step, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function stuckAt(string $step, ?int $days = null, int $limit = 50): array
    {
        $keys = array_keys(self::STEPS);
        $index = array_search($step, $keys, true);

        if ($index === false) {
            return [];
        }

        $query = $this->query()
            ->whereNull('users.platform_role')
            ->where('users.account_kind', '!=', 'house')
            ->when($days, fn ($q) => $q->where('users.created_at', '>=', now()->subDays($days)));

        // Done everything before $step, not $step itself.
        foreach (array_slice($keys, 0, $index) as $before) {
            $query->whereRaw(self::EXPRESSIONS[$before]);
        }

        return $query->whereRaw('not ('.self::EXPRESSIONS[$step].')')
            ->orderByDesc('users.created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'name' => $row->name,
                'email' => $row->email,
                'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            ])
            ->all();
    }

    private const EXPRESSIONS = [
        'verified' => 'users.email_verified_at is not null',
        'book' => 'exists (select 1 from books b where b.user_id = users.id)',
        'chapter' => 'exists (select 1 from manuscript_versions v where v.user_id = users.id and v.impersonator_id is null)',
        'export' => 'exists (select 1 from book_exports e join books b on b.id = e.book_id where b.user_id = users.id)',
        'two_factor' => 'users.two_factor_confirmed_at is not null',
        'cover' => 'exists (select 1 from books b where b.user_id = users.id and b.cover is not null)',
    ];

    private function query()
    {
        $query = DB::table('users')->select(['users.id', 'users.name', 'users.email', 'users.created_at']);

        foreach (self::EXPRESSIONS as $key => $sql) {
            $query->selectRaw("({$sql}) as {$key}");
        }

        return $query;
    }
}
