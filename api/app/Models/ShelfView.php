<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How somebody looks at a shelf (App\Shelf\Shelf::normalise): the last
 * way they did (name null, restored on return) or a view they named.
 * user_id and scope are set by App\Shelf\ShelfViews, never from input.
 */
class ShelfView extends Model
{
    protected $fillable = ['name', 'state'];

    protected function casts(): array
    {
        return ['state' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
