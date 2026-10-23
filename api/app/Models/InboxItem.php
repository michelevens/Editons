<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One thing in somebody's inbox. See App\Inbox\Inbox. */
class InboxItem extends Model
{
    public const KINDS = [
        'comment', 'reply', 'mention', 'workflow', 'assigned', 'team',
        'export_ready', 'export_failed', 'review',
    ];

    protected $fillable = [
        'user_id', 'book_id', 'actor_id', 'kind', 'title', 'body', 'target',
        'group_key', 'occurred_at', 'read_at', 'done_at', 'snoozed_until',
    ];

    protected function casts(): array
    {
        return [
            'target' => 'array',
            'occurred_at' => 'datetime',
            'read_at' => 'datetime',
            'done_at' => 'datetime',
            'snoozed_until' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * A person's items about books they can still open.
     *
     * Somebody removed from a team keeps their rows, but no longer sees what
     * happens in a book they have lost, not even its title.
     */
    public function scopeFor(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id)
            ->where(fn ($q) => $q->whereNull('book_id')
                ->orWhereIn('book_id', $user->accessibleBooks()->select('books.id')));
    }

    /** Not done, and not snoozed past now. */
    public function scopeWaiting(Builder $query): Builder
    {
        return $query->whereNull('done_at')
            ->where(fn ($q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()));
    }

    public function scopeSnoozed(Builder $query): Builder
    {
        return $query->whereNull('done_at')->where('snoozed_until', '>', now());
    }
}
