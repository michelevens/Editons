<?php

namespace App\Mail\Account;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The sign-in address changed. Sent to the OLD address.
 *
 * The new address is the one an attacker would control; the old one belongs
 * to whoever owned the account before the change. Telling only the new
 * address would tell the wrong person.
 */
class EmailChangedMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'account';

    public function __construct(
        public User $user,
        public string $oldEmail,
        public string $newEmail,
        public CarbonInterface $changedAt,
    ) {}

    public function account(): ?User
    {
        return $this->user;
    }

    public function letter(): Letter
    {
        $brand = config('mail.ecrivain.brand');
        $support = config('mail.ecrivain.support_address');

        return Letter::make(__('Your :brand email address was changed', ['brand' => $brand]))
            ->preheader(__('Your account now signs in with :email. We are telling your old address so you know.', ['email' => $this->newEmail]))
            ->subtitle(__('Email address changed'))
            ->eyebrow(__('Security'))
            ->severity('heads')
            ->headline(__('Your sign-in email has changed'))
            ->lede(self::greet($this->user->name, __('the email address on your account was changed. We are writing to your old address so that you hear about it either way.')))
            ->facts([
                __('Old address') => $this->oldEmail,
                __('New address') => $this->newEmail,
                __('When') => $this->changedAt->translatedFormat('F j, Y \a\t g:i A T'),
            ])
            ->secondary(__('Contact :brand support', ['brand' => $brand]), 'mailto:'.$support)
            ->callout('danger', __('Reply to :support from this address straight away and we will help you get your account back.', [
                'support' => $support,
            ]), __('Did not make this change?'))
            ->reason(__('Security emails cannot be switched off: they are how you find out somebody else is in your account.'));
    }
}
