<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Who one broadcast went to, and what happened. One row per account, ever. */
class BroadcastRecipient extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'inboxed' => 'boolean',
            'sent_at' => 'datetime',
        ];
    }

    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(Broadcast::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
