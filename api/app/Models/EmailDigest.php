<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One digest for one person and period: the idempotency key (App\Mail\HeldEmails). */
class EmailDigest extends Model
{
    protected $fillable = ['user_id', 'kind', 'period', 'items', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function heldEmails(): HasMany
    {
        return $this->hasMany(HeldEmail::class);
    }
}
