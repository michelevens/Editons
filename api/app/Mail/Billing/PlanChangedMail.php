<?php

namespace App\Mail\Billing;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Mail\Support\Money;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Upgraded or downgraded between paid plans. */
class PlanChangedMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'billing';

    public function __construct(
        public User $user,
        public string $fromPlan,
        public string $toPlan,
        public CarbonInterface $effectiveAt,
        public ?int $newPriceCents = null,
        public string $currency = 'USD',
        public ?int $monthlyCredits = null,
        public bool $isUpgrade = true,
    ) {}

    public function account(): ?User
    {
        return $this->user;
    }

    public function letter(): Letter
    {
        $now = $this->effectiveAt->lessThanOrEqualTo(now()->addMinute());

        return Letter::make(__('Your plan is now :plan', ['plan' => $this->toPlan]))
            ->preheader($now
                ? __('Your :from plan has changed to :to, starting today.', ['from' => $this->fromPlan, 'to' => $this->toPlan])
                : __('Your :from plan changes to :to on :date.', ['from' => $this->fromPlan, 'to' => $this->toPlan, 'date' => $this->effectiveAt->translatedFormat('F j')]))
            ->subtitle(__('Plan changed'))
            ->eyebrow($this->isUpgrade ? __('Upgrade') : __('Plan change'))
            ->severity('confirmed')
            ->headline($this->isUpgrade
                ? __('Welcome to :plan', ['plan' => $this->toPlan])
                : __('Your plan is changing to :plan', ['plan' => $this->toPlan]))
            ->lede(self::greet($this->user->name, $this->isUpgrade
                ? __('your account has moved from :from to :to. The extra room is yours now.', ['from' => $this->fromPlan, 'to' => $this->toPlan])
                : __('your account is moving from :from to :to. Here is what changes.', ['from' => $this->fromPlan, 'to' => $this->toPlan])))
            ->facts([
                __('Previous plan') => $this->fromPlan,
                __('New plan') => $this->toPlan,
                __('Price') => $this->newPriceCents !== null
                    ? __(':amount a month', ['amount' => Money::format($this->newPriceCents, $this->currency)])
                    : null,
                __('AI credits') => $this->monthlyCredits ? __(':n a month', ['n' => number_format($this->monthlyCredits)]) : null,
                __('Takes effect') => $now ? __('Now') : $this->effectiveAt->translatedFormat('F j, Y'),
            ])
            ->cta(__('See your plan'), route('billing.show'))
            ->reason(__('Billing emails are always sent: they are your record of what you pay for.'));
    }
}
