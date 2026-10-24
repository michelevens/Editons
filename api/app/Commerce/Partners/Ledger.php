<?php

namespace App\Commerce\Partners;

use App\Models\Partner;
use App\Models\PartnerCommission;
use App\Models\Payout;
use App\Models\User;
use App\Platform\AdminAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A partner's money: what is pending, payable and paid, per currency.
 *
 *   pending   sale commissions still in their hold
 *   earned    approved and paid rows, adjustments and forfeits included
 *   paid      partner payouts recorded (the `payouts` table, kind partner:
 *             the same ledger as authors' royalty payouts)
 *   balance   earned − paid. Negative after a refund of something already
 *             paid: it comes out of the next earnings.
 *
 * Payouts are recorded by the owner after paying the partner outside
 * Écrivain (PayPal, MonCash, a bank transfer) — no money moves through the
 * app. A payout needs a balance of at least the minimum (25.00), is never
 * more than the balance, and is refused while a suspended partner's balance
 * is frozen. Rows it covers are marked paid, oldest first.
 *
 * Money is never added across currencies.
 */
final class Ledger
{
    public function __construct(private readonly Program $program) {}

    /** @return array<string, array{currency: string, pending_cents: int, earned_cents: int, paid_cents: int, balance_cents: int}> */
    public function balances(Partner $partner): array
    {
        $rows = PartnerCommission::where('partner_id', $partner->id)
            ->select('currency', 'status', DB::raw('sum(amount_cents - reversed_cents) as total'))
            ->groupBy('currency', 'status')
            ->get();

        $paid = Payout::where('kind', 'partner')->where('user_id', $partner->user_id)
            ->select('currency', DB::raw('sum(amount_cents) as total'))
            ->groupBy('currency')
            ->pluck('total', 'currency');

        $out = [];

        foreach ($rows->pluck('currency')->merge($paid->keys())->unique() as $currency) {
            $of = fn (array $statuses) => (int) $rows->where('currency', $currency)->whereIn('status', $statuses)->sum('total');
            $earned = $of(['approved', 'paid']);
            $paidCents = (int) ($paid[$currency] ?? 0);

            $out[$currency] = [
                'currency' => $currency,
                'pending_cents' => $of(['pending']),
                'earned_cents' => $earned,
                'paid_cents' => $paidCents,
                'balance_cents' => $earned - $paidCents,
            ];
        }

        return $out;
    }

    public function balance(Partner $partner, string $currency): int
    {
        return $this->balances($partner)[$currency]['balance_cents'] ?? 0;
    }

    /** Can a payout be recorded now, and if not, why? @return array{eligible: bool, reason: ?string} */
    public function eligibility(Partner $partner, string $currency): array
    {
        $balance = $this->balance($partner, $currency);
        $minimum = $this->program->get('payout_minimum_cents');

        $reason = match (true) {
            $partner->balanceFrozen() => 'The partner is suspended and the balance is frozen.',
            $balance < max(1, $minimum) => 'The balance is below the '.number_format($minimum / 100, 2).' '.strtoupper($currency).' minimum.',
            ! $partner->payout_method || ! $partner->payout_details => 'The partner has not given payout details yet.',
            default => null,
        };

        return ['eligible' => $reason === null, 'reason' => $reason];
    }

    /**
     * Partners a payout could be recorded for now, per currency.
     *
     * @return list<array<string, mixed>>
     */
    public function eligible(): array
    {
        $partnerIds = PartnerCommission::whereIn('status', ['approved'])->distinct()->pluck('partner_id');

        return Partner::with('user:id,name,email')->whereIn('id', $partnerIds)->get()
            ->flatMap(fn (Partner $partner) => collect($this->balances($partner))
                ->map(fn (array $b) => $b + ['partner' => $partner] + $this->eligibility($partner, $b['currency'])))
            ->filter(fn (array $row) => $row['balance_cents'] > 0)
            ->sortByDesc('balance_cents')
            ->values()
            ->all();
    }

    /**
     * The owner records a payout made outside Écrivain.
     *
     * @param  array{currency: string, amount_cents: int, method: string, reference: ?string, paid_on: string, note: ?string}  $data
     *
     * @throws ValidationException
     */
    public function recordPayout(Partner $partner, array $data, string $reason, User $actor): Payout
    {
        $payout = AdminAudit::transaction(function () use ($partner, $data, $reason, $actor) {
            // Under the partner's row lock, so two payouts recorded at once
            // cannot both fit under the same balance.
            $partner = Partner::whereKey($partner->id)->lockForUpdate()->first();
            $currency = $data['currency'];
            $balance = $this->balance($partner, $currency);
            $minimum = $this->program->get('payout_minimum_cents');

            $refuse = fn (string $message) => throw ValidationException::withMessages(['amount' => $message]);

            if ($partner->balanceFrozen()) {
                $refuse('This partner is suspended: release the balance first, or forfeit it.');
            }

            if ($balance < max(1, $minimum)) {
                $refuse('The balance is '.number_format($balance / 100, 2).' '.strtoupper($currency).', below the '.number_format($minimum / 100, 2).' minimum.');
            }

            if ($data['amount_cents'] > $balance) {
                $refuse('That is more than the '.number_format($balance / 100, 2).' '.strtoupper($currency).' this partner is owed.');
            }

            $payout = Payout::create([
                'kind' => 'partner',
                'user_id' => $partner->user_id,
                'currency' => $currency,
                'amount_cents' => $data['amount_cents'],
                'method' => $data['method'],
                'reference' => $data['reference'] ?: null,
                'paid_on' => Carbon::parse($data['paid_on'])->toDateString(),
                'note' => $data['note'] ?: null,
                'recorded_by' => $actor->id,
            ]);

            $this->settle($partner, $payout);

            AdminAudit::record('partner.payout_recorded', $partner, ['balance_cents' => $balance], [
                'payout_id' => $payout->id,
                'amount_cents' => $payout->amount_cents,
                'currency' => $currency,
                'method' => $payout->method,
                'reference' => $payout->reference,
                'paid_on' => $payout->paid_on->toDateString(),
                'balance_cents' => $balance - $payout->amount_cents,
            ], $reason, $actor);

            return $payout;
        });

        PartnerNotices::payoutRecorded($partner->fresh('user'), $payout);

        return $payout;
    }

    /**
     * Staff take a suspended partner's balance, with a reason: a negative
     * `forfeit` row per currency brings it to zero.
     */
    public function forfeit(Partner $partner, string $reason, User $actor): void
    {
        AdminAudit::transaction(function () use ($partner, $reason, $actor) {
            $partner = Partner::whereKey($partner->id)->lockForUpdate()->first();

            if (! $partner->isSuspended()) {
                throw ValidationException::withMessages(['reason' => 'Only a suspended partner\'s balance can be forfeited.']);
            }

            $taken = [];

            foreach ($this->balances($partner) as $currency => $b) {
                // Pending commissions are forfeited too: nothing of a
                // suspended partner's is still waiting to be paid.
                PartnerCommission::where('partner_id', $partner->id)->where('currency', $currency)->where('status', 'pending')
                    ->update(['status' => 'approved', 'approved_at' => now(), 'updated_at' => now()]);

                $amount = $b['balance_cents'] + $b['pending_cents'];

                if ($amount <= 0) {
                    continue;
                }

                $row = new PartnerCommission;
                $row->forceFill([
                    'partner_id' => $partner->id,
                    'currency' => $currency,
                    'kind' => 'forfeit',
                    'amount_cents' => -$amount,
                    'status' => 'approved',
                    'approved_at' => now(),
                    'note' => trim($reason),
                ])->save();

                $taken[$currency] = $amount;
            }

            if ($taken === []) {
                throw ValidationException::withMessages(['reason' => 'There is no balance to forfeit.']);
            }

            AdminAudit::record('partner.balance_forfeited', $partner, null, ['forfeited_cents' => $taken], $reason, $actor);
        });
    }

    /** Staff let a suspended partner's balance be paid. */
    public function release(Partner $partner, string $reason, User $actor): void
    {
        if (! $partner->balanceFrozen()) {
            throw ValidationException::withMessages(['reason' => 'This balance is not frozen.']);
        }

        AdminAudit::transaction(function () use ($partner, $reason, $actor) {
            $partner->forceFill(['balance_released_at' => now()])->save();

            AdminAudit::record('partner.balance_released', $partner, ['frozen' => true], ['frozen' => false, 'balances' => collect($this->balances($partner))->pluck('balance_cents', 'currency')->all()], $reason, $actor);
        });
    }

    /** Recent payouts to partners, for the console. @return Collection<int, Payout> */
    public function payouts(?Partner $partner = null, int $limit = 100): Collection
    {
        return Payout::where('kind', 'partner')
            ->when($partner, fn ($q) => $q->where('user_id', $partner->user_id))
            ->with(['user:id,name,email', 'recorder:id,name'])
            ->latest('paid_on')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /** Mark the approved rows a payout covers as paid, oldest first; refunds owed first. */
    private function settle(Partner $partner, Payout $payout): void
    {
        $rows = PartnerCommission::where('partner_id', $partner->id)
            ->where('currency', $payout->currency)
            ->where('status', 'approved')
            ->orderByRaw('amount_cents - reversed_cents < 0 desc')
            ->orderBy('approved_at')
            ->orderBy('id')
            ->get();

        $covered = 0;

        foreach ($rows as $row) {
            if ($covered + $row->net() > $payout->amount_cents) {
                break;
            }

            $covered += $row->net();
            $row->forceFill(['status' => 'paid', 'paid_at' => now(), 'payout_id' => $payout->id])->save();
        }
    }
}
