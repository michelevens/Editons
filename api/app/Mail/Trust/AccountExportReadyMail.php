<?php

namespace App\Mail\Trust;

use App\Mail\EcrivainMail;
use App\Mail\Support\ContainsSecrets;
use App\Mail\Support\Letter;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A copy of everything in the account is ready to download.
 *
 * The link is signed and expiring, so the body is a secret: the email log
 * keeps only its metadata (ContainsSecrets).
 */
class AccountExportReadyMail extends EcrivainMail implements ContainsSecrets, ShouldQueue
{
    public const CATEGORY = 'account';

    public function __construct(public User $recipient, public string $url, public CarbonInterface $expiresAt, public int $books) {}

    public function account(): ?User
    {
        return $this->recipient;
    }

    public function letter(): Letter
    {
        $brand = config('mail.ecrivain.brand');

        return Letter::make(__('Your :brand data is ready to download', ['brand' => $brand]))
            ->preheader(__('Your books and records, in one file. The link works until :date.', ['date' => $this->expiresAt->translatedFormat('F j, Y')]))
            ->subtitle(__('Your data'))
            ->eyebrow(__('Account'))
            ->severity('action')
            ->headline(__('Your data export is ready'))
            ->lede(self::greet($this->recipient->name, __('as requested, here is a copy of your account: every book as Markdown and as a :brand file, any EPUB you had generated, and your orders and credits as spreadsheets.', ['brand' => $brand])))
            ->facts([
                __('Books') => (string) $this->books,
                __('Link expires') => $this->expiresAt->translatedFormat('F j, Y'),
            ])
            ->cta(__('Download your data'), $this->url, showFallbackUrl: true)
            ->callout('info', __('Anyone with this link can download the file until it expires. Do not forward this email.'))
            ->reason(__('You received this because a copy of your :brand account was requested for you.', ['brand' => $brand]));
    }
}
