<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One email to many accounts (App\Growth\Broadcasts). */
class Broadcast extends Model
{
    public const STATUSES = ['draft', 'scheduled', 'sending', 'sent', 'cancelled'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'audience_rules' => 'array',
            'inbox' => 'boolean',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'recipients_total' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
            'skipped_count' => 'integer',
        ];
    }

    public function audience(): BelongsTo
    {
        return $this->belongsTo(Audience::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(BroadcastRecipient::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editable(): bool
    {
        return in_array($this->status, ['draft', 'scheduled'], true);
    }
}
