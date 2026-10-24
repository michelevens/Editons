<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One plan in the catalogue (App\Billing\PlanCatalog).
 *
 * Written only by App\Billing\PlanEditor, from the owner's console, with an
 * audit row; nothing an author sends reaches it.
 */
class Plan extends Model
{
    public const VISIBILITIES = ['public', 'hidden', 'legacy'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'credits' => 'integer',
            'books' => 'integer',
            'seats' => 'integer',
            'formats' => 'array',
            'tier' => 'integer',
            'sort' => 'integer',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class);
    }

    /**
     * The shape config/billing.php has always had, so every caller of
     * planLimits() reads the same keys it did — plus what the catalogue adds.
     *
     * `price` stays whole dollars when it is a whole number (19, not 19.0),
     * which is what the config said and what the pages print.
     *
     * @return array<string, mixed>
     */
    public function toLimits(): array
    {
        return [
            'name' => $this->name,
            'price' => self::dollars($this->price_cents),
            'credits' => $this->credits,
            'books' => $this->books,
            'seats' => $this->seats,
            'formats' => array_values($this->formats ?? []),
            'tier' => $this->tier,
            'key' => $this->key,
            'price_cents' => $this->price_cents,
            'currency' => $this->currency,
            'visibility' => $this->visibility,
            'stripe_price_id' => $this->stripe_price_id,
        ];
    }

    public static function dollars(int $cents): int|float
    {
        return $cents % 100 === 0 ? intdiv($cents, 100) : $cents / 100;
    }
}
