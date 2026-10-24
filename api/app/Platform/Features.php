<?php

namespace App\Platform;

use App\Commerce\Commerce;
use App\Covers\CoverArtist;
use App\Covers\CoverConcepts;
use App\Models\FeatureOverride;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Feature flags for incomplete or risky modules (Credentik's Features
 * registry, adapted to accounts rather than agencies).
 *
 * Keys are STABLE: they are stored in feature_flags and feature_overrides.
 * Never rename one; add a key and migrate instead.
 *
 * What decides, in order:
 *
 *  1. the module's own prerequisite. A flag never turns on what cannot
 *     work: the storefront needs Commerce::enabled() (Stripe, or the fake
 *     gateway off production), AI covers need a configured provider. Those
 *     stay config, as they were; this sits on top of them;
 *  2. an override for this account, forced on or off, with a reason;
 *  3. the platform-wide switch set in the console;
 *  4. the default below.
 *
 * Every change is written to the admin audit log in the same transaction.
 * The resolved map is shared with every page (`features`), so a module
 * switched off disappears from the sidebar, the More sheet and the account
 * menu as well as refusing at its entry point.
 */
final class Features
{
    /** key => label, what it gates, default, prerequisite */
    public const CATALOG = [
        'storefront' => [
            'label' => 'Direct sales (storefront)',
            'description' => 'Selling a book from its own store page: products, checkout, the Storefront sales screen.',
            'default' => true,
            'requires' => 'commerce',
        ],
        'ai_cover' => [
            'label' => 'AI cover concepts and artwork',
            'description' => 'The two AI buttons in Cover Studio. Designing a cover by hand is not affected.',
            'default' => true,
            'requires' => 'ai_cover',
        ],
        'suggest_mode' => [
            'label' => 'Suggest mode (tracked changes)',
            'description' => 'The Suggest switch in the editor. Changes already suggested can still be accepted or rejected.',
            'default' => true,
            'requires' => null,
        ],
        'preview' => [
            'label' => 'Book preview',
            'description' => 'The full-screen paginated preview opened from Design and Export.',
            'default' => true,
            'requires' => null,
        ],
        'inbox' => [
            'label' => 'Inbox',
            'description' => 'The account inbox and its tab. Emails are not affected.',
            'default' => true,
            'requires' => null,
        ],
        'support' => [
            'label' => 'Help and support requests',
            'description' => 'Help in the account menu and the More sheet, and sending a request to Ecrivain support.',
            'default' => true,
            'requires' => null,
        ],
        'audiobook' => [
            'label' => 'Audiobook Studio (coming soon)',
            'description' => 'The coming-soon entry in the book sidebar. Off hides it.',
            'default' => true,
            'requires' => null,
        ],
        'course_builder' => [
            'label' => 'Course Builder (coming soon)',
            'description' => 'The coming-soon entry in the book sidebar. Off hides it.',
            'default' => true,
            'requires' => null,
        ],
        'author_site' => [
            'label' => 'Author Website (coming soon)',
            'description' => 'The coming-soon entry in the book sidebar. Off hides it.',
            'default' => true,
            'requires' => null,
        ],
    ];

    private const CACHE_KEY = 'platform.feature-flags';

    /** @var array<string, array{enabled: bool, reason: ?string, updated_by: ?int, updated_at: ?string}>|null */
    private ?array $globals = null;

    /** @var array<int, array<string, bool>> user id => overrides */
    private array $overrides = [];

    /** Is $key on for $user (or for a visitor, without one)? */
    public static function enabled(string $key, ?User $user = null): bool
    {
        return app(self::class)->explain($key, $user)['enabled'];
    }

    /**
     * The answer and where it came from: unavailable | override | global | default.
     *
     * @return array{enabled: bool, source: string}
     */
    public function explain(string $key, ?User $user = null): array
    {
        $meta = self::CATALOG[$key] ?? throw new \InvalidArgumentException("Unknown feature [{$key}].");

        if ($meta['requires'] && ! self::prerequisite($meta['requires'])) {
            return ['enabled' => false, 'source' => 'unavailable'];
        }

        if ($user && array_key_exists($key, $own = $this->overridesFor($user))) {
            return ['enabled' => $own[$key], 'source' => 'override'];
        }

        $global = $this->globals()[$key] ?? null;

        if ($global !== null) {
            return ['enabled' => $global['enabled'], 'source' => 'global'];
        }

        return ['enabled' => (bool) $meta['default'], 'source' => 'default'];
    }

    /**
     * Every key, resolved for one person: what the pages are given.
     *
     * @return array<string, bool>
     */
    public function resolve(?User $user): array
    {
        $out = [];

        foreach (array_keys(self::CATALOG) as $key) {
            $out[$key] = $this->explain($key, $user)['enabled'];
        }

        return $out;
    }

    /** @return array<string, array{enabled: bool, reason: ?string, updated_by: ?int, updated_at: ?string}> */
    public function globals(): array
    {
        if ($this->globals !== null) {
            return $this->globals;
        }

        try {
            $rows = Cache::rememberForever(self::CACHE_KEY, fn () => Schema::hasTable('feature_flags')
                ? DB::table('feature_flags')->get()->keyBy('key')->map(fn ($row) => (array) $row)->all()
                : []);
        } catch (Throwable $e) {
            // Defaults only: a flag read must never take a page down.
            report($e);
            $rows = [];
        }

        return $this->globals = array_map(fn (array $row) => [
            'enabled' => (bool) $row['enabled'],
            'reason' => $row['reason'] ?? null,
            'updated_by' => isset($row['updated_by']) ? (int) $row['updated_by'] : null,
            'updated_at' => $row['updated_at'] ?? null,
        ], array_intersect_key($rows, self::CATALOG));
    }

    /** @return array<string, bool> */
    public function overridesFor(User $user): array
    {
        if (! array_key_exists($user->id, $this->overrides)) {
            try {
                $this->overrides[$user->id] = FeatureOverride::where('user_id', $user->id)
                    ->pluck('enabled', 'feature')
                    ->map(fn ($on) => (bool) $on)
                    ->all();
            } catch (Throwable $e) {
                report($e);
                $this->overrides[$user->id] = [];
            }
        }

        return $this->overrides[$user->id];
    }

    /**
     * Switch a module for everybody. Null goes back to the default.
     *
     * @throws ValidationException
     */
    public function setGlobal(string $key, ?bool $enabled, string $reason, User $actor): void
    {
        self::assertKnown($key);

        $before = $this->globals()[$key]['enabled'] ?? null;

        if ($before === $enabled) {
            return;
        }

        AdminAudit::transaction(function () use ($key, $enabled, $before, $reason, $actor) {
            $enabled === null
                ? DB::table('feature_flags')->where('key', $key)->delete()
                : DB::table('feature_flags')->updateOrInsert(
                    ['key' => $key],
                    ['enabled' => $enabled, 'reason' => trim($reason), 'updated_by' => $actor->id, 'updated_at' => now()],
                );

            AdminAudit::record('feature.global', null, ['key' => $key, 'enabled' => $before], ['key' => $key, 'enabled' => $enabled], $reason, $actor);
        });

        $this->flush();
    }

    /**
     * Force a module on or off for one account. Null removes the override.
     *
     * @throws ValidationException
     */
    public function setOverride(User $user, string $key, ?bool $enabled, string $reason, User $actor): void
    {
        self::assertKnown($key);

        $before = $this->overridesFor($user)[$key] ?? null;

        if ($before === $enabled) {
            return;
        }

        AdminAudit::transaction(function () use ($user, $key, $enabled, $before, $reason, $actor) {
            $enabled === null
                ? FeatureOverride::where('user_id', $user->id)->where('feature', $key)->delete()
                : FeatureOverride::updateOrCreate(
                    ['user_id' => $user->id, 'feature' => $key],
                    ['enabled' => $enabled, 'reason' => trim($reason), 'set_by' => $actor->id],
                );

            AdminAudit::record('feature.override', $user, ['feature' => $key, 'enabled' => $before], ['feature' => $key, 'enabled' => $enabled], $reason, $actor);
        });

        unset($this->overrides[$user->id]);
    }

    public function flush(): void
    {
        $this->globals = null;
        $this->overrides = [];
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<int, array{key: string, label: string, description: string, default: bool}> */
    public static function catalog(): array
    {
        $out = [];

        foreach (self::CATALOG as $key => $meta) {
            $out[] = ['key' => $key, 'label' => $meta['label'], 'description' => $meta['description'], 'default' => $meta['default'], 'available' => ! $meta['requires'] || self::prerequisite($meta['requires'])];
        }

        return $out;
    }

    /** What has to be configured before a module can be on at all. */
    private static function prerequisite(string $name): bool
    {
        return match ($name) {
            'commerce' => Commerce::enabled(),
            'ai_cover' => CoverConcepts::available() || CoverArtist::available(),
            default => true,
        };
    }

    /** @throws ValidationException */
    private static function assertKnown(string $key): void
    {
        if (! isset(self::CATALOG[$key])) {
            throw ValidationException::withMessages(['key' => 'Unknown feature.']);
        }
    }
}
