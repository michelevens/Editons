<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A book on sale in the author's own storefront.
 *
 * One per book (an edition is its own book, so its own product). What a
 * buyer receives are the files the export system built for that book — the
 * latest ready export of each format in `formats` — under the license named
 * here.
 *
 * user_id and book_id are not fillable: the controller sets them from the
 * book it authorised, never from request input.
 */
class Product extends Model
{
    protected $fillable = [
        'title', 'description', 'price_cents', 'currency', 'formats', 'license', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'formats' => 'array',
            'price_cents' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            $product->slug ??= self::newSlug($product->book?->title ?? 'book');
        });
    }

    /** "the-long-walk-k3x9q2": readable, and not guessable from the title alone. */
    public static function newSlug(string $title): string
    {
        $base = Str::limit(Str::slug($title) ?: 'book', 60, '');

        do {
            $slug = $base.'-'.Str::lower(Str::random(6));
        } while (self::where('slug', $slug)->exists());

        return $slug;
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            // A book in the bin is not for sale, whatever its product says.
            ->whereHas('book');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null && $this->published_at->lte(now());
    }

    /** The title buyers see: the product's own, or the book's. */
    public function displayTitle(): string
    {
        return trim((string) $this->title) !== '' ? (string) $this->title : (string) $this->book?->title;
    }

    /** Plain text. The storefront renders it as text, never as HTML. */
    public function displayDescription(): string
    {
        $text = trim((string) $this->description) !== '' ? $this->description : $this->book?->description;

        return trim(strip_tags((string) $text));
    }

    /**
     * The name printed on the storefront, or null.
     *
     * Only the book's own author_name. Never the account's name, never the
     * cover's: a book published without a name (a series sold anonymously)
     * shows none anywhere a buyer can see.
     */
    public function authorName(): ?string
    {
        $name = trim((string) $this->book?->author_name);

        return $name === '' ? null : $name;
    }

    /** @return array{label: string, terms: string} */
    public function licenseTerms(): array
    {
        return config("commerce.licenses.{$this->license}")
            ?? config('commerce.licenses.personal');
    }
}
