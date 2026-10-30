<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Several of one seller's products sold together at one price — a whole
 * series, say (App\Commerce\Bundles).
 *
 * Buying it puts one order item per product on the order, each with its
 * share of the price (in proportion to the products' list prices), so the
 * licence, the royalty, the statement and the sales report of each book
 * work exactly as for a book bought on its own.
 *
 * user_id is not fillable: the controller sets it from the signed-in
 * seller.
 */
class Bundle extends Model
{
    protected $fillable = ['title', 'description', 'price_cents', 'currency', 'published_at'];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Bundle $bundle) {
            $bundle->slug ??= self::newSlug($bundle->title);
        });
    }

    public static function newSlug(string $title): string
    {
        $base = Str::limit(Str::slug($title) ?: 'bundle', 60, '');

        do {
            $slug = $base.'-'.Str::lower(Str::random(6));
        } while (self::where('slug', $slug)->exists());

        return $slug;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The products, in the seller's order. */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'bundle_items')
            ->withPivot('position')
            ->orderBy('bundle_items.position');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null && $this->published_at->lte(now());
    }

    /** Plain text, as a product's. */
    public function displayDescription(): string
    {
        return trim(strip_tags((string) $this->description));
    }
}
