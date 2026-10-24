<?php

namespace App\Mail\Trust;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Ecrivain took a storefront listing down. Account mail: an author whose
 * book stopped being for sale is always told, and told why, whatever their
 * email preferences.
 */
class ListingTakenDownMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'account';

    public function __construct(public User $author, public string $title, public string $reason) {}

    public function account(): ?User
    {
        return $this->author;
    }

    public function letter(): Letter
    {
        $brand = config('mail.ecrivain.brand');

        return Letter::make(__('“:title” has been taken off the :brand store', ['title' => $this->title, 'brand' => $brand]))
            ->preheader(__('Its store page is offline. Readers who bought it keep their copy.'))
            ->subtitle(__('Storefront'))
            ->eyebrow(__('Your listing'))
            ->severity('heads')
            ->headline(__('Your store page was taken down'))
            ->lede(self::greet($this->author->name, __('the store page for “:title” is no longer public. Your book itself is untouched, and you can still write, export and publish it elsewhere.', ['title' => $this->title])))
            ->facts([
                __('Book') => $this->title,
                __('Taken down') => now()->translatedFormat('F j, Y'),
                __('Reason') => $this->reason,
            ])
            ->callout('info', __('Readers who already bought it keep their licence and their downloads.'))
            ->cta(__('Write to support'), route('support.index'))
            ->reason(__('You received this because you sell “:title” in the :brand store. If you think this is a mistake, reply from Help and we will look again.', ['title' => $this->title, 'brand' => $brand]));
    }
}
