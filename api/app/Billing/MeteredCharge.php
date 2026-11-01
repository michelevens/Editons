<?php

namespace App\Billing;

use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Credits charged from real usage (ADR — AI research, source-backed
 * fact-checking, cover templates).
 *
 * A web research run cannot be priced before it runs: the model decides
 * how many searches to make and how many pages to read. So the most a run
 * may take is held first, through the ledger's locked spend (two runs at
 * once still cannot overdraw), and once the provider has reported its
 * tokens and searches the run is settled: the unused part of the hold goes
 * back as one refund row pointing at the hold. A failed run releases the
 * whole hold, exactly as a failed review does.
 *
 * The settlement uses the ledger's one-refund-per-spend rule, so a run is
 * settled or released once, never both.
 */
class MeteredCharge
{
    public function __construct(private readonly CreditLedger $ledger) {}

    /** The provider's cost in credits: rounded up, never below the minimum. */
    public static function credits(float $usd): int
    {
        $rate = max(0.0001, (float) config('billing.metered.usd_per_credit', 0.01));

        return max((int) config('billing.metered.minimum', 1), (int) ceil(round($usd / $rate, 6)));
    }

    /** Hold the most this kind of run may take. @throws InsufficientCredits */
    public function hold(User $user, string $label, ?Model $subject = null): CreditTransaction
    {
        return $this->ledger->spend(user: $user, label: $label, subject: $subject);
    }

    /**
     * Settle a hold to what the run used, and return the credits it cost.
     *
     * An unknown cost (an unpriced model) keeps the whole hold: charging a
     * guess below it would be inventing a price, and the hold is the most
     * the author agreed to.
     */
    public function settle(CreditTransaction $hold, ?float $usd): int
    {
        $held = abs((int) $hold->amount);

        if ($usd === null) {
            return $held;
        }

        $used = min($held, self::credits($usd));

        if ($used < $held) {
            DB::transaction(function () use ($hold, $held, $used) {
                if (CreditTransaction::where('reverses_id', $hold->id)->exists()) {
                    return;
                }

                CreditTransaction::create([
                    'user_id' => $hold->user_id,
                    'amount' => $held - $used,
                    'kind' => 'refund',
                    'label' => "Unused hold: {$hold->label}",
                    'reverses_id' => $hold->id,
                ]);
            });
        }

        return $used;
    }

    /** The run failed: everything held goes back. */
    public function release(CreditTransaction $hold, string $reason): void
    {
        $this->ledger->refund($hold, $reason);
    }
}
