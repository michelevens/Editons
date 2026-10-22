<?php

namespace App\Observers;

use App\Billing\CreditLedger;
use App\Mail\Billing\CreditsLowMail;
use App\Mail\Notify;
use App\Models\CreditTransaction;
use App\Models\User;

/**
 * Warns an author when a spend takes their AI credits below the line.
 *
 * "Below the line" is the same 15% of the monthly allowance at which the
 * account menu's credit bar turns amber (Entitlements::summaryFor), so the
 * email and the screen never disagree about what "low" means.
 *
 * Once per calendar month: users.credits_low_notified_at records the last
 * warning, and a month's allowance resets the question.
 */
class CreditTransactionObserver
{
    public function created(CreditTransaction $transaction): void
    {
        if ($transaction->kind !== 'spend' || $transaction->amount >= 0) {
            return;
        }

        $user = User::find($transaction->user_id);

        if (! $user) {
            return;
        }

        $monthly = (int) ($user->planLimits()['credits'] ?? 0);

        if ($monthly <= 0) {
            return;
        }

        $line = $monthly * CreditsLowMail::THRESHOLD;
        $after = app(CreditLedger::class)->balanceFor($user);
        $before = $after - $transaction->amount; // amount is negative

        // Only the spend that crosses the line, not every spend below it.
        if (! ($before >= $line && $after < $line)) {
            return;
        }

        if ($user->credits_low_notified_at?->isSameMonth(now())) {
            return;
        }

        $user->forceFill(['credits_low_notified_at' => now()])->saveQuietly();

        Notify::creditsLow($user, max(0, $after), $monthly);
    }
}
