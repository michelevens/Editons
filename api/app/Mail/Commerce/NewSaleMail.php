<?php

namespace App\Mail\Commerce;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Mail\Support\Money;
use App\Models\Book;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Somebody bought one of the author's books.
 *
 * The buyer is not named: the author gets the sale, not the reader's
 * identity. Country is as much as a store would show.
 */
class NewSaleMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'sales';

    public function __construct(
        public User $author,
        public Book $book,
        public string $format,
        public int $amountCents,
        public int $royaltyCents,
        public string $currency,
        public CarbonInterface $soldAt,
        public ?string $channel = null,
        public ?string $buyerCountry = null,
        public ?int $salesThisMonth = null,
    ) {}

    public function account(): ?User
    {
        return $this->author;
    }

    public function letter(): Letter
    {
        $royalty = Money::format($this->royaltyCents, $this->currency);
        $channel = $this->channel ?? __(':brand store', ['brand' => config('mail.ecrivain.brand')]);

        return Letter::make(__('You sold a copy of “:title”', ['title' => $this->book->title]))
            ->preheader(__(':royalty to you from a :format sale on :channel.', ['royalty' => $royalty, 'format' => $this->format, 'channel' => $channel]))
            ->subtitle(__('New sale'))
            ->eyebrow(__('Sale · :channel', ['channel' => $channel]))
            ->severity('confirmed')
            ->headline(__('Somebody just bought your book'))
            ->lede(self::greet($this->author->name, __('a reader bought the :format of “:title”.', ['format' => $this->format, 'title' => $this->book->title])))
            ->stats(array_values(array_filter([
                ['label' => __('Sale price'), 'value' => Money::format($this->amountCents, $this->currency)],
                ['label' => __('Your royalty'), 'value' => $royalty, 'tone' => 'success'],
                $this->salesThisMonth !== null ? ['label' => __('Sales this month'), 'value' => number_format($this->salesThisMonth)] : null,
            ])))
            ->book($this->book)
            ->facts([
                __('Format') => $this->format,
                __('Where') => $channel,
                __('Reader in') => $this->buyerCountry,
                __('When') => $this->soldAt->translatedFormat('F j, Y \a\t g:i A'),
            ])
            ->cta(__('See your sales'), route('sales.show', $this->book))
            ->reason(__('You received this because you sell “:title” through :brand. You can switch sale emails off in your preferences.', [
                'title' => $this->book->title,
                'brand' => config('mail.ecrivain.brand'),
            ]));
    }
}
