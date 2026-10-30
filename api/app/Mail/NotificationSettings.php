<?php

namespace App\Mail;

use App\Models\Book;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\Rule;

/**
 * How and when notifications reach somebody, beyond "this category on or
 * off" (EmailPreferences).
 *
 * For each digestible category (activity about a book: collaboration,
 * editorial reviews, exports and publishing, sales, partners):
 *
 *  - email: instant, daily digest, weekly digest, or off. "Off" is the
 *    existing EmailPreferences switch, so the unsubscribe link, the old
 *    profile toggles and this setting are one fact, not two;
 *  - inbox: on or off, for the categories the inbox has items for.
 *
 * And for the account: books muted (no email, inbox items arrive already
 * read), quiet hours (instant emails held until they end), and the digest
 * day and hour — one schedule for the daily digest (hour), the weekly
 * digest and the weekly progress email (day and hour), in the account's
 * timezone (users.timezone, else the platform's reporting zone).
 *
 * Stored as overrides in users.notification_settings; a key that is absent
 * means the default, and the defaults are exactly what happened before this
 * existed: every email instant, every inbox item, nothing muted, no quiet
 * hours.
 */
final class NotificationSettings
{
    public const FREQUENCIES = ['instant', 'daily', 'weekly', 'off'];

    /** Inbox kinds (App\Models\InboxItem::KINDS) each category covers. */
    public const INBOX_KINDS = [
        'collaboration' => ['comment', 'reply', 'mention', 'workflow', 'assigned', 'team'],
        'writing' => ['review'],
        'publishing' => ['export_ready', 'export_failed'],
        'partners' => ['partner'],
    ];

    public const DEFAULT_DAY = 1;      // ISO weekday: Monday

    public const DEFAULT_HOUR = 8;

    /** Emails a quiet-hours window may hold back: activity, never security or billing. */
    public static function quietable(string $category): bool
    {
        return EmailPreferences::isDigestible($category);
    }

    // ── Reading ─────────────────────────────────────────────────────────

    public static function frequency(User $user, string $category): string
    {
        if (! EmailPreferences::isDigestible($category)) {
            return EmailPreferences::allows($user, $category) ? 'instant' : 'off';
        }

        if (! EmailPreferences::allows($user, $category)) {
            return 'off';
        }

        $stored = self::stored($user)['frequency'][$category] ?? 'instant';

        return in_array($stored, ['instant', 'daily', 'weekly'], true) ? $stored : 'instant';
    }

    public static function inboxAllows(User $user, string $kind): bool
    {
        $category = self::categoryForKind($kind);

        return $category === null || (bool) (self::stored($user)['inbox'][$category] ?? true);
    }

    public static function categoryForKind(string $kind): ?string
    {
        foreach (self::INBOX_KINDS as $category => $kinds) {
            if (in_array($kind, $kinds, true)) {
                return $category;
            }
        }

        return null;
    }

    /** @return list<int> */
    public static function mutedBooks(User $user): array
    {
        return array_values(array_map('intval', self::stored($user)['muted_books'] ?? []));
    }

    public static function isMuted(User $user, Book|int|null $book): bool
    {
        if ($book === null) {
            return false;
        }

        return in_array($book instanceof Book ? $book->id : $book, self::mutedBooks($user), true);
    }

    /** @return array{start: int, end: int}|null hours 0–23, local */
    public static function quietHours(User $user): ?array
    {
        $quiet = self::stored($user)['quiet_hours'] ?? null;

        if (! is_array($quiet) || ! isset($quiet['start'], $quiet['end']) || (int) $quiet['start'] === (int) $quiet['end']) {
            return null;
        }

        return ['start' => (int) $quiet['start'], 'end' => (int) $quiet['end']];
    }

    public static function digestDay(User $user): int
    {
        return (int) (self::stored($user)['digest_day'] ?? self::DEFAULT_DAY);
    }

    public static function digestHour(User $user): int
    {
        return (int) (self::stored($user)['digest_hour'] ?? self::DEFAULT_HOUR);
    }

    public static function timezone(User $user): string
    {
        return $user->reportTimezone();
    }

    // ── Time ────────────────────────────────────────────────────────────

    /**
     * When quiet hours that cover $at end, in UTC; null when $at is not
     * inside them. A window may cross midnight (22 → 7).
     */
    public static function quietUntil(User $user, ?CarbonInterface $at = null): ?CarbonImmutable
    {
        $quiet = self::quietHours($user);

        if ($quiet === null) {
            return null;
        }

        $local = CarbonImmutable::instance($at ?? now())->setTimezone(self::timezone($user));
        $hour = $local->hour;
        $inside = $quiet['start'] < $quiet['end']
            ? $hour >= $quiet['start'] && $hour < $quiet['end']
            : $hour >= $quiet['start'] || $hour < $quiet['end'];

        if (! $inside) {
            return null;
        }

        $end = $local->setTime($quiet['end'], 0);

        if ($end->lessThanOrEqualTo($local)) {
            $end = $end->addDay();
        }

        return $end->utc();
    }

    /**
     * The most recent scheduled moment, at or before $now, of a daily or
     * weekly delivery, in UTC. Everything held before it goes in the digest
     * for that moment; a run that was missed catches up on the next one.
     */
    public static function lastMoment(User $user, string $kind, ?CarbonInterface $now = null): CarbonImmutable
    {
        $local = CarbonImmutable::instance($now ?? now())->setTimezone(self::timezone($user));
        $moment = $local->setTime(self::digestHour($user), 0);

        if ($kind === 'daily') {
            return ($moment->greaterThan($local) ? $moment->subDay() : $moment)->utc();
        }

        // weekly and progress: this week's chosen day, or last week's.
        $moment = $moment->addDays(self::digestDay($user) - $local->dayOfWeekIso);

        return ($moment->greaterThan($local) ? $moment->subWeek() : $moment)->utc();
    }

    // ── Writing ─────────────────────────────────────────────────────────

    /** Validation for the profile form; every key optional. @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'frequency' => ['sometimes', 'array'],
            'frequency.*' => ['string', Rule::in(self::FREQUENCIES)],
            'inbox' => ['sometimes', 'array'],
            'inbox.*' => ['boolean'],
            'quiet_hours' => ['sometimes', 'nullable', 'array'],
            'quiet_hours.start' => ['required_with:quiet_hours', 'integer', 'between:0,23'],
            'quiet_hours.end' => ['required_with:quiet_hours', 'integer', 'between:0,23', 'different:quiet_hours.start'],
            'digest_day' => ['sometimes', 'integer', 'between:1,7'],
            'digest_hour' => ['sometimes', 'integer', 'between:0,23'],
        ];
    }

    /**
     * Apply validated changes. Unknown categories are ignored, not stored,
     * and "off" goes to EmailPreferences, the one place on/off lives.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function update(User $user, array $changes): void
    {
        $stored = self::stored($user);
        $toggles = [];

        foreach ($changes['frequency'] ?? [] as $category => $frequency) {
            if (! EmailPreferences::isDigestible((string) $category) || ! in_array($frequency, self::FREQUENCIES, true)) {
                continue;
            }

            $toggles[$category] = $frequency !== 'off';

            if ($frequency === 'instant' || $frequency === 'off') {
                unset($stored['frequency'][$category]);
            } else {
                $stored['frequency'][$category] = $frequency;
            }
        }

        foreach ($changes['inbox'] ?? [] as $category => $on) {
            if (! isset(self::INBOX_KINDS[$category])) {
                continue;
            }

            if ($on) {
                unset($stored['inbox'][$category]);
            } else {
                $stored['inbox'][$category] = false;
            }
        }

        if (array_key_exists('quiet_hours', $changes)) {
            $quiet = $changes['quiet_hours'];

            if (is_array($quiet) && (int) $quiet['start'] !== (int) $quiet['end']) {
                $stored['quiet_hours'] = ['start' => (int) $quiet['start'], 'end' => (int) $quiet['end']];
            } else {
                unset($stored['quiet_hours']);
            }
        }

        foreach (['digest_day' => self::DEFAULT_DAY, 'digest_hour' => self::DEFAULT_HOUR] as $key => $default) {
            if (array_key_exists($key, $changes)) {
                if ((int) $changes[$key] === $default) {
                    unset($stored[$key]);
                } else {
                    $stored[$key] = (int) $changes[$key];
                }
            }
        }

        $user->forceFill(['notification_settings' => self::clean($stored) ?: null])->save();

        if ($toggles !== []) {
            EmailPreferences::update($user, $toggles);
        }
    }

    /** Mute or unmute one book. */
    public static function mute(User $user, Book $book, bool $muted = true): void
    {
        $stored = self::stored($user);
        $ids = array_values(array_diff(self::mutedBooks($user), [$book->id]));

        if ($muted) {
            $ids[] = $book->id;
        }

        $stored['muted_books'] = $ids;
        $user->forceFill(['notification_settings' => self::clean($stored) ?: null])->save();
    }

    // ── For the profile page ────────────────────────────────────────────

    /** @return array<string, mixed> */
    public static function describe(User $user): array
    {
        $muted = self::mutedBooks($user);

        return [
            'frequency' => collect(EmailPreferences::digestible())
                ->mapWithKeys(fn (string $c) => [$c => self::frequency($user, $c)])
                ->all(),
            'inbox' => collect(array_keys(self::INBOX_KINDS))
                ->mapWithKeys(fn (string $c) => [$c => (bool) (self::stored($user)['inbox'][$c] ?? true)])
                ->all(),
            'quiet_hours' => self::quietHours($user),
            'digest_day' => self::digestDay($user),
            'digest_hour' => self::digestHour($user),
            'timezone' => self::timezone($user),
            'muted_books' => $muted === []
                ? []
                : $user->accessibleBooks()->whereIn('books.id', $muted)->orderBy('title')
                    ->get(['books.id', 'books.title'])
                    ->map(fn (Book $b) => ['id' => $b->id, 'title' => $b->title])
                    ->values()->all(),
        ];
    }

    // ── Internals ───────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private static function stored(User $user): array
    {
        return is_array($user->notification_settings) ? $user->notification_settings : [];
    }

    /** Drops empty groups so a person back on the defaults stores nothing. */
    private static function clean(array $stored): array
    {
        return array_filter($stored, fn ($value) => $value !== [] && $value !== null);
    }
}
