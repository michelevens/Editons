<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invitation to join Écrivain, sent from the console
 * (App\Growth\Invitations\Invitations).
 *
 *   pending → accepted   (registered through /join/{token}, perks applied)
 *           → expired    (past expires_at; invitations:expire writes it)
 *           → revoked    (by staff)
 *           → declined   (the person asked not to be invited again)
 *
 * The token is never stored: token_hash is its SHA-256, and the raw value
 * exists only in the link that was emailed. A resend mints a new token, so
 * the older link stops working.
 */
class AuthorInvitation extends Model
{
    public const STATUSES = ['pending', 'accepted', 'expired', 'revoked', 'declined'];

    public const LANGUAGES = ['en' => 'English', 'fr' => 'Français', 'ht' => 'Kreyòl ayisyen'];

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'perks' => 'array',
            'perks_applied' => 'array',
            'sent_at' => 'datetime',
            'last_resent_at' => 'datetime',
            'resend_count' => 'integer',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'declined_at' => 'datetime',
            'expired_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function acceptedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_user_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AuthorInvitationBatch::class, 'batch_id');
    }

    /** Pending and not past its date: the only state in which the link works. */
    public function isLive(): bool
    {
        return $this->status === 'pending' && $this->expires_at->isFuture();
    }

    /**
     * What the console and the join page call its state. A pending row past
     * its date is expired whether or not the scheduled job has run yet.
     */
    public function state(): string
    {
        return $this->status === 'pending' && ! $this->expires_at->isFuture() ? 'expired' : $this->status;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findByToken(string $token): ?self
    {
        // A token is 48 URL-safe characters; anything else is not worth a query.
        if (! preg_match('/^[A-Za-z0-9_-]{32,96}$/', $token)) {
            return null;
        }

        return self::where('token_hash', self::hashToken($token))->first();
    }

    /** The perks with defaults, as the rest of the code reads them. @return array{plan: ?string, plan_months: ?int, credits: ?int} */
    public function perkList(): array
    {
        $perks = $this->perks ?? [];

        return [
            'plan' => $perks['plan'] ?? null,
            'plan_months' => isset($perks['plan_months']) ? (int) $perks['plan_months'] : null,
            'credits' => isset($perks['credits']) ? (int) $perks['credits'] : null,
        ];
    }
}
