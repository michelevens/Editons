<?php

namespace App\Growth;

use App\Mail\EmailPreferences;
use App\Models\User;
use App\Platform\Mask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who an audience is: rules over real fields, turned into one query.
 *
 * The rules are a fixed shape, not a free-form expression: every field the
 * builder offers is listed here, normalised (unknown keys dropped, numbers
 * clamped) before it is stored or run. All rules must hold (AND); a list
 * matches any of its values (OR).
 *
 *   plans                  users.plan is one of
 *   joined_within_days     signed up in the last N days
 *   joined_before_days     signed up more than N days ago
 *   active_within_days     signed in, or saved a version, in the last N days
 *   inactive_days          neither, for at least N days
 *   books_min, books_max   books owned, archived ones not counted
 *   words_min              words across those books
 *   has_exported           yes | no: any export of a book they own
 *   languages              a book in one of these languages
 *   countries              a book published in one of these countries
 *   marketing              yes | no: "News from Écrivain" allowed
 *
 * Every audience leaves out suspended accounts and Écrivain's own staff. A
 * broadcast also leaves out, at send time, anybody whose preferences no
 * longer allow it — the count says how many that is.
 */
final class AudienceRules
{
    public const EMPTY = [
        'plans' => [],
        'joined_within_days' => null,
        'joined_before_days' => null,
        'active_within_days' => null,
        'inactive_days' => null,
        'books_min' => null,
        'books_max' => null,
        'words_min' => null,
        'has_exported' => null,
        'languages' => [],
        'countries' => [],
        'marketing' => null,
    ];

    /**
     * The canonical form of $rules: every key present, nothing else.
     *
     * @param  array<string, mixed>|null  $rules
     * @return array<string, mixed>
     */
    public static function normalize(?array $rules): array
    {
        $rules ??= [];
        $int = fn (string $key, int $max) => isset($rules[$key]) && $rules[$key] !== '' && is_numeric($rules[$key])
            ? max(0, min($max, (int) $rules[$key]))
            : null;
        $list = fn (string $key, int $len) => array_values(array_unique(array_filter(array_map(
            fn ($v) => is_scalar($v) ? mb_substr(trim((string) $v), 0, $len) : '',
            is_array($rules[$key] ?? null) ? $rules[$key] : [],
        ), fn ($v) => $v !== '')));
        $yesNo = fn (string $key) => in_array($rules[$key] ?? null, ['yes', 'no'], true) ? $rules[$key] : null;

        return [
            'plans' => array_slice($list('plans', 32), 0, 20),
            'joined_within_days' => $int('joined_within_days', 3650),
            'joined_before_days' => $int('joined_before_days', 3650),
            'active_within_days' => $int('active_within_days', 3650),
            'inactive_days' => $int('inactive_days', 3650),
            'books_min' => $int('books_min', 100000),
            'books_max' => $int('books_max', 100000),
            'words_min' => $int('words_min', 100000000),
            'has_exported' => $yesNo('has_exported'),
            'languages' => array_slice($list('languages', 60), 0, 20),
            'countries' => array_slice(array_map('strtoupper', $list('countries', 2)), 0, 50),
            'marketing' => $yesNo('marketing'),
        ];
    }

    /** The accounts these rules select. */
    public static function query(?array $rules): Builder
    {
        $r = self::normalize($rules);
        $now = now();

        $query = User::query()
            ->whereNull('suspended_at')
            ->whereNull('platform_role')
            ->exceptHouse();

        if ($r['plans'] !== []) {
            $query->whereIn('plan', $r['plans']);
        }

        if ($r['joined_within_days'] !== null) {
            $query->where('created_at', '>=', $now->copy()->subDays($r['joined_within_days']));
        }

        if ($r['joined_before_days'] !== null) {
            $query->where('created_at', '<', $now->copy()->subDays($r['joined_before_days']));
        }

        if ($r['active_within_days'] !== null) {
            $since = $now->copy()->subDays($r['active_within_days']);
            $query->where(fn ($q) => self::activeSince($q, $since));
        }

        if ($r['inactive_days'] !== null) {
            $since = $now->copy()->subDays($r['inactive_days']);
            $query->whereNot(fn ($q) => self::activeSince($q, $since));
        }

        $books = fn () => DB::table('books')->whereColumn('books.user_id', 'users.id')->where('books.status', '!=', 'archived');

        if ($r['books_min'] !== null) {
            $query->whereRaw('('.$books()->selectRaw('count(*)')->toSql().') >= ?', [...$books()->getBindings(), $r['books_min']]);
        }

        if ($r['books_max'] !== null) {
            $query->whereRaw('('.$books()->selectRaw('count(*)')->toSql().') <= ?', [...$books()->getBindings(), $r['books_max']]);
        }

        if ($r['words_min'] !== null) {
            $query->whereRaw('('.$books()->selectRaw('coalesce(sum(word_count), 0)')->toSql().') >= ?', [...$books()->getBindings(), $r['words_min']]);
        }

        if ($r['has_exported'] !== null) {
            $exported = fn ($q) => $q->select(DB::raw(1))
                ->from('book_exports')
                ->join('books', 'books.id', '=', 'book_exports.book_id')
                ->whereColumn('books.user_id', 'users.id');

            $r['has_exported'] === 'yes' ? $query->whereExists($exported) : $query->whereNotExists($exported);
        }

        if ($r['languages'] !== []) {
            $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('books')
                ->whereColumn('books.user_id', 'users.id')
                ->whereIn('books.language', $r['languages']));
        }

        if ($r['countries'] !== []) {
            $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('books')
                ->whereColumn('books.user_id', 'users.id')
                ->whereIn(DB::raw("upper(books.publication->>'country')"), $r['countries']));
        }

        if ($r['marketing'] !== null) {
            $r['marketing'] === 'yes' ? self::allowing($query, 'news') : $query->whereRaw("(users.email_preferences::jsonb ->> 'news') = 'false'");
        }

        return $query;
    }

    /**
     * Only the accounts whose preferences allow $category (absent means the
     * default, which is on — EmailPreferences::allows).
     */
    public static function allowing(Builder $query, string $category): Builder
    {
        if (EmailPreferences::isMandatory($category)) {
            return $query;
        }

        return $query->where(fn ($q) => $q->whereNull('users.email_preferences')
            ->orWhereRaw("coalesce(users.email_preferences::jsonb ->> ?, 'true') <> 'false'", [$category]));
    }

    /**
     * How many accounts match, how many of them the mail would reach, and a
     * few masked examples.
     *
     * @return array{count: int, reachable: int, sample: list<string>}
     */
    public static function preview(?array $rules, string $category = 'news'): array
    {
        return [
            'count' => self::query($rules)->count(),
            'reachable' => self::allowing(self::query($rules), $category)->count(),
            'sample' => self::query($rules)->latest('id')->limit(5)->pluck('email')->map(fn ($e) => Mask::email($e))->all(),
        ];
    }

    /** What the builder offers: plans, and the languages and countries in use. @return array<string, mixed> */
    public static function options(): array
    {
        return [
            'languages' => DB::table('books')->whereNotNull('language')->distinct()->orderBy('language')->limit(100)->pluck('language')->all(),
            'countries' => DB::table('books')->whereRaw("books.publication->>'country' is not null")
                ->selectRaw("distinct upper(books.publication->>'country') as c")->orderBy('c')->limit(250)->pluck('c')->all(),
        ];
    }

    /** One line describing the rules, for lists. */
    public static function describe(?array $rules, array $planNames = []): string
    {
        $r = self::normalize($rules);
        $parts = [];

        if ($r['plans']) {
            $parts[] = 'Plan: '.implode(', ', array_map(fn ($k) => $planNames[$k] ?? $k, $r['plans']));
        }
        if ($r['joined_within_days'] !== null) {
            $parts[] = "joined in the last {$r['joined_within_days']} days";
        }
        if ($r['joined_before_days'] !== null) {
            $parts[] = "joined over {$r['joined_before_days']} days ago";
        }
        if ($r['active_within_days'] !== null) {
            $parts[] = "active in the last {$r['active_within_days']} days";
        }
        if ($r['inactive_days'] !== null) {
            $parts[] = "inactive for {$r['inactive_days']}+ days";
        }
        if ($r['books_min'] !== null) {
            $parts[] = "at least {$r['books_min']} book".($r['books_min'] === 1 ? '' : 's');
        }
        if ($r['books_max'] !== null) {
            $parts[] = "at most {$r['books_max']} book".($r['books_max'] === 1 ? '' : 's');
        }
        if ($r['words_min'] !== null) {
            $parts[] = number_format($r['words_min']).'+ words';
        }
        if ($r['has_exported'] !== null) {
            $parts[] = $r['has_exported'] === 'yes' ? 'has exported' : 'never exported';
        }
        if ($r['languages']) {
            $parts[] = 'writes in '.implode(', ', $r['languages']);
        }
        if ($r['countries']) {
            $parts[] = 'publishes in '.implode(', ', $r['countries']);
        }
        if ($r['marketing'] !== null) {
            $parts[] = $r['marketing'] === 'yes' ? 'accepts news' : 'turned news off';
        }

        return $parts === [] ? 'Every account' : ucfirst(implode(' · ', $parts));
    }

    /** Signed in or saved a version (not during "View as") since $since. */
    private static function activeSince($query, $since): void
    {
        $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('user_logins')
            ->whereColumn('user_logins.user_id', 'users.id')
            ->where('user_logins.created_at', '>=', $since)
            ->where('user_logins.impersonated', false))
            ->orWhereExists(fn ($q) => $q->select(DB::raw(1))->from('manuscript_versions')
                ->whereColumn('manuscript_versions.user_id', 'users.id')
                ->whereNull('manuscript_versions.impersonator_id')
                ->where('manuscript_versions.created_at', '>=', $since));
    }
}
