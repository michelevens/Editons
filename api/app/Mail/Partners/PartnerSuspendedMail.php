<?php

namespace App\Mail\Partners;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Staff suspended a partner: the page is offline, links stop earning, the balance is frozen. */
class PartnerSuspendedMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'account';

    public function __construct(public User $user, public Partner $partner, public string $reason) {}

    public function account(): ?User
    {
        return $this->user;
    }

    public function letter(): Letter
    {
        $brand = config('mail.ecrivain.brand');

        return Letter::make(__('Your :brand Partners account is suspended', ['brand' => $brand]))
            ->preheader(__('Your partner page is offline and your links no longer earn commissions.'))
            ->subtitle(__(':brand Partners', ['brand' => $brand]))
            ->eyebrow(__('Partner account'))
            ->severity('urgent')
            ->headline(__('Your partner account is suspended'))
            ->lede(self::greet($this->user->name, __('your partner page is offline and your links no longer earn commissions.')))
            ->facts([
                __('Partner name') => $this->partner->display_name,
                __('Reason') => $this->reason,
            ])
            ->callout('warning', __('Your balance is frozen while we look into it. It is either released to you or, if the program rules were broken, forfeited.'))
            ->cta(__('Write to support'), route('support.index'))
            ->reason(__('You received this because you are an :brand Partner. Your writing account itself is not affected.', ['brand' => $brand]));
    }
}
