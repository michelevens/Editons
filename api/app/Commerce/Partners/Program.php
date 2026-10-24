<?php

namespace App\Commerce\Partners;

use App\Models\User;
use App\Platform\AdminAudit;
use App\Platform\Features;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The program's settings and its switch.
 *
 * Settings: config/partners.php holds the defaults; the owner can change
 * them in the console, which writes `partners.*` rows to platform_settings
 * (audited, like every console change). A setting only ever applies to
 * what happens next: a commission copies its rate, a hold its end date.
 *
 * The switch is the `partners` feature flag, which needs direct sales on as
 * well (App\Platform\Features, prerequisite `commerce`).
 */
final class Program
{
    /** key => [min, max] */
    public const SETTINGS = [
        'default_rate' => [1, 100],
        'min_rate' => [1, 100],
        'max_rate' => [1, 100],
        'hold_days' => [0, 365],
        'cookie_days' => [1, 365],
        'payout_minimum_cents' => [0, 1_000_000],
    ];

    private const CACHE_KEY = 'partners.settings';

    private ?array $values = null;

    public static function enabled(?User $user = null): bool
    {
        return Features::enabled('partners', $user);
    }

    /** @return array<string, int> */
    public function settings(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        $values = [];

        foreach (array_keys(self::SETTINGS) as $key) {
            $values[$key] = (int) config("partners.{$key}");
        }

        try {
            $stored = Cache::rememberForever(self::CACHE_KEY, fn () => Schema::hasTable('platform_settings')
                ? DB::table('platform_settings')->where('key', 'like', 'partners.%')->pluck('value', 'key')->all()
                : []);

            foreach ($stored as $key => $value) {
                $name = substr((string) $key, strlen('partners.'));

                if (array_key_exists($name, self::SETTINGS)) {
                    $values[$name] = (int) json_decode((string) $value, true);
                }
            }
        } catch (Throwable $e) {
            // The defaults: a settings read must never take a checkout down.
            report($e);
        }

        return $this->values = $values;
    }

    public function get(string $key): int
    {
        return $this->settings()[$key];
    }

    /** The rate a seller may choose, as whole percent. @return array{min: int, max: int, default: int} */
    public function rates(): array
    {
        $s = $this->settings();

        return ['min' => $s['min_rate'], 'max' => $s['max_rate'], 'default' => min($s['max_rate'], max($s['min_rate'], $s['default_rate']))];
    }

    /**
     * The owner changes the program's settings, audited.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function update(array $input, string $reason, User $actor): void
    {
        $current = $this->settings();
        $next = $current;

        foreach (self::SETTINGS as $key => [$min, $max]) {
            if (! array_key_exists($key, $input)) {
                continue;
            }

            $value = filter_var($input[$key], FILTER_VALIDATE_INT);

            if ($value === false || $value < $min || $value > $max) {
                throw ValidationException::withMessages([$key => "Must be a whole number from {$min} to {$max}."]);
            }

            $next[$key] = $value;
        }

        if ($next['min_rate'] > $next['max_rate']) {
            throw ValidationException::withMessages(['min_rate' => 'The lowest rate cannot be above the highest.']);
        }

        if ($next['default_rate'] < $next['min_rate'] || $next['default_rate'] > $next['max_rate']) {
            throw ValidationException::withMessages(['default_rate' => 'The default rate must be between the lowest and the highest.']);
        }

        $changed = array_diff_assoc($next, $current);

        if ($changed === []) {
            return;
        }

        AdminAudit::transaction(function () use ($changed, $current, $reason, $actor) {
            foreach ($changed as $key => $value) {
                DB::table('platform_settings')->updateOrInsert(
                    ['key' => "partners.{$key}"],
                    ['value' => json_encode($value), 'updated_by' => $actor->id, 'updated_at' => now()],
                );
            }

            AdminAudit::record('partners.settings', null, array_intersect_key($current, $changed), $changed, $reason, $actor);
        });

        $this->flush();
    }

    public function flush(): void
    {
        $this->values = null;
        Cache::forget(self::CACHE_KEY);
    }
}
