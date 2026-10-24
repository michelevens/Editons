<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A zip of everything an account holds, built on the worker (App\Platform\Trust\AccountExporter). */
class AccountExport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'contents' => 'array',
            'byte_size' => 'integer',
            'email_account' => 'boolean',
            'emailed_at' => 'datetime',
            'expires_at' => 'datetime',
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

    public function isDownloadable(): bool
    {
        return $this->status === 'ready' && $this->path && (! $this->expires_at || $this->expires_at->isFuture());
    }
}
