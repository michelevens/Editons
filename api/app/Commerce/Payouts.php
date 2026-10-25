<?php

namespace App\Commerce;

use App\Models\Payout;
use App\Models\User;
use App\Platform\AdminAudit;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Recording royalties paid to an author by hand.
 *
 * Stripe Connect is not built, so Écrivain pays nobody automatically: the
 * owner pays an author outside it (a bank transfer, PayPal, MonCash) and
 * records it here, which is what brings the "owed" figure down. A payout is
 * never more than is owed — an overpayment is a typo, and a negative
 * balance would hide the next sale's royalty.
 */
final class Payouts
{
    public function __construct(private readonly PlatformSales $sales) {}

    /**
     * @param  array{currency: string, amount_cents: int, method: string, reference: ?string, paid_on: string, note: ?string}  $data
     */
    public function record(User $seller, array $data, string $reason, User $actor): Payout
    {
        // A house account's sales are the platform's revenue (ADR — house
        // catalog): there is nobody to pay.
        if ($seller->isHouse()) {
            throw ValidationException::withMessages([
                'amount' => 'This is a house publisher account: its sales are platform revenue, and no royalty is paid out.',
            ]);
        }

        return AdminAudit::transaction(function () use ($seller, $data, $reason, $actor) {
            // Under the seller's row lock, so two payouts recorded at once
            // cannot both fit under the same balance.
            User::whereKey($seller->id)->lockForUpdate()->first();

            $owed = $this->sales->owed($seller, $data['currency']);

            if ($data['amount_cents'] > $owed) {
                throw ValidationException::withMessages([
                    'amount' => $owed > 0
                        ? 'That is more than the '.number_format($owed / 100, 2).' '.strtoupper($data['currency']).' this author is owed.'
                        : 'Nothing is owed to this author in '.strtoupper($data['currency']).'.',
                ]);
            }

            $payout = Payout::create([
                'user_id' => $seller->id,
                'currency' => $data['currency'],
                'amount_cents' => $data['amount_cents'],
                'method' => $data['method'],
                'reference' => $data['reference'] ?: null,
                'paid_on' => Carbon::parse($data['paid_on'])->toDateString(),
                'note' => $data['note'] ?: null,
                'recorded_by' => $actor->id,
            ]);

            AdminAudit::record('payout.recorded', $seller, ['owed_cents' => $owed], [
                'payout_id' => $payout->id,
                'amount_cents' => $payout->amount_cents,
                'currency' => $payout->currency,
                'method' => $payout->method,
                'reference' => $payout->reference,
                'paid_on' => $payout->paid_on->toDateString(),
                'owed_cents' => $owed - $payout->amount_cents,
            ], $reason, $actor);

            return $payout;
        });
    }
}
