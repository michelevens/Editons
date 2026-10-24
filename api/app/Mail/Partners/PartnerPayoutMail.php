<?php

namespace App\Mail\Partners;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Mail\Support\Money;
use App\Models\Partner;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Écrivain recorded a payout to a partner. Billing: a record of money paid,
 * always sent.
 */
class PartnerPayoutMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'billing';

    public function __construct(public User $user, public Partner $partner, public Payout $payout, public int $balanceCents) {}

    public function account(): ?User
    {
        return $this->user;
    }

    public function letter(): Letter
    {
        $brand = config('mail.ecrivain.brand');
        $amount = Money::format($this->payout->amount_cents, $this->payout->currency);

        return Letter::make(__('Partner payout: :amount', ['amount' => $amount]))
            ->preheader(__(':amount was sent to you by :method.', ['amount' => $amount, 'method' => Payout::METHODS[$this->payout->method] ?? $this->payout->method]))
            ->subtitle(__(':brand Partners', ['brand' => $brand]))
            ->eyebrow(__('Payout'))
            ->severity('confirmed')
            ->headline(__(':amount is on its way', ['amount' => $amount]))
            ->lede(self::greet($this->user->name, __('we have paid your partner commissions.')))
            ->facts([
                __('Amount') => $amount,
                __('Method') => Payout::METHODS[$this->payout->method] ?? $this->payout->method,
                __('Reference') => $this->payout->reference,
                __('Paid on') => $this->payout->paid_on?->translatedFormat('F j, Y'),
                __('Balance after this payout') => Money::format($this->balanceCents, $this->payout->currency),
            ])
            ->cta(__('See your payouts'), route('partners.dashboard', ['tab' => 'payouts']))
            ->reason(__('You received this because you are an :brand Partner. Keep it with your records.', ['brand' => $brand]));
    }
}
