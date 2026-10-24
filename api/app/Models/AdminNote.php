<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An internal note about an account, for platform staff only.
 *
 * Never shown to the account itself; nothing outside the admin console reads
 * this table.
 */
class AdminNote extends Model
{
    protected $fillable = ['body', 'pinned'];

    protected function casts(): array
    {
        return ['pinned' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
