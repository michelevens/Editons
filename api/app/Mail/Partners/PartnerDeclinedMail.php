<?php

namespace App\Mail\Partners;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

/** The application was declined, with the reason staff gave. */
class PartnerDeclinedMail extends EcrivainMail implements ShouldQueue
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

        return Letter::make(__('Your :brand Partners application', ['brand' => $brand]))
            ->preheader(__('We cannot approve it as it stands. Here is why.'))
            ->subtitle(__(':brand Partners', ['brand' => $brand]))
            ->eyebrow(__('Application declined'))
            ->severity('heads')
            ->headline(__('We could not approve your application'))
            ->lede(self::greet($this->user->name, __('thank you for applying as “:name”. We are not able to approve it as it stands.', ['name' => $this->partner->display_name])))
            ->facts([__('Reason') => $this->reason])
            ->paragraph(__('Your account is not affected. You can update your application and send it again from the partner page.'))
            ->cta(__('Update your application'), route('partners.apply'))
            ->reason(__('You received this because you applied to :brand Partners. Reply from Help if you have a question.', ['brand' => $brand]));
    }
}
