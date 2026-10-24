<?php

namespace App\Mail\Trust;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

/** A listing Ecrivain had taken down is public again. Account mail. */
class ListingRestoredMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'account';

    public function __construct(public User $author, public string $title, public ?string $note = null) {}

    public function account(): ?User
    {
        return $this->author;
    }

    public function letter(): Letter
    {
        $brand = config('mail.ecrivain.brand');

        return Letter::make(__('“:title” is back in the :brand store', ['title' => $this->title, 'brand' => $brand]))
            ->preheader(__('Its store page is public again.'))
            ->subtitle(__('Storefront'))
            ->eyebrow(__('Your listing'))
            ->severity('confirmed')
            ->headline(__('Your store page is back'))
            ->lede(self::greet($this->author->name, __('the store page for “:title” is public again, as it was before.', ['title' => $this->title])))
            ->facts([
                __('Book') => $this->title,
                __('Restored') => now()->translatedFormat('F j, Y'),
                __('Note') => $this->note,
            ])
            ->cta(__('Open your books'), route('books.index'))
            ->reason(__('You received this because you sell “:title” in the :brand store.', ['title' => $this->title, 'brand' => $brand]));
    }
}
