<?php

namespace App\Mail\Publishing;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Models\Book;
use App\Models\BookChannel;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

/** A book was sent to one or more stores for review. */
class SubmittedToStoresMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'publishing';

    /** @param  list<string>  $channels  BookChannel::CHANNELS keys */
    public function __construct(
        public Book $book,
        public User $recipient,
        public array $channels,
    ) {}

    public function account(): ?User
    {
        return $this->recipient;
    }

    public function letter(): Letter
    {
        $count = count($this->channels);

        return Letter::make(trans_choice('{1} “:title” was submitted to :count store|[2,*] “:title” was submitted to :count stores', $count, [
                'title' => $this->book->title,
                'count' => $count,
            ]))
            ->preheader(__('Each store reviews new books on its own schedule. We will email you as each one goes live.'))
            ->subtitle(__('Submitted to stores'))
            ->eyebrow(__('Publishing'))
            ->severity('confirmed')
            ->headline(__('Your book is on its way to the stores'))
            ->lede(self::greet($this->recipient->name, __('“:title” has been submitted. Each store checks a new book before it goes on sale, on its own schedule — usually a few days.', [
                'title' => $this->book->title,
            ])))
            ->book($this->book)
            ->items(array_map(fn (string $channel) => [
                'title' => BookChannel::CHANNELS[$channel][0] ?? $channel,
                'detail' => (BookChannel::CHANNELS[$channel][1] ?? null) === 'print' ? __('Print edition') : __('eBook edition'),
                'badge' => __('In review'),
                'tone' => 'warning',
            ], $this->channels), __('Submitted to'))
            ->facts([
                __('Submitted') => now()->translatedFormat('F j, Y'),
                __('List price') => $this->book->list_price ? '$'.number_format((float) $this->book->list_price, 2) : null,
            ])
            ->cta(__('Track your submissions'), route('publishing.show', $this->book))
            ->reason(__('You received this because you submitted “:title”. You can switch publishing emails off in your preferences.', ['title' => $this->book->title]));
    }
}
