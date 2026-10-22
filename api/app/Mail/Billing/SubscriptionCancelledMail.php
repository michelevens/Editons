<?php

namespace App\Mail\Billing;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;

/** A subscription was cancelled; it runs until the end of the paid period. */
class SubscriptionCancelledMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'billing';

    public function __construct(
        public User $user,
        public string $planName,
        public CarbonInterface $endsAt,
    ) {}

    public function account(): ?User
    {
        return $this->user;
    }

    public function letter(): Letter
    {
        $ends = $this->endsAt->translatedFormat('F j, Y');

        return Letter::make(__('Your :plan plan is cancelled', ['plan' => $this->planName]))
            ->preheader(__('You keep everything in :plan until :date. Your books stay yours.', ['plan' => $this->planName, 'date' => $ends]))
            ->subtitle(__('Subscription cancelled'))
            ->eyebrow(__('Ends :date', ['date' => $ends]))
            ->severity('heads')
            ->headline(__('Your subscription is cancelled'))
            ->lede(self::greet($this->user->name, __('your :plan subscription has been cancelled and will not renew. You keep everything in :plan until :date; after that your account moves to the Free plan.', [
                'plan' => $this->planName,
                'date' => $ends,
            ])))
            ->facts([
                __('Plan') => $this->planName,
                __('Access until') => $ends,
                __('Then') => __('Free plan'),
                __('Further charges') => __('None'),
            ])
            ->cta(__('Resume subscription'), route('billing.show'))
            ->callout('info', __('Nothing is deleted when a plan ends. Changed your mind? Resume any time before :date and nothing changes.', ['date' => $ends]), __('Your books stay yours'))
            ->reason(__('Billing emails are always sent: they are your record of what you pay for.'));
    }
}
