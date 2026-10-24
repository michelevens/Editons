<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One email handed to the mailer. Written by App\Platform\Ops\MailLogger only. */
class MailLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sensitive' => 'boolean',
            'resend_args' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resentFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'resent_from_id');
    }

    public function resendable(): bool
    {
        return $this->resend_args !== null
            && $this->mailable !== null
            && class_exists($this->mailable);
    }
}
