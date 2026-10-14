<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An ISBN in the author's account.
 *
 * Unassigned rows are the pool. Assigning sets book_id and the format it
 * covers; unassigning clears both and returns it to the pool.
 */
class Isbn extends Model
{
    use HasFactory;

    public const SOURCES = ['purchased', 'store_issued', 'own'];

    protected $fillable = ['isbn', 'book_id', 'edition_format', 'source'];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isAssigned(): bool
    {
        return $this->book_id !== null;
    }

    /**
     * Whether a string is a structurally valid ISBN-13.
     *
     * Checks the checksum, not just the shape. A mistyped digit passes a
     * length check and is then printed on a cover and sent to a store, where
     * it becomes somebody else's book or no book at all.
     */
    public static function isValid(string $candidate): bool
    {
        $digits = preg_replace('/[^0-9]/', '', $candidate);

        if (strlen((string) $digits) !== 13) {
            return false;
        }

        $sum = 0;

        foreach (str_split($digits) as $index => $digit) {
            $sum += (int) $digit * ($index % 2 === 0 ? 1 : 3);
        }

        return $sum % 10 === 0;
    }

    /** Normalised to digits with the conventional hyphenation dropped. */
    public static function normalise(string $candidate): string
    {
        return (string) preg_replace('/[^0-9]/', '', $candidate);
    }
}
