<?php

namespace App\Mail\Partners;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

/** The application was approved (or an author's invitation accepted): the page is live. */
class PartnerApprovedMail extends EcrivainMail implements ShouldQueue
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

        return Letter::make(__('You are an :brand Partner', ['brand' => $brand]))
            ->preheader(__('Your partner page is live. Add books, share your link, earn on every sale.'))
            ->subtitle(__(':brand Partners', ['brand' => $brand]))
            ->eyebrow(__('Application approved'))
            ->severity('confirmed')
            ->headline(__('Welcome to :brand Partners', ['brand' => $brand]))
            ->lede(self::greet($this->user->name, __('your partner page is now public.')))
            ->facts([
                __('Your page') => route('partners.page', $this->partner->handle),
                __('Your partner code') => $this->partner->code,
            ])
            ->paragraph(__('Choose the books for your page, then share your link, a book link or your QR code. When somebody buys through them, you earn a commission. Each commission is held for :days days (the refund window) before it can be paid.', ['days' => (int) config('partners.hold_days')]))
            ->callout('info', __('Your page always tells visitors that you earn a commission on purchases made through it. Please say so as well wherever you share your links.'))
            ->cta(__('Set up your page'), route('partners.dashboard', ['tab' => 'page']))
            ->reason(__('You received this because you applied to :brand Partners.', ['brand' => $brand]));
    }
}
