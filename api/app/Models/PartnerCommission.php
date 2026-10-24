<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a partner's ledger (App\Commerce\Partners\Commissions).
 *
 * A `sale` row per order item: its rate, base and amount copied when the
 * order was paid, never recomputed. A refund before payout grows
 * reversed_cents; a refund after payout is a negative `adjustment` row.
 * `forfeit` rows are staff taking a suspended partner's balance, with a
 * reason. What a row is worth now is amount_cents − reversed_cents.
 */
class PartnerCommission extends Model
{
    public const STATUSES = ['pending', 'approved', 'paid', 'reversed'];

    public const KINDS = ['sale', 'adjustment', 'forfeit'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'rate_bps' => 'integer',
            'base_cents' => 'integer',
            'amount_cents' => 'integer',
            'reversed_cents' => 'integer',
            'hold_until' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }

    /** What this row is worth now. */
    public function net(): int
    {
        return $this->amount_cents - $this->reversed_cents;
    }
}
