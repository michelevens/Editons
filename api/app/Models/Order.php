<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Str;

/**
 * One checkout.
 *
 * Created as `pending` before the buyer is sent to Stripe, so the Checkout
 * Session can carry its id; marked `paid` only by a signed
 * checkout.session.completed event whose amount matches. Nothing a browser
 * sends can mark an order paid.
 *
 * Nothing is fillable: every field is set by App\Commerce code.
 */
class Order extends Model
{
    public const STATUSES = ['pending', 'paid', 'partially_refunded', 'refunded', 'cancelled'];

    /** Statuses in which the buyer paid and has not had everything back. */
    public const COUNTED = ['paid', 'partially_refunded'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'total_cents' => 'integer',
            'fee_cents' => 'integer',
            'refunded_cents' => 'integer',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'shipping_cents' => 'integer',
            // A paperback buyer's address and phone, for the printer only.
            'shipping_address' => 'encrypted:array',
        ];
    }

    /** "EC-7KQ2M9XA": short enough to read over the phone. */
    public static function newNumber(): string
    {
        do {
            $number = 'EC-'.Str::upper(Str::random(8));
        } while (self::where('number', $number)->exists());

        return $number;
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function licenses(): HasManyThrough
    {
        return $this->hasManyThrough(License::class, OrderItem::class);
    }

    public function printJobs(): HasMany
    {
        return $this->hasMany(PrintJob::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }
}
