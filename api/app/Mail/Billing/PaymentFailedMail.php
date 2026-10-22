<?php

namespace App\Mail\Billing;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Mail\Support\Money;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;

/** A subscription charge was declined. */
class PaymentFailedMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'billing';

    public function __construct(
        public User $user,
        public string $planName,
        public int $amountCents,
        public string $currency,
        public ?CarbonInterface $nextAttemptAt = null,
        public ?CarbonInterface $accessEndsAt = null,
        public ?string $updateUrl = null,
        public ?string $declineReason = null,
        public ?string $paymentMethod = null,
    ) {}

    public function account(): ?User
    {
        return $this->user;
    }

    public function letter(): Letter
    {
        $amount = Money::format($this->amountCents, $this->currency);

        $letter = Letter::make(__('Your payment of :amount did not go through', ['amount' => $amount]))
            ->preheader(__('Update your card to keep :plan. Your books are safe either way.', ['plan' => $this->planName]))
            ->subtitle(__('Payment failed'))
            ->eyebrow(__('Action needed · Billing'))
            ->severity('urgent')
            ->headline(__('We could not take your payment'))
            ->lede(self::greet($this->user->name, __('the payment for your :plan plan was declined. Updating your card takes a minute and keeps everything running.', ['plan' => $this->planName])))
            ->facts([
                __('Plan') => $this->planName,
                __('Amount due') => $amount,
                __('Card') => $this->paymentMethod,
                __('Reason given') => $this->declineReason,
                __('Next attempt') => $this->nextAttemptAt?->translatedFormat('F j, Y'),
            ])
            ->cta(__('Update your card'), $this->updateUrl ?? route('billing.show'));

        if ($this->accessEndsAt) {
            $letter->callout('danger', __('If the payment still fails by then, your account moves to the Free plan. Your books and manuscripts are kept — nothing is deleted.'), __(':plan features pause on :date', [
                'plan' => $this->planName,
                'date' => $this->accessEndsAt->translatedFormat('F j'),
            ]));
        } else {
            $letter->callout('warning', __('Your books and manuscripts are safe either way — nothing is deleted when a payment fails.'));
        }

        return $letter->reason(__('Billing emails are always sent: they are how you hear about a problem before it becomes one.'));
    }
}
