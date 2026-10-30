<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An email kept back for a digest or until quiet hours end
 * (App\Mail\HeldEmails).
 */
class HeldEmail extends Model
{
    protected $fillable = [
        'user_id', 'book_id', 'category', 'mode', 'subject', 'url',
        'mailable', 'release_at', 'email_digest_id', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'release_at' => 'datetime',
            'sent_at' => 'datetime',
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
}
