<?php

namespace App\Mail\Publishing;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Models\Book;
use App\Models\BookChannel;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A book passed the store checks and is ready for the author to upload.
 *
 * Écrivain has no store integration yet, so nothing was sent anywhere. The
 * email says so and gives each store's upload page; it used to say the book
 * was "on its way to the stores", which an author would wait on for ever.
 */
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

        return Letter::make(trans_choice('{1} “:title” is ready to upload to :count store|[2,*] “:title” is ready to upload to :count stores', $count, [
                'title' => $this->book->title,
                'count' => $count,
            ]))
            ->preheader(__('Download the file from Écrivain and upload it at each store. Nothing has been sent yet.'))
            ->subtitle(__('Ready to submit'))
            ->eyebrow(__('Publishing'))
            ->severity('confirmed')
            ->headline(__('Your book is ready for the stores'))
            ->lede(self::greet($this->recipient->name, __('“:title” passed every check. Écrivain does not send books to stores itself yet, so the last step is yours: download the file and upload it at each store below, then mark it submitted on the Publish screen.', [
                'title' => $this->book->title,
            ])))
            ->book($this->book)
            ->items(array_map(fn (string $channel) => [
                'title' => BookChannel::CHANNELS[$channel][0] ?? $channel,
                'detail' => BookChannel::UPLOAD[$channel][2] ?? null,
                'badge' => __('To upload'),
                'tone' => 'warning',
                'url' => BookChannel::UPLOAD[$channel][0] ?? null,
            ], $this->channels), __('Upload to'))
            ->facts([
                __('List price') => $this->book->list_price ? '$'.number_format((float) $this->book->list_price, 2) : null,
            ])
            ->cta(__('Open the Publish screen'), route('books.edit', ['book' => $this->book, 'pane' => 'export']))
            ->reason(__('You received this because you asked to publish “:title”. You can switch publishing emails off in your preferences.', ['title' => $this->book->title]));
    }
}
