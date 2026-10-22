<?php

namespace App\Mail\Billing;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Mail\Support\Money;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;

/** A trial ends soon (Stripe sends customer.subscription.trial_will_end three days out). */
class TrialEndingMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'billing';

    public function __construct(
        public User $user,
        public string $planName,
        public CarbonInterface $trialEndsAt,
        public ?int $priceCents = null,
        public string $currency = 'USD',
        public bool $hasPaymentMethod = false,
    ) {}

    public function account(): ?User
    {
        return $this->user;
    }

    public function letter(): Letter
    {
        $days = max(1, (int) ceil(now()->diffInHours($this->trialEndsAt, false) / 24));
        $date = $this->trialEndsAt->translatedFormat('F j');
        $price = $this->priceCents !== null ? Money::format($this->priceCents, $this->currency) : null;

        return Letter::make(trans_choice('{1} Your :plan trial ends tomorrow|[2,*] Your :plan trial ends in :count days', $days, ['plan' => $this->planName, 'count' => $days]))
            ->preheader($this->hasPaymentMethod
                ? __('Nothing to do: :plan continues on :date:price.', ['plan' => $this->planName, 'date' => $date, 'price' => $price ? ' '.__('at :price a month', ['price' => $price]) : ''])
                : __('Add a payment method by :date to keep :plan.', ['plan' => $this->planName, 'date' => $date]))
            ->subtitle(__('Trial ending'))
            ->eyebrow(__('Ends :date', ['date' => $date]))
            ->severity('heads')
            ->headline(__('Your trial ends on :date', ['date' => $date]))
            ->lede(self::greet($this->user->name, $this->hasPaymentMethod
                ? __('your :plan trial ends soon and your subscription will start automatically. There is nothing you need to do.', ['plan' => $this->planName])
                : __('your :plan trial ends soon. Add a payment method to keep it; otherwise your account moves to the Free plan and your books stay just as they are.', ['plan' => $this->planName])))
            ->facts([
                __('Plan') => $this->planName,
                __('Trial ends') => $this->trialEndsAt->translatedFormat('F j, Y'),
                __('Then') => $price ? __(':price a month', ['price' => $price]) : null,
                __('Payment method') => $this->hasPaymentMethod ? __('On file') : __('Not added yet'),
            ])
            ->cta($this->hasPaymentMethod ? __('Review your plan') : __('Add a payment method'), route('billing.show'))
            ->reason(__('Billing emails are always sent: they are how you hear about a charge before it happens.'));
    }
}
