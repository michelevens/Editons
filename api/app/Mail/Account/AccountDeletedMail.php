<?php

namespace App\Mail\Account;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Confirmation that an account is gone.
 *
 * Carries plain values, not the User: by the time a queue worker renders
 * this the row has been deleted, and SerializesModels would fail to restore
 * it. For the same reason there is no preferences link — there is no
 * account left to manage.
 */
class AccountDeletedMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'account';

    public function __construct(
        public ?string $name,
        public string $email,
        public CarbonInterface $deletedAt,
        public int $booksDeleted = 0,
    ) {}

    public function letter(): Letter
    {
        $brand = config('mail.ecrivain.brand');

        return Letter::make(__('Your :brand account has been deleted', ['brand' => $brand]))
            ->preheader(__('Your account and everything in it have been removed. This is the last email we will send.'))
            ->subtitle(__('Account deleted'))
            ->eyebrow(__('For your records'))
            ->severity('info')
            ->headline(__('Your account is deleted'))
            ->lede(self::greet($this->name, __('as you asked, your :brand account and everything in it have been deleted.', ['brand' => $brand])))
            ->facts([
                __('Account') => $this->email,
                __('Deleted on') => $this->deletedAt->translatedFormat('F j, Y'),
                __('Books removed') => (string) $this->booksDeleted,
            ])
            ->callout('info', __('Your books, their versions and their exports were removed with the account. If you ever want to write with us again, you are welcome to start a new one.'), __('This cannot be undone'))
            ->reason(__('This is the last email we will send to this address. If you did not delete your account, write to :support.', [
                'support' => config('mail.ecrivain.support_address'),
            ]));
    }
}
