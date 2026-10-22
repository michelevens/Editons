<?php

namespace App\Mail\Publishing;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Models\BookChannel;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

/** A book stopped being for sale at one store — by the author's choice or the store's. */
class BookUnpublishedMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'publishing';

    public function __construct(
        public BookChannel $channel,
        public User $recipient,
        public ?string $reason = null,
        public bool $byAuthor = true,
    ) {}

    public function account(): ?User
    {
        return $this->recipient;
    }

    public function letter(): Letter
    {
        $book = $this->channel->book;
        $store = $this->channel->label();

        return Letter::make(__('“:title” is no longer on sale at :store', ['title' => $book->title, 'store' => $store]))
            ->preheader($this->byAuthor
                ? __('Unpublished as you asked. You can publish it again at any time.')
                : __(':store has taken the book down. Here is what we know.', ['store' => $store]))
            ->subtitle(__('Book unpublished'))
            ->eyebrow(__('Publishing · :store', ['store' => $store]))
            ->severity('heads')
            ->headline(__('Your book was unpublished from :store', ['store' => $store]))
            ->lede(self::greet($this->recipient->name, $this->byAuthor
                ? __('as you asked, “:title” has been taken off sale at :store. Other stores are not affected.', ['title' => $book->title, 'store' => $store])
                : __(':store has taken “:title” off sale. Other stores are not affected.', ['title' => $book->title, 'store' => $store])))
            ->book($book)
            ->facts([
                __('Store') => $store,
                __('Unpublished') => now()->translatedFormat('F j, Y'),
                __('Reason') => $this->reason,
            ])
            ->cta(__('Open publishing'), route('publishing.show', $book))
            ->callout('info', __('Readers who already bought it usually keep their copy; the store\'s own policy decides. You can publish the book again whenever you like.'))
            ->reason(__('You received this because you publish “:title”. You can switch publishing emails off in your preferences.', ['title' => $book->title]));
    }
}
