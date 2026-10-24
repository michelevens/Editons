<?php

namespace App\Mail\Partners;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Somebody applied to Écrivain Partners: we have it, and what happens next. */
class PartnerApplicationReceivedMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'account';

    public function __construct(public User $user, public Partner $partner) {}

    public function account(): ?User
    {
        return $this->user;
    }

    public function letter(): Letter
    {
        $brand = config('mail.ecrivain.brand');

        return Letter::make(__('We have your :brand Partners application', ['brand' => $brand]))
            ->preheader(__('A person reads every application. We will write when it is decided.'))
            ->subtitle(__(':brand Partners', ['brand' => $brand]))
            ->eyebrow(__('Application received'))
            ->severity('info')
            ->headline(__('Thank you for applying'))
            ->lede(self::greet($this->user->name, __('your application to sell books as “:name” is in the queue.', ['name' => $this->partner->display_name])))
            ->facts([
                __('Partner name') => $this->partner->display_name,
                __('Your page, once approved') => route('partners.page', $this->partner->handle),
                __('Agreement') => __('Partner Agreement v:version', ['version' => $this->partner->agreement_version]),
            ])
            ->paragraph(__('Your page stays private until your application is approved. You can already prepare it from your partner dashboard.'))
            ->cta(__('Open your partner dashboard'), route('partners.dashboard'))
            ->reason(__('You received this because you applied to :brand Partners.', ['brand' => $brand]));
    }
}
