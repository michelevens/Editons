<?php

namespace App\Billing;

use App\Editorial\EditorialAgent;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * What a plan allows.
 *
 * One place rather than a check per controller. The prototype gates features
 * in the client, which tells an honest user what to expect and stops nobody;
 * these are the server-side versions, and the client's version of the same
 * rules is a hint about what will happen, not the control.
 *
 * Every refusal names the plan that would allow it. "Not available on your
 * plan" leaves somebody guessing what to buy.
 */
class Entitlements
{
    public function __construct(
        private readonly CreditLedger $ledger,
        private readonly MonthlyAllowance $allowance,
    ) {}

    public function tier(User $user): int
    {
        return (int) ($user->planLimits()['tier'] ?? 0);
    }

    public function planName(User $user): string
    {
        return (string) ($user->planLimits()['name'] ?? 'Free');
    }

    /** May this account run this agent, on its plan? */
    public function canRunAgent(User $user, EditorialAgent $agent): bool
    {
        return $this->tier($user) >= $agent->minimumTier();
    }

    /**
     * Refuse an agent the plan does not include.
     *
     * 422 with the field the client submitted, so the message appears against
     * the control that was used rather than as a bare toast.
     *
     * @throws ValidationException
     */
    public function assertCanRunAgent(User $user, EditorialAgent $agent): void
    {
        if ($this->canRunAgent($user, $agent)) {
            return;
        }

        $needed = $this->cheapestPlanWithTier($agent->minimumTier());

        throw ValidationException::withMessages([
            'agent' => "{$agent->label()} is part of the {$needed['name']} plan. "
                ."You are on {$this->planName($user)}.",
        ]);
    }

    /**
     * Refuse an export format the plan does not include.
     *
     * @throws ValidationException
     */
    public function assertCanExport(User $user, string $format): void
    {
        if ($user->canExportFormat($format)) {
            return;
        }

        $needed = $this->cheapestPlanWithFormat($format);

        $message = $needed
            ? "That format is part of the {$needed['name']} plan. You are on {$this->planName($user)}."
            : 'That format is not available.';

        throw ValidationException::withMessages(['format' => $message]);
    }

    /**
     * Refuse an action the balance will not cover.
     *
     * Checked before the work starts, so an author is told up front rather
     * than watching a review begin and fail. The spend itself re-checks under
     * a lock, because this check and the debit are not atomic together.
     *
     * @throws ValidationException
     */
    public function assertCanAfford(User $user, string $label): void
    {
        // Before the balance is judged. An account whose allowance has not
        // been granted yet is not out of credits, and refusing it would be
        // wrong rather than merely unhelpful.
        $this->allowance->ensureGranted($user);

        $cost = CreditLedger::costOf($label);
        $balance = $this->ledger->balanceFor($user);

        if ($balance >= $cost) {
            return;
        }

        throw ValidationException::withMessages([
            'credits' => "That costs {$cost} credits and you have {$balance}. "
                .'Top up, or upgrade for a larger monthly allowance.',
        ]);
    }

    /**
     * What the credit strip across the studio shows.
     *
     * @return array<string, mixed>
     */
    public function summaryFor(User $user): array
    {
        $this->allowance->ensureGranted($user);

        $limits = $user->planLimits();
        $balance = $this->ledger->balanceFor($user);
        $monthly = (int) ($limits['credits'] ?? 0);

        return [
            'plan' => $user->plan,
            'plan_name' => $limits['name'] ?? 'Free',
            'tier' => $this->tier($user),
            'credits' => $balance,
            'monthly_credits' => $monthly,
            // For the little bar in the account menu. Guarded: a free plan
            // with no allowance would divide by zero.
            'credits_pct' => $monthly > 0
                ? max(0, min(100, (int) round($balance / $monthly * 100)))
                : 0,
            'low' => $monthly > 0 && $balance < $monthly * 0.15,
            'costs' => config('billing.costs'),
        ];
    }

    /** @return array<string, mixed> */
    private function cheapestPlanWithTier(int $tier): array
    {
        $plans = collect(config('billing.plans'))
            ->filter(fn (array $plan) => ($plan['tier'] ?? 0) >= $tier)
            ->sortBy('price');

        return $plans->first() ?? config('billing.plans.studio');
    }

    /** @return array<string, mixed>|null */
    private function cheapestPlanWithFormat(string $format): ?array
    {
        return collect(config('billing.plans'))
            ->filter(fn (array $plan) => in_array($format, $plan['formats'] ?? [], true))
            ->sortBy('price')
            ->first();
    }
}
