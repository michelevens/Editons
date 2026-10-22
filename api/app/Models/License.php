<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * The right to download one order item's files.
 *
 * Issued when the order is paid; revoked by a full refund. The key is what
 * the buyer quotes, and what a download link names — but a link only works
 * with a valid, unexpired signature (App\Commerce\Downloads), so knowing a
 * key is not enough to download.
 */
class License extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'download_count' => 'integer',
            'last_downloaded_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** "LIC-7Q4M-2KX9-H8RD-W3NB" */
    public static function newKey(): string
    {
        do {
            $key = 'LIC-'.implode('-', str_split(Str::upper(Str::random(16)), 4));
        } while (self::where('key', $key)->exists());

        return $key;
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
