<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One message on a support ticket: the author's, a staff reply, or an internal note. */
class SupportMessage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['support_ticket_id', 'author_id', 'from_staff', 'internal', 'body', 'ai_drafted'];

    protected function casts(): array
    {
        return [
            'from_staff' => 'boolean',
            'internal' => 'boolean',
            'ai_drafted' => 'boolean',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
