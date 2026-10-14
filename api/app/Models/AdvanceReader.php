<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody reading the book before it is published, to review it at launch.
 */
class AdvanceReader extends Model
{
    use HasFactory;

    public const STATUSES = ['invited', 'sent', 'reading', 'reviewed', 'declined'];

    protected $fillable = ['name', 'email', 'status'];

    /**
     * copy_sent_at and reviewed_at are not fillable: when a copy went out and
     * when a review arrived are records of what happened, and a client
     * claiming either would make the launch timeline lie.
     */
    protected function casts(): array
    {
        return [
            'copy_sent_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
