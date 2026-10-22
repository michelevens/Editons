<?php

namespace App\Mail\Publishing;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Models\BookChannel;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

/** A store made the book live. One email per store. */
class BookLiveMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'publishing';

    public function __construct(
        public BookChannel $channel,
        public User $recipient,
        public ?string $storeUrl = null,
    ) {}

    public function account(): ?User
    {
        return $this->recipient;
    }

    public function letter(): Letter
    {
        $book = $this->channel->book;
        $store = $this->channel->label();

        $letter = Letter::make(__('“:title” is live on :store', ['title' => $book->title, 'store' => $store]))
            ->preheader(__('Readers can buy it now. Time to tell them.'))
            ->subtitle(__('Your book is live'))
            ->eyebrow(__('Published · :store', ['store' => $store]))
            ->severity('confirmed')
            ->headline(__('Your book is on sale at :store', ['store' => $store]))
            ->lede(self::greet($this->recipient->name, __(':store has approved “:title”, and readers can buy it now. Congratulations.', [
                'store' => $store,
                'title' => $book->title,
            ])))
            ->book($book, __('Now available'))
            ->facts([
                __('Store') => $store,
                __('Edition') => $this->channel->format === 'print' ? __('Print') : __('eBook'),
                __('Live since') => ($this->channel->went_live_at ?? now())->translatedFormat('F j, Y'),
                __('Store page') => $this->storeUrl,
            ]);

        return ($this->storeUrl
                ? $letter->cta(__('See it on :store', ['store' => $store]), $this->storeUrl)->secondary(__('Plan your launch'), route('launch.show', $book))
                : $letter->cta(__('Plan your launch'), route('launch.show', $book)))
            ->reason(__('You received this because you published “:title”. You can switch publishing emails off in your preferences.', ['title' => $book->title]));
    }
}
