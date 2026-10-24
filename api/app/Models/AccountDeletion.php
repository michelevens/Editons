<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An account scheduled for deletion, cancelled, or deleted
 * (App\Platform\Trust\Offboarding). Kept after the account is emptied: it
 * carries the destruction certificate.
 */
class AccountDeletion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'plan' => 'array',
            'certificate' => 'array',
            'suspended_by_us' => 'boolean',
            'scheduled_for' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function graceOver(): bool
    {
        return $this->scheduled_for->isPast();
    }
}
