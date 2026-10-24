<?php

namespace App\Mail\Partners;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * An author invites somebody to sell their books as a partner. Sent to a
 * bare address: the invitee may have no account yet.
 */
class PartnerInvitationMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'collaboration';

    /** @param  list<string>  $titles */
    public function __construct(
        public string $sellerName,
        public string $url,
        public array $titles,
        public CarbonInterface $expiresAt,
    ) {}

    public function letter(): Letter
    {
        $brand = config('mail.ecrivain.brand');

        return Letter::make(__(':name invited you to sell their books', ['name' => $this->sellerName]))
            ->preheader(__('Earn a commission on every copy sold through your own :brand Partners page.', ['brand' => $brand]))
            ->subtitle(__(':brand Partners', ['brand' => $brand]))
            ->eyebrow(__('Invitation'))
            ->severity('action')
            ->headline(__(':name would like you as a partner', ['name' => $this->sellerName]))
            ->lede(__(':name sells their books on :brand and invited you to share them, on commission, from a page of your own.', ['name' => $this->sellerName, 'brand' => $brand]))
            ->items(array_map(fn (string $title) => ['title' => $title], array_slice($this->titles, 0, 8)), __('Books you could sell'))
            ->paragraph(__('Accepting needs a free :brand account. Your page is approved straight away, for this author\'s books.', ['brand' => $brand]))
            ->cta(__('Accept the invitation'), $this->url, true)
            ->facts([__('Invitation expires') => $this->expiresAt->translatedFormat('F j, Y')])
            ->reason(__('You received this because :name entered this address on :brand. If you were not expecting it, ignore this email.', ['name' => $this->sellerName, 'brand' => $brand]));
    }
}
