<?php

namespace App\Platform;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Platform-wide settings, typed (after MemberMD's registry).
 *
 * Defaults live here; a platform_settings row wins. Every key changes
 * behaviour — there are no decorative switches — and every change is written
 * to the admin audit log with its before and after values, in the same
 * transaction.
 *
 *   maintenance_mode         MaintenanceGate: 503 for everybody but staff
 *   maintenance_message      shown on that page
 *   announcement_*           the banner in the app shell
 *   new_signups_open         registration refuses new accounts when off
 *   require_2fa_for_admins   staff without two-factor reach only its set-up
 *
 * A container singleton rather than statics, so a test (or a queue worker
 * between jobs) never reads another's values from a static cache.
 */
final class PlatformSettings
{
    /** key => [type, default] */
    public const FIELDS = [
        'maintenance_mode' => ['bool', false],
        'maintenance_message' => ['text', ''],
        'announcement_enabled' => ['bool', false],
        'announcement_message' => ['text', ''],
        'announcement_tone' => [['info', 'success', 'warning', 'danger'], 'info'],
        'announcement_audience' => [['all', 'writers', 'admins'], 'all'],
        'announcement_ends_at' => ['datetime', null],
        'new_signups_open' => ['bool', true],
        'require_2fa_for_admins' => ['bool', true],
    ];

    private const CACHE_KEY = 'platform.settings';

    private ?array $values = null;

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        $values = array_map(fn ($field) => $field[1], self::FIELDS);

        try {
            $stored = Cache::rememberForever(self::CACHE_KEY, fn () => Schema::hasTable('platform_settings')
                ? DB::table('platform_settings')->pluck('value', 'key')->all()
                : []);

            foreach ($stored as $key => $value) {
                if (array_key_exists($key, self::FIELDS)) {
                    $values[$key] = json_decode((string) $value, true);
                }
            }
        } catch (\Throwable $e) {
            // Defaults only: a settings read must never take the site down.
            // Logged, because a maintenance switch that silently stopped
            // applying is the kind of thing nobody notices for a week.
            report($e);
        }

        return $this->values = $values;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? (self::FIELDS[$key][1] ?? null);
    }

    public function maintenance(): bool
    {
        return (bool) $this->get('maintenance_mode');
    }

    /**
     * The banner for this person, or null.
     *
     * Writers are every signed-in account that is not staff; "all" includes
     * signed-out visitors (the sign-in page). An end time in the past hides
     * it without anybody having to switch it off.
     */
    public function announcementFor(?User $user): ?array
    {
        $s = $this->all();
        $message = trim((string) $s['announcement_message']);

        if (! $s['announcement_enabled'] || $message === '') {
            return null;
        }

        if ($s['announcement_ends_at'] && Carbon::parse($s['announcement_ends_at'])->isPast()) {
            return null;
        }

        $audience = $s['announcement_audience'];
        $admin = $user?->isPlatformAdmin() ?? false;

        if (($audience === 'writers' && ($admin || ! $user)) || ($audience === 'admins' && ! $admin)) {
            return null;
        }

        return [
            'message' => $message,
            'tone' => $s['announcement_tone'],
            'ends_at' => $s['announcement_ends_at'],
            // Changes when the text does, so a dismissed banner comes back
            // for a new announcement.
            'id' => substr(sha1($message.'|'.$s['announcement_tone']), 0, 12),
        ];
    }

    /**
     * Change some settings, audited.
     *
     * @param  array<string, mixed>  $input
     * @return array{before: array, after: array} what actually changed
     *
     * @throws ValidationException
     */
    public function update(array $input, User $actor, ?string $reason = null): array
    {
        $current = $this->all();
        $changed = [];

        foreach ($input as $key => $raw) {
            if (! array_key_exists($key, self::FIELDS)) {
                continue;
            }

            $value = self::coerce($key, $raw);

            if ($value !== $current[$key]) {
                $changed[$key] = $value;
            }
        }

        /*
         | Requiring two-factor for staff is refused to somebody who has not
         | set it up: they would lock themselves out of the page that turns it
         | back off (MemberMD's PlatformSettingsController:46).
         */
        if (($changed['require_2fa_for_admins'] ?? false) === true && ! $actor->hasTwoFactor()) {
            throw ValidationException::withMessages([
                'require_2fa_for_admins' => 'Turn on two-factor sign-in for your own account first.',
            ]);
        }

        if ($changed === []) {
            return ['before' => [], 'after' => []];
        }

        $before = array_intersect_key($current, $changed);

        AdminAudit::transaction(function () use ($changed, $before, $actor, $reason) {
            foreach ($changed as $key => $value) {
                DB::table('platform_settings')->updateOrInsert(
                    ['key' => $key],
                    ['value' => json_encode($value), 'updated_by' => $actor->id, 'updated_at' => now()],
                );
            }

            AdminAudit::record('settings.updated', null, $before, $changed, $reason, $actor);
        });

        $this->flush();

        return ['before' => $before, 'after' => $changed];
    }

    public function flush(): void
    {
        $this->values = null;
        Cache::forget(self::CACHE_KEY);
    }

    /** @throws ValidationException */
    private static function coerce(string $key, mixed $raw): mixed
    {
        $type = self::FIELDS[$key][0];
        $fail = fn (string $message) => throw ValidationException::withMessages([$key => $message]);

        if (is_array($type)) {
            return in_array($raw, $type, true) ? $raw : $fail('Choose one of: '.implode(', ', $type).'.');
        }

        return match ($type) {
            'bool' => filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                ?? $fail('Must be on or off.'),
            'text' => mb_strlen(trim((string) $raw)) <= 500
                ? trim((string) $raw)
                : $fail('500 characters at most.'),
            'datetime' => blank($raw) ? null : (function () use ($raw, $fail) {
                try {
                    return Carbon::parse((string) $raw)->toIso8601String();
                } catch (\Throwable) {
                    return $fail('Not a date and time.');
                }
            })(),
        };
    }
}
