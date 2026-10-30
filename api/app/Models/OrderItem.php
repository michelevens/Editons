<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One product in an order, as it was when it was sold.
 *
 * Title, formats, license and price are copies, so editing or deleting the
 * product later never changes a past sale. fee_cents and royalty_cents are
 * set when the order is paid (App\Commerce\Revenue).
 */
class OrderItem extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'formats' => 'array',
            'price_cents' => 'integer',
            'fee_cents' => 'integer',
            'royalty_cents' => 'integer',
            'refunded_cents' => 'integer',
            'print_cost_cents' => 'integer',
            'list_price_cents' => 'integer',
            'release_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class)->withTrashed();
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function license(): HasOne
    {
        return $this->hasOne(License::class);
    }

    /** A paperback's print job at Lulu; none for a digital item. */
    public function printJob(): HasOne
    {
        return $this->hasOne(PrintJob::class);
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Bundle::class);
    }

    /** Pre-ordered and not released yet: no licence, no downloads. */
    public function awaitingRelease(): bool
    {
        return $this->delivered_at === null && $this->release_at !== null && $this->release_at->isFuture();
    }

    public function isPaperback(): bool
    {
        return in_array('paperback', $this->formats ?? [], true);
    }
}
