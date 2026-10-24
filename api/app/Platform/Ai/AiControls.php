<?php

namespace App\Platform\Ai;

use App\Models\AiUsage;
use App\Models\User;
use App\Platform\AdminAudit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The AI kill switches and spend caps (after Credentik's AiGovernance).
 *
 * One switch for all of AI and one per feature, each with the reason it was
 * thrown. A paused feature refuses before anything is charged or sent to a
 * provider, with a sentence the author can read — "AI reviews are paused by
 * Ecrivain — try again later" — rather than an error.
 *
 * A monthly cap (per account, or the platform default) stops an account
 * whose AI calls this calendar month have cost that much already. Only
 * known costs count: an unpriced model cannot be capped, which the console
 * says beside the cap.
 *
 * A container singleton, so a test or a worker never reads another's
 * switches from a static.
 */
final class AiControls
{
    public const ALL = 'all';

    /** feature => [label, what the author is told when it is paused] */
    public const FEATURES = [
        'editorial_review' => ['Editorial reviews', 'AI reviews are paused by Ecrivain — try again later.'],
        'cover_concepts' => ['Cover concepts', 'AI cover concepts are paused by Ecrivain — try again later.'],
        'cover_artwork' => ['Cover artwork', 'AI cover artwork is paused by Ecrivain — try again later.'],
        'support_draft' => ['Support reply drafts', 'AI reply drafts are paused.'],
    ];

    private const CACHE_KEY = 'platform.ai-controls';

    private ?array $rows = null;

    /** @return array<string, array{paused: bool, reason: ?string, updated_by: ?int, updated_at: ?string}> */
    public function all(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        try {
            $stored = Cache::rememberForever(self::CACHE_KEY, fn () => Schema::hasTable('ai_controls')
                ? DB::table('ai_controls')->get()->keyBy('key')->map(fn ($row) => (array) $row)->all()
                : []);
        } catch (Throwable $e) {
            // A switch that cannot be read counts as not thrown: credits and
            // the provider's own limits still apply. Reported, because a kill
            // switch that silently stopped working is what this prevents.
            report($e);
            $stored = [];
        }

        $rows = [];

        foreach ([self::ALL, ...array_keys(self::FEATURES)] as $key) {
            $row = $stored[$key] ?? null;
            $rows[$key] = [
                'paused' => (bool) ($row['paused'] ?? false),
                'reason' => $row['reason'] ?? null,
                'updated_by' => isset($row['updated_by']) ? (int) $row['updated_by'] : null,
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }

        return $this->rows = $rows;
    }

    public function paused(string $feature): bool
    {
        $all = $this->all();

        return $all[self::ALL]['paused'] || ($all[$feature]['paused'] ?? false);
    }

    /**
     * Why $user may not use $feature now, or null if they may.
     *
     * Checked before credits are reserved and before any provider is
     * called, so a refusal costs nothing.
     */
    public function refusal(string $feature, ?User $user = null): ?string
    {
        if ($this->paused($feature)) {
            return self::FEATURES[$feature][1] ?? 'This AI feature is paused by Ecrivain — try again later.';
        }

        if ($user && ($cap = $this->capFor($user)) !== null && $this->spentThisMonth($user) >= $cap) {
            return 'Your account has reached its AI limit for this month. It resets on the 1st; write to support if you need more sooner.';
        }

        return null;
    }

    /** Refuse with a 503 and the author's sentence. */
    public function assertAllowed(string $feature, ?User $user = null): void
    {
        if ($message = $this->refusal($feature, $user)) {
            throw new AiPaused($message);
        }
    }

    /** The cap that applies to this account, in USD, or null for none. */
    public function capFor(User $user): ?float
    {
        if ($user->ai_monthly_cap_usd !== null) {
            return (float) $user->ai_monthly_cap_usd;
        }

        $default = config('platform.ai.default_monthly_cap_usd');

        return $default === null ? null : (float) $default;
    }

    /** Known AI cost for this account since the 1st (PlanDraft's AiPolicy::spentThisMonth). */
    public function spentThisMonth(User $user): float
    {
        return (float) AiUsage::where('user_id', $user->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('cost_usd');
    }

    /**
     * Throw or release one switch, audited. A reason is required either way.
     *
     * @throws ValidationException
     */
    public function set(string $key, bool $paused, string $reason, User $actor): void
    {
        if ($key !== self::ALL && ! isset(self::FEATURES[$key])) {
            throw ValidationException::withMessages(['key' => 'Unknown AI feature.']);
        }

        $before = $this->all()[$key];

        if ($before['paused'] === $paused) {
            return;
        }

        AdminAudit::transaction(function () use ($key, $paused, $reason, $actor, $before) {
            DB::table('ai_controls')->updateOrInsert(
                ['key' => $key],
                ['paused' => $paused, 'reason' => trim($reason), 'updated_by' => $actor->id, 'updated_at' => now()],
            );

            AdminAudit::record(
                $paused ? 'ai.paused' : 'ai.resumed',
                null,
                ['key' => $key, 'paused' => $before['paused']],
                ['key' => $key, 'paused' => $paused],
                $reason,
                $actor,
            );
        });

        $this->flush();
    }

    /** Set or clear one account's monthly cap, audited. */
    public function setCap(User $user, ?float $cap, string $reason, User $actor): void
    {
        $before = $user->ai_monthly_cap_usd === null ? null : (float) $user->ai_monthly_cap_usd;

        if ($before === $cap) {
            return;
        }

        AdminAudit::transaction(function () use ($user, $cap, $before, $reason, $actor) {
            $user->forceFill(['ai_monthly_cap_usd' => $cap])->save();
            AdminAudit::record('account.ai_cap', $user, ['ai_monthly_cap_usd' => $before], ['ai_monthly_cap_usd' => $cap], $reason, $actor);
        });
    }

    public function flush(): void
    {
        $this->rows = null;
        Cache::forget(self::CACHE_KEY);
    }

    public static function label(string $key): string
    {
        return $key === self::ALL ? 'All AI' : (self::FEATURES[$key][0] ?? $key);
    }
}
