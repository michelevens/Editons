<?php

namespace App\Notifications;

use App\Mail\Account\PasswordResetMail;
use Illuminate\Auth\Notifications\ResetPassword;

/**
 * Laravel's password reset, in Écrivain's template.
 *
 * Extends the framework notification so the token, the broker and the
 * reset URL work exactly as before; only the message changes. Sent
 * immediately (no ShouldQueue): the reader is waiting on the "check your
 * inbox" screen. The expiry printed in the email is the broker's own.
 */
class ResetPasswordNotification extends ResetPassword
{
    public function toMail($notifiable): PasswordResetMail
    {
        $broker = config('auth.defaults.passwords', 'users');
        $minutes = (int) config("auth.passwords.{$broker}.expire", 60);

        return (new PasswordResetMail($notifiable, $this->resetUrl($notifiable), $minutes))
            ->to($notifiable->getEmailForPasswordReset());
    }
}
