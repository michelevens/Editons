<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A monthly royalty statement that was sent. See commerce:royalty-statements. */
class RoyaltyStatement extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'units' => 'integer',
            'royalty_cents' => 'integer',
            'sent_at' => 'datetime',
        ];
    }
}
