<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A comment on a passage, or a reply within a thread.
 *
 * Only top-level comments carry an anchor and a status; a reply inherits both
 * from its parent. The database enforces that shape rather than trusting
 * every caller to remember it.
 */
class Comment extends Model
{
    use HasFactory;

    public const STATUSES = ['open', 'resolved'];

    protected $fillable = [
        'manuscript_section_id', 'parent_id', 'user_id',
        'author_name', 'body', 'excerpt', 'status',
    ];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(ManuscriptSection::class, 'manuscript_section_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** The thread a reply belongs to; null on a top-level comment. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('created_at');
    }

    public function isReply(): bool
    {
        return $this->parent_id !== null;
    }

    /**
     * Has the passage this refers to been edited away?
     *
     * Same problem the editorial findings have, and the same answer: compare
     * the stored excerpt against the current text. A thread pointing at a
     * deleted sentence should say so rather than appear to be about whatever
     * now sits in that position.
     */
    public function isDetached(string $currentText): bool
    {
        if (! $this->excerpt) {
            return false;
        }

        return ! str_contains($currentText, $this->excerpt);
    }
}
