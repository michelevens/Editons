<?php

namespace App\Mail\Account;

use App\Mail\EcrivainMail;
use App\Mail\Support\ContainsSecrets;
use App\Mail\Support\Letter;
use App\Models\User;

/**
 * A password reset link.
 *
 * Not queued: the reader is on the "we sent you a link" screen, and a reset
 * that waits behind a busy queue worker looks like one that never came.
 * Sent by App\Notifications\ResetPasswordNotification; the expiry is the
 * password broker's own (config auth.passwords.users.expire).
 */
class PasswordResetMail extends EcrivainMail implements ContainsSecrets
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

        return Letter::make(__('Reset your :brand password', ['brand' => $brand]))
            ->preheader(__('Use this link within :n minutes to choose a new password.', ['n' => $this->expiresInMinutes]))
            ->subtitle(__('Password reset'))
            ->eyebrow(__('Security'))
            ->severity('action')
            ->headline(__('Choose a new password'))
            ->lede(self::greet($this->user->name, __('we received a request to reset the password for your account. The button below lets you choose a new one.')))
            ->facts([
                __('Account') => $this->user->email,
                __('Link expires') => trans_choice('{1} in :count minute|[2,*] in :count minutes', $this->expiresInMinutes, ['count' => $this->expiresInMinutes]),
            ])
            ->cta(__('Reset password'), $this->url, showFallbackUrl: true)
            ->callout('warning', __('You can ignore this email: your password stays the same until somebody uses the link. If these keep arriving and you did not ask for them, write to :support.', [
                'support' => config('mail.ecrivain.support_address'),
            ]), __('Did not ask for this?'))
            ->reason(__('You received this because a password reset was requested for this :brand account.', ['brand' => $brand]));
    }
}
