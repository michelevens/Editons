<?php

namespace App\Mail\Account;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Models\User;

/**
 * Confirm an email address.
 *
 * Not queued: somebody is looking at a "check your inbox" screen. Sent by
 * App\Notifications\VerifyEmailNotification, which User uses in place of
 * Laravel's stock notification — ready for when MustVerifyEmail is enabled.
 */
class VerifyEmailMail extends EcrivainMail
{
    public const CATEGORY = 'account';

    public function __construct(
        public User $user,
        public string $url,
        public int $expiresInMinutes = 60,
    ) {}

    public function account(): ?User
    {
        return $this->user;
    }

    public function letter(): Letter
    {
        $brand = config('mail.ecrivain.brand');

        return Letter::make(__('Confirm your email address'))
            ->preheader(__('One click to confirm this is your address, so we can reach you about your books.'))
            ->subtitle(__('Confirm your email'))
            ->eyebrow(__('One step left'))
            ->severity('action')
            ->headline(__('Is this the right address?'))
            ->lede(self::greet($this->user->name, __('please confirm that :email is yours. It is where we send sign-in help, receipts and anything else about your account.', ['email' => $this->user->email])))
            ->cta(__('Confirm email address'), $this->url, showFallbackUrl: true)
            ->callout('info', __('This link expires in :n minutes. If you did not sign up for :brand, ignore this email and nothing will happen.', [
                'n' => $this->expiresInMinutes,
                'brand' => $brand,
            ]))
            ->reason(__('You received this because this address was used to sign up for :brand.', ['brand' => $brand]));
    }
}
