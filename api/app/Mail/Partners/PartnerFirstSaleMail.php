<?php

namespace App\Mail\Partners;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Mail\Support\Money;
use App\Models\Partner;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A partner's first commission. Only the first: after that the dashboard
 * and the monthly statement say it, not an email per sale. The buyer is
 * never named.
 */
class PartnerFirstSaleMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'partners';

    public function __construct(
        public User $user,
        public Partner $partner,
        public string $title,
        public int $commissionCents,
        public string $currency,
        public CarbonInterface $holdUntil,
    ) {}

    public function account(): ?User
    {
        return $this->user;
    }

    public function letter(): Letter
    {
        $brand = config('mail.ecrivain.brand');
        $amount = Money::format($this->commissionCents, $this->currency);

        return Letter::make(__('Your first partner sale: “:title”', ['title' => $this->title]))
            ->preheader(__(':amount commission, payable after the hold.', ['amount' => $amount]))
            ->subtitle(__(':brand Partners', ['brand' => $brand]))
            ->eyebrow(__('First sale'))
            ->severity('confirmed')
            ->headline(__('Somebody bought a book through you'))
            ->lede(self::greet($this->user->name, __('a reader bought “:title” through your link.', ['title' => $this->title])))
            ->stats([
                ['label' => __('Your commission'), 'value' => $amount, 'tone' => 'success'],
            ])
            ->facts([
                __('Book') => $this->title,
                __('Payable from') => $this->holdUntil->translatedFormat('F j, Y'),
            ])
            ->paragraph(__('Commissions are held for the refund window, then added to your balance. From now on your sales are in your dashboard and in a monthly statement.'))
            ->cta(__('See your commissions'), route('partners.dashboard', ['tab' => 'commissions']))
            ->reason(__('You received this because you are an :brand Partner. You can switch partner emails off in your preferences.', ['brand' => $brand]));
    }
}
