<?php

namespace App\Notifications;

use App\Mail\Account\VerifyEmailMail;
use Illuminate\Auth\Notifications\VerifyEmail;

/**
 * Laravel's email verification, in Écrivain's template.
 *
 * The signed URL is built by the framework class exactly as before. Not
 * queued. Only sent once MustVerifyEmail is enabled on User (or when the
 * "send the link again" button is used).
 */
class VerifyEmailNotification extends VerifyEmail
{
    public function toMail($notifiable): VerifyEmailMail
    {
        $minutes = (int) config('auth.verification.expire', 60);

        return (new VerifyEmailMail($notifiable, $this->verificationUrl($notifiable), $minutes))
            ->to($notifiable->getEmailForVerification());
    }
}
