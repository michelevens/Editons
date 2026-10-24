<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One "View as" session (App\Platform\Impersonation). */
class ImpersonationSession extends Model
{
    public const END_REASONS = ['stopped', 'expired', 'signed_out', 'revoked'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** Not ended, and inside its hard limit. */
    public function isLive(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }

    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_id');
    }
}
