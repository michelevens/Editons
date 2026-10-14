<?php

namespace App\Billing;

use App\Models\User;

/**
 * The credits a plan includes each month.
 *
 * Nothing granted them before this: an account had a plan that promised 1,000
 * credits a month and a balance of zero, so every AI action was refused the
 * moment plan enforcement went in. The plan table was describing something
 * that never happened.
 *
 * Granted on demand rather than by a scheduled job. A cron that has not run
 * yet is indistinguishable from a bug to the author sitting in front of an
 * empty balance, and the reference key makes the grant safe to attempt as
 * often as it likes.
 */
class MonthlyAllowance
{
    public function __construct(private readonly CreditLedger $ledger) {}

    /**
     * Give this account its allowance if the current period has not been
     * granted yet.
     *
     * Idempotent twice over: the ledger refuses a duplicate
     * `external_reference`, and `credits_granted_at` short-circuits the
     * common case without a query against the ledger.
     */
    public function ensureGranted(User $user): void
    {
        $monthly = (int) ($user->planLimits()['credits'] ?? 0);

        if ($monthly <= 0) {
            return;
        }

        $period = now()->format('Y-m');

        // Already granted for this period.
        if ($user->credits_granted_at?->format('Y-m') === $period) {
            return;
        }

        $this->ledger->grant(
            user: $user,
            amount: $monthly,
            label: 'monthly_allowance',
            // One grant per account per calendar month, whatever happens
            // upstream. A retried request cannot double an allowance.
            reference: "allowance:{$user->id}:{$period}",
        );

        // forceFill: when the allowance was last granted is the server's
        // record of what it did, never something a client sets.
        $user->forceFill(['credits_granted_at' => now()])->save();
    }
}
