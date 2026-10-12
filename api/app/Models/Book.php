<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A book project.
 *
 * Owns a tree of manuscript sections. Editions, layout templates and products
 * attach later (ARCHITECTURE.md lists them); none exist yet.
 */
class Book extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STATUSES = ['draft', 'in_review', 'published'];

    protected $fillable = [
        'title', 'subtitle', 'author_name', 'genre', 'status', 'description',
    ];

    /**
     * user_id and word_count are deliberately NOT fillable.
     *
     * Ownership is set by the controller from the authenticated user, never
     * from request input — a mass-assignable user_id is how a book gets
     * reassigned to someone else's account. word_count is derived from
     * section content and would be a lie if a client could set it.
     */
    protected function casts(): array
    {
        return [
            'word_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Every section, flat. Use rootSections() for the binder tree. */
    public function sections(): HasMany
    {
        return $this->hasMany(ManuscriptSection::class);
    }

    /**
     * Top-level nodes in binder order, with their children eager-loaded.
     *
     * The binder renders on every editor page load, so this exists to keep
     * that one query plus one for the children, rather than N+1 across parts.
     */
    public function rootSections(): HasMany
    {
        return $this->sections()
            ->whereNull('parent_id')
            ->orderBy('position');
    }

    /** The book's research library. */
    public function sources(): HasMany
    {
        return $this->hasMany(ResearchSource::class);
    }

    /** The book's controlled vocabulary, used for cross-chapter consistency. */
    public function terms(): HasMany
    {
        return $this->hasMany(Term::class);
    }

    /** Every citation in the book, across all its sections. */
    public function citations(): HasManyThrough
    {
        return $this->hasManyThrough(Citation::class, ManuscriptSection::class);
    }

    /**
     * Recompute the denormalised word count from the sections.
     *
     * Called after a section saves. Cheap — one SUM — and keeps the dashboard
     * from deriving the figure out of every section's JSON on each render.
     *
     * Assigns the attribute directly instead of calling update(). word_count
     * is deliberately NOT fillable (a client must not be able to claim a word
     * count), and update() honours mass-assignment protection — so the
     * earlier update(['word_count' => …]) was silently discarded and every
     * book stayed at 0. The test caught it; nothing in the application would
     * have, because a wrong-but-plausible number looks like a working feature.
     */
    public function recalculateWordCount(): void
    {
        $this->word_count = (int) $this->sections()->sum('word_count');
        $this->save();
    }
}
