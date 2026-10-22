<?php

namespace App\Mail\Billing;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Mail\Support\Money;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A subscription payment went through: the first one (subscription
 * started) or a renewal. Amounts are integer minor units, as Stripe sends
 * them.
 */
class SubscriptionReceiptMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'billing';

    public function __construct(
        public User $user,
        public string $planName,
        public int $amountCents,
        public string $currency,
        public CarbonInterface $periodStart,
        public CarbonInterface $periodEnd,
        public ?string $invoiceUrl = null,
        public ?string $invoiceNumber = null,
        public bool $isFirstPayment = false,
        public ?string $paymentMethod = null,
        public ?int $monthlyCredits = null,
        public ?CarbonInterface $paidAt = null,
    ) {}

    public function account(): ?User
    {
        return $this->user;
    }

    public function letter(): Letter
    {
        $brand = config('mail.ecrivain.brand');
        $amount = Money::format($this->amountCents, $this->currency);
        $period = $this->periodStart->translatedFormat('M j').' – '.$this->periodEnd->translatedFormat('M j, Y');
        $paid = ($this->paidAt ?? now())->translatedFormat('F j, Y');

        return Letter::make($this->isFirstPayment
                ? __('Welcome to :plan — your receipt', ['plan' => $this->planName])
                : __('Your :brand receipt for :period', ['brand' => $brand, 'period' => $this->periodStart->translatedFormat('F Y')]))
            ->preheader(__(':amount paid for :plan. Thank you.', ['amount' => $amount, 'plan' => $this->planName]))
            ->subtitle($this->isFirstPayment ? __('Subscription started') : __('Payment receipt'))
            ->eyebrow(__('Paid · :date', ['date' => $paid]))
            ->severity('confirmed')
            ->headline($this->isFirstPayment
                ? __('You are on the :plan plan', ['plan' => $this->planName])
                : __('Thank you for your payment'))
            ->lede(self::greet($this->user->name, $this->isFirstPayment
                ? __('your :plan subscription has started and everything in it is available now.', ['plan' => $this->planName])
                : __('your :plan subscription has renewed. Here is your receipt.', ['plan' => $this->planName])))
            ->table(
                [__('Description'), __('Amount')],
                [[__(':plan plan · :period', ['plan' => $this->planName, 'period' => $period]), $amount]],
                [__('Total paid'), $amount],
                __('Receipt'),
            )
            ->facts([
                __('Invoice') => $this->invoiceNumber,
                __('Paid on') => $paid,
                __('Payment method') => $this->paymentMethod,
                __('AI credits') => $this->monthlyCredits ? __(':n a month', ['n' => number_format($this->monthlyCredits)]) : null,
                __('Next renewal') => $this->periodEnd->translatedFormat('F j, Y'),
            ])
            ->cta($this->invoiceUrl ? __('View invoice') : __('Open billing'), $this->invoiceUrl ?? route('billing.show'))
            ->secondary(__('Manage your subscription'), route('billing.show'))
            ->reason(__('Billing emails are always sent: they are your record of what you paid.'));
    }
}
