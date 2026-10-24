<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One bulk send of invitations, from a pasted list or a CSV file. */
class AuthorInvitationBatch extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rows_total' => 'integer',
            'sent' => 'integer',
            'skipped' => 'integer',
        ];
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(AuthorInvitation::class, 'batch_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** "Batch #4 · spring-beta" */
    public function label(): string
    {
        return 'Batch #'.$this->id.($this->campaign ? ' · '.$this->campaign : '');
    }
}
