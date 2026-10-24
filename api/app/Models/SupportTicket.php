<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A request for help from an author, and the conversation about it.
 *
 * open: waiting for staff · pending: answered, waiting for the author ·
 * closed. An author sees only their own, and never the internal notes.
 */
class SupportTicket extends Model
{
    public const STATUSES = ['open', 'pending', 'closed'];

    protected $fillable = ['user_id', 'book_id', 'subject', 'status', 'assignee_id', 'context', 'last_message_at', 'closed_at'];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'last_message_at' => 'datetime',
            'closed_at' => 'datetime',
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

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class)->orderBy('id');
    }

    /** What the author may read: everything but staff's internal notes. */
    public function publicMessages(): HasMany
    {
        return $this->messages()->where('internal', false);
    }
}
