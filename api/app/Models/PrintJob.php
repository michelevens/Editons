<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One paperback being printed and shipped by Lulu for a storefront order.
 *
 * Created with the paid order (App\Commerce\Fulfilment), `pending` until it
 * is sent (App\Commerce\Print\PrintJobs::submit); from then on `status` is
 * Lulu's own name for the job's state (CREATED, UNPAID, PRODUCTION_READY,
 * IN_PRODUCTION, SHIPPED, REJECTED, CANCELED, ...), or `failed` when Lulu
 * could not be reached or refused the request. Nothing is fillable: only
 * App\Commerce\Print code writes it.
 */
class PrintJob extends Model
{
    /** Lulu states after which nothing changes for us. */
    public const FINAL = ['SHIPPED', 'DELIVERED', 'REJECTED', 'CANCELED', 'ERROR'];

    /** Lulu states that mean the copy will not arrive. */
    public const PROBLEM = ['REJECTED', 'CANCELED', 'ERROR', 'failed'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'tracking' => 'array',
            'page_count' => 'integer',
            'cost_cents' => 'integer',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'polled_at' => 'datetime',
            'shipped_at' => 'datetime',
            'buyer_notified_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class)->withTrashed();
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL, true);
    }
}
