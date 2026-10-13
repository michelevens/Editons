<?php

namespace App\Billing;

use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The credit ledger.
 *
 * The balance is the sum of the rows, never a cached column. That costs one
 * aggregate per read and buys the property that matters: the balance and its
 * explanation cannot drift apart, because they are the same data.
 *
 * CLAUDE-NEXT.md section 0: "Create a job record first and debit credits
 * atomically... Refund the credits if it fails."
 */
class CreditLedger
{
    public function balanceFor(User $user): int
    {
        return (int) CreditTransaction::where('user_id', $user->id)->sum('amount');
    }

    /**
     * Spend credits, or fail if the balance will not cover it.
     *
     * The row lock is the whole point. Two reviews started at once would
     * otherwise both read the same balance, both decide it is sufficient, and
     * both debit — letting an account spend credits it does not have. Locking
     * the user row serialises the check and the write.
     *
     * @throws InsufficientCredits
     */
    public function spend(
        User $user,
        string $label,
        ?int $cost = null,
        ?Model $subject = null,
    ): CreditTransaction {
        $cost ??= self::costOf($label);

        return DB::transaction(function () use ($user, $label, $cost, $subject) {
            // Lock the account, not the ledger: this is the row every
            // concurrent spend for this user has in common.
            User::whereKey($user->id)->lockForUpdate()->first();

            $balance = $this->balanceFor($user);

            if ($balance < $cost) {
                throw new InsufficientCredits($cost, $balance);
            }

            return CreditTransaction::create([
                'user_id' => $user->id,
                'amount' => -$cost,
                'kind' => 'spend',
                'label' => $label,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
            ]);
        });
    }

    /**
     * Give back credits for work that failed.
     *
     * Writes a positive row pointing at the original rather than deleting the
     * debit, so the ledger still shows that the attempt was made and paid for.
     * A unique index means a retried failure handler cannot refund twice;
     * calling this again returns the existing refund.
     */
    public function refund(CreditTransaction $spend, ?string $reason = null): CreditTransaction
    {
        if ($spend->kind !== 'spend') {
            throw new \InvalidArgumentException('Only a spend can be refunded.');
        }

        return DB::transaction(function () use ($spend, $reason) {
            $existing = CreditTransaction::where('reverses_id', $spend->id)->first();

            if ($existing) {
                return $existing;
            }

            return CreditTransaction::create([
                'user_id' => $spend->user_id,
                'amount' => abs($spend->amount),
                'kind' => 'refund',
                'label' => $reason ?? "Refund: {$spend->label}",
                'reverses_id' => $spend->id,
            ]);
        });
    }

    /**
     * Grant a plan's monthly credits.
     *
     * Idempotent on `external_reference`, which carries Stripe's invoice ID.
     * Webhooks are delivered at least once, and granting a thousand credits
     * twice because Stripe retried is a real way to lose money.
     */
    public function grant(
        User $user,
        int $amount,
        string $label,
        ?string $reference = null,
        string $kind = 'grant',
    ): CreditTransaction {
        if ($reference) {
            $existing = CreditTransaction::where('external_reference', $reference)->first();

            if ($existing) {
                return $existing;
            }
        }

        return CreditTransaction::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'kind' => $kind,
            'label' => $label,
            'external_reference' => $reference,
        ]);
    }

    /** Would this spend go through right now? */
    public function canAfford(User $user, string $label, ?int $cost = null): bool
    {
        return $this->balanceFor($user) >= ($cost ?? self::costOf($label));
    }

    /** @throws \InvalidArgumentException for an unpriced action */
    public static function costOf(string $label): int
    {
        return config("billing.costs.{$label}")
            ?? throw new \InvalidArgumentException("No credit cost configured for [{$label}].");
    }
}
