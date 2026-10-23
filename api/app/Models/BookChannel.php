<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A book's standing in one store.
 *
 * The store list is fixed here rather than in a table: these are integrations,
 * and a new one means code to talk to it, not a row.
 */
class BookChannel extends Model
{
    use HasFactory;

    /**
     * ready_to_submit: the author asked to publish and the book passed the
     * checks, but nothing has been sent, because no store integration
     * exists. The author uploads the file at the store and then marks it
     * submitted, which is when submitted_at becomes a fact.
     */
    public const STATUSES = ['not_submitted', 'ready_to_submit', 'in_review', 'live', 'unpublished'];

    /** channel => [label, format]. */
    public const CHANNELS = [
        'amazon_kdp' => ['Amazon Kindle', 'ebook'],
        'apple_books' => ['Apple Books', 'ebook'],
        'kobo' => ['Kobo', 'ebook'],
        'google_play' => ['Google Play Books', 'ebook'],
        'ingram_print' => ['IngramSpark (print)', 'print'],
    ];

    /**
     * What the author does at each store, until it is integrated.
     *
     * channel => [where to upload, which file, the steps].
     */
    public const UPLOAD = [
        'amazon_kdp' => ['https://kdp.amazon.com', 'epub', 'Sign in to Kindle Direct Publishing, create a Kindle eBook, paste the listing details from this screen and upload the EPUB.'],
        'apple_books' => ['https://authors.apple.com', 'epub', 'Sign in to Apple Books for Authors, add a new book, enter the listing details and upload the EPUB with its cover.'],
        'kobo' => ['https://www.kobo.com/writinglife', 'epub', 'Sign in to Kobo Writing Life, create a new eBook, enter the listing details and upload the EPUB.'],
        'google_play' => ['https://play.google.com/books/publish', 'epub', 'Sign in to the Google Play Books Partner Center, add a book, enter the listing details and upload the EPUB.'],
        'ingram_print' => ['https://www.ingramspark.com', 'pdf', 'Sign in to IngramSpark, add a print title at your trim size and upload the PDF interior with a cover file built to their template.'],
    ];

    protected $fillable = ['channel', 'format', 'status'];

    /**
     * submitted_at and went_live_at are not fillable: when a store received a
     * book and when it made it live are facts about the outside world, and a
     * client claiming either would make the Publishing screen lie.
     */
    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'went_live_at' => 'datetime',
        ];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function label(): string
    {
        return self::CHANNELS[$this->channel][0] ?? $this->channel;
    }
}
