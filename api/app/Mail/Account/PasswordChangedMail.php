<?php

namespace App\Mail\Account;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;

/** The password changed — from the profile page or a reset link. */
class PasswordChangedMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'account';

    public function __construct(
        public User $user,
        public CarbonInterface $changedAt,
        public ?string $ipAddress = null,
    ) {}

    public function account(): ?User
    {
        return $this->user;
    }

    public function letter(): Letter
    {
        $brand = config('mail.ecrivain.brand');

        return Letter::make(__('Your :brand password was changed', ['brand' => $brand]))
            ->preheader(__('If this was you, there is nothing else to do. If not, reset your password now.'))
            ->subtitle(__('Password changed'))
            ->eyebrow(__('Security'))
            ->severity('confirmed')
            ->headline(__('Your password has been changed'))
            ->lede(self::greet($this->user->name, __('the password for your account was just changed. If that was you, there is nothing else to do.')))
            ->facts([
                __('Account') => $this->user->email,
                __('When') => $this->changedAt->translatedFormat('F j, Y \a\t g:i A T'),
                __('From IP address') => $this->ipAddress,
            ])
            ->cta(__('Review your account'), route('profile.edit'))
            ->secondary(__('Not you? Reset your password'), route('password.request'))
            ->callout('danger', __('Reset your password straight away and write to :support. Somebody may have access to your account.', [
                'support' => config('mail.ecrivain.support_address'),
            ]), __('Did not change it?'))
            ->reason(__('Security emails cannot be switched off: they are how you find out somebody else is in your account.'));
    }
}
