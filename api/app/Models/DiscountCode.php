<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A code that takes money off a purchase (App\Growth\Discounts): the
 * owner's (seller_id null) or an author's own (seller_id).
 */
class DiscountCode extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'percent_off' => 'integer',
            'amount_off_cents' => 'integer',
            'applies_to_plans' => 'boolean',
            'applies_to_storefront' => 'boolean',
            'plan_keys' => 'array',
            'max_redemptions' => 'integer',
            'expires_at' => 'datetime',
            'first_time_only' => 'boolean',
            'active' => 'boolean',
            // An author's own code: their products and bundles, or all.
            'product_ids' => 'array',
            'bundle_ids' => 'array',
        ];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(DiscountRedemption::class);
    }

    /** "20% off", "USD 5.00 off". */
    public function label(): string
    {
        return $this->kind === 'percent'
            ? $this->percent_off.'% off'
            : strtoupper((string) $this->currency).' '.number_format($this->amount_off_cents / 100, 2).' off';
    }
}
