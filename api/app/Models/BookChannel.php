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

    public const STATUSES = ['not_submitted', 'in_review', 'live', 'unpublished'];

    /** channel => [label, format]. */
    public const CHANNELS = [
        'amazon_kdp' => ['Amazon Kindle', 'ebook'],
        'apple_books' => ['Apple Books', 'ebook'],
        'kobo' => ['Kobo', 'ebook'],
        'google_play' => ['Google Play Books', 'ebook'],
        'ingram_print' => ['IngramSpark (print)', 'print'],
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
