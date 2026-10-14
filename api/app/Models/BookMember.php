<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Somebody other than the owner who can reach a book.
 *
 * The owner is not represented here — ownership is books.user_id. Two places
 * recording the same fact is two places for it to disagree.
 */
class BookMember extends Model
{
    use HasFactory;

    public const ROLES = ['editor', 'reviewer', 'viewer'];

    public const STATUSES = ['invited', 'active', 'revoked'];

    /** How long an invitation link stays usable. */
    public const INVITE_DAYS = 14;

    protected $fillable = ['email', 'role', 'status', 'user_id', 'invited_by'];

    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** Issue a fresh single-use token and stamp the time it was sent. */
    public function issueInvite(): string
    {
        $token = Str::random(64);

        $this->forceFill([
            'invite_token' => $token,
            'invited_at' => now(),
            'status' => 'invited',
        ])->save();

        return $token;
    }

    /**
     * Is this invitation still usable?
     *
     * An accepted one is not: the token is spent. An expired one is not
     * either, because a link that works forever is a credential travelling
     * by email.
     */
    public function inviteIsLive(): bool
    {
        return $this->status === 'invited'
            && $this->invite_token !== null
            && $this->invited_at?->gt(now()->subDays(self::INVITE_DAYS));
    }

    /** May this member change the manuscript? */
    public function canEdit(): bool
    {
        return $this->status === 'active' && $this->role === 'editor';
    }
}
