<?php

namespace App\Mail;

use App\Mail\Account\ActivityDigestMail;
use App\Mail\Support\WeeklyProgress;
use App\Mail\Writing\WeeklyDigestMail;
use App\Models\EmailDigest;
use App\Models\HeldEmail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Emails kept back, and the hourly run that lets them go
 * (`php artisan notifications:deliver`).
 *
 * Three kinds of hold, written by Notify::send:
 *  - daily / weekly: the email itself is not sent; its subject and link go
 *    into one digest at the person's digest hour (and day);
 *  - quiet: an instant email during quiet hours, sent as it was once they
 *    end.
 *
 * And one scheduled email that is not a hold: the weekly progress summary,
 * for people who opted in, on their day at their hour.
 *
 * Never twice. A digest is a row unique on (person, kind, period) claimed
 * before sending and stamped sent inside the same locked transaction; a
 * quiet hold is claimed by stamping sent_at where it is still null. A run
 * that dies mid-way leaves an unsent digest row, which the next run sends.
 */
final class HeldEmails
{
    public const MODES = ['daily', 'weekly', 'quiet'];

    /** Keep $mail back for $user. */
    public static function hold(User $user, EcrivainMail $mail, string $mode, ?CarbonInterface $releaseAt = null): HeldEmail
    {
        $locale = $mail->locale ?? $user->preferredLocale() ?? app()->getLocale();
        $letter = $mail->withLocale($locale, fn () => $mail->letter());

        return HeldEmail::create([
            'user_id' => $user->id,
            'book_id' => $mail->aboutBook()?->id,
            'category' => $mail->category(),
            'mode' => $mode,
            'subject' => mb_substr($letter->subject, 0, 300),
            'url' => $letter->cta['url'] ?? null,
            'mailable' => $mode === 'quiet' ? serialize($mail) : null,
            'release_at' => $releaseAt,
        ]);
    }

    /**
     * Everything due at $now. Each part is independent: one person's
     * broken digest does not stop the others.
     *
     * @return array{released: int, digests: int, progress: int}
     */
    public static function deliver(?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now());

        return [
            'released' => self::releaseQuiet($now),
            'digests' => self::sendDigests($now),
            'progress' => self::sendProgress($now),
        ];
    }

    /** Instant emails whose quiet hours are over, sent as they were. */
    public static function releaseQuiet(CarbonInterface $now): int
    {
        $sent = 0;

        HeldEmail::where('mode', 'quiet')
            ->whereNull('sent_at')
            ->where('release_at', '<=', $now)
            ->orderBy('id')
            ->each(function (HeldEmail $held) use ($now, &$sent) {
                // Claimed first: a second runner finds nothing to update.
                if (HeldEmail::whereKey($held->id)->whereNull('sent_at')->update(['sent_at' => $now]) !== 1) {
                    return;
                }

                $user = $held->user;

                try {
                    $mail = unserialize($held->mailable);
                } catch (Throwable) {
                    // The comment or export it was about is gone.
                    return;
                }

                // Preferences as they are now: switched off or muted since
                // it was held means it is not wanted any more.
                if ($user && $mail instanceof EcrivainMail
                    && EmailPreferences::allows($user, $mail->category())
                    && ! NotificationSettings::isMuted($user, $held->book_id)) {
                    $sent += (int) Notify::deliver($user, $mail, $user);
                }
            });

        return $sent;
    }

    /** Daily and weekly digests whose moment has come. */
    public static function sendDigests(CarbonInterface $now): int
    {
        $sent = 0;

        $userIds = HeldEmail::whereIn('mode', ['daily', 'weekly'])
            ->whereNull('email_digest_id')
            ->whereNull('sent_at')
            ->distinct()
            ->pluck('user_id');

        // Plus anybody whose digest was claimed but not sent (a failed run).
        $userIds = $userIds->merge(EmailDigest::whereIn('kind', ['daily', 'weekly'])->whereNull('sent_at')->pluck('user_id'))->unique();

        foreach (User::whereIn('id', $userIds)->orderBy('id')->get() as $user) {
            foreach (['daily', 'weekly'] as $kind) {
                try {
                    $sent += (int) self::digestFor($user, $kind, $now);
                } catch (Throwable $e) {
                    if (app()->runningUnitTests()) {
                        throw $e;
                    }

                    report($e);
                }
            }
        }

        return $sent;
    }

    /** One person's digest of one kind, if one is due. */
    public static function digestFor(User $user, string $kind, CarbonInterface $now): bool
    {
        $moment = NotificationSettings::lastMoment($user, $kind, $now);
        $period = $moment->setTimezone(NotificationSettings::timezone($user))->toDateString();

        $digest = EmailDigest::where(['user_id' => $user->id, 'kind' => $kind, 'period' => $period])->first();

        if ($digest?->sent_at) {
            return false;
        }

        if (! $digest) {
            $waiting = HeldEmail::where('user_id', $user->id)
                ->where('mode', $kind)
                ->whereNull('email_digest_id')
                ->whereNull('sent_at')
                ->where('created_at', '<', $moment);

            if (! $waiting->exists()) {
                return false;
            }

            $digest = DB::transaction(function () use ($user, $kind, $period, $waiting) {
                $digest = self::claim($user, $kind, $period);

                // Null: another run claimed this period first.
                $digest?->update(['items' => (clone $waiting)->update(['email_digest_id' => $digest->id])]);

                return $digest;
            });

            if (! $digest) {
                return false;
            }
        }

        return DB::transaction(function () use ($user, $digest) {
            $locked = EmailDigest::whereKey($digest->id)->lockForUpdate()->first();

            if ($locked->sent_at) {
                return false;
            }

            $items = $locked->heldEmails()->with('book:id,title')->orderBy('created_at')->get()
                ->map(fn (HeldEmail $held) => [
                    'category' => $held->category,
                    'subject' => $held->subject,
                    'url' => $held->url,
                    'book' => $held->book?->title,
                    'at' => $held->created_at->toIso8601String(),
                ])->all();

            $sent = $items !== [] && Notify::deliver($user, new ActivityDigestMail($user, $locked->kind, $items), $user);

            $locked->update(['sent_at' => now()]);
            $locked->heldEmails()->update(['sent_at' => now()]);

            return $sent;
        });
    }

    /**
     * The weekly progress summary, for people who opted in, on their day
     * at their hour. The seven days before the send day, in their zone.
     * Recorded even when it says nothing (a quiet week is not sent), so
     * each person is looked at once a week, not every hour.
     */
    public static function sendProgress(CarbonInterface $now): int
    {
        $sent = 0;

        User::query()
            ->where('email_preferences->digest', true)
            ->exceptHouse()
            ->whereHas('books')
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($now, &$sent) {
                foreach ($users as $user) {
                    try {
                        $sent += (int) self::progressFor($user, $now);
                    } catch (Throwable $e) {
                        if (app()->runningUnitTests()) {
                            throw $e;
                        }

                        report($e);
                    }
                }
            });

        return $sent;
    }

    /**
     * The row for one person, kind and period, or null when it already
     * exists. ON CONFLICT DO NOTHING rather than catching the violation:
     * inside a transaction Postgres aborts on a failed insert.
     */
    private static function claim(User $user, string $kind, string $period): ?EmailDigest
    {
        $row = ['user_id' => $user->id, 'kind' => $kind, 'period' => $period, 'items' => 0, 'created_at' => now(), 'updated_at' => now()];

        return EmailDigest::query()->insertOrIgnore($row) === 1
            ? EmailDigest::where(['user_id' => $user->id, 'kind' => $kind, 'period' => $period])->first()
            : null;
    }

    public static function progressFor(User $user, CarbonInterface $now): bool
    {
        if (! EmailPreferences::allows($user, 'digest')) {
            return false;
        }

        $zone = NotificationSettings::timezone($user);
        $moment = NotificationSettings::lastMoment($user, 'progress', $now)->setTimezone($zone);
        $period = $moment->toDateString();

        // Only within a day of the moment: turning it on on a Thursday for
        // a Monday does not send last Monday's summary three days late.
        if (CarbonImmutable::instance($now)->diffInHours($moment, true) >= 24) {
            return false;
        }

        $digest = self::claim($user, 'progress', $period)
            ?? EmailDigest::where(['user_id' => $user->id, 'kind' => 'progress', 'period' => $period])->first();

        return DB::transaction(function () use ($user, $digest, $moment) {
            $locked = EmailDigest::whereKey($digest->id)->lockForUpdate()->first();

            if ($locked->sent_at) {
                return false;
            }

            $end = $moment->startOfDay()->subSecond();
            $start = $moment->startOfDay()->subDays(7);
            $summary = WeeklyProgress::for($user, $start->utc(), $end->utc());
            $quiet = $summary === null || ($summary['words'] === 0 && $summary['active_days'] === 0);

            $sent = ! $quiet && Notify::deliver($user, new WeeklyDigestMail($user, $start, $end, $summary), $user);

            $locked->update(['sent_at' => now(), 'items' => (int) $sent]);

            return $sent;
        });
    }
}
