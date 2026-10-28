<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named book design an account keeps and applies to its books
 * (App\Publishing\HouseStyles). user_id is the account that owns it — the
 * owner of the books it is applied to — set by the service, never from
 * input.
 */
class HouseStyle extends Model
{
    protected $fillable = ['name', 'design'];

    protected function casts(): array
    {
        return ['design' => 'array', 'version' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The books that follow it. */
    public function books(): HasMany
    {
        return $this->hasMany(Book::class);
    }
}
