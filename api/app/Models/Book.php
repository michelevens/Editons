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

    public const STATUSES = ['draft', 'in_review', 'published', 'archived'];

    protected $fillable = [
        'title', 'subtitle', 'author_name', 'genre', 'language', 'status',
        'description', 'target_words', 'settings',
        'layout', 'cover', 'meta', 'list_price', 'isbn', 'rights',
        'launch_date', 'launch_tasks', 'notes', 'marketing_kit',
    ];

    /** Interior layout, with the defaults the Design screen opens on. */
    public const DEFAULT_LAYOUT = [
        'trim' => '6 x 9 in',
        'margins' => 'Standard',
        'font' => 'serif',
        'size' => 11.0,
        'leading' => 1.45,
        'dropcap' => false,
        'headers' => true,
    ];

    /** Print trim sizes offered on the Design screen. */
    public const TRIMS = ['5 x 8 in', '5.5 x 8.5 in', '6 x 9 in', '7 x 10 in', '8.5 x 11 in'];

    /** Formats that each need their own ISBN. */
    public const EDITION_FORMATS = ['ebook', 'paperback', 'hardcover', 'large_print', 'audiobook'];

    public const EDITION_TYPES = ['workbook', 'translation', 'large_print'];

    /** Manual rights attestations, all false until the author ticks them. */
    public const DEFAULT_RIGHTS = [
        'registered_copyright' => false,
        'permissions_cleared' => false,
        'image_licenses' => false,
    ];

    /** Per-book writing preferences, with their defaults. */
    public const DEFAULT_SETTINGS = [
        'autosave' => true,
        'inline_ai' => true,
        'review_on_save' => false,
    ];

    /**
     * user_id and word_count are deliberately NOT fillable.
     *
     * Ownership is set by the controller from the authenticated user, never
     * from request input — a mass-assignable user_id is how a book gets
     * reassigned to someone else's account. word_count is derived from
     * section content and would be a lie if a client could set it.
     */
    /** Settings with defaults applied, so a missing key is never undefined. */
    public function settings(): array
    {
        return array_merge(self::DEFAULT_SETTINGS, $this->settings ?? []);
    }

    /** Layout with defaults applied, so the Design screen never reads null. */
    public function layout(): array
    {
        return array_merge(self::DEFAULT_LAYOUT, $this->layout ?? []);
    }

    /** Rights attestations with defaults applied. */
    public function rights(): array
    {
        return array_merge(self::DEFAULT_RIGHTS, $this->rights ?? []);
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'layout' => 'array',
            'cover' => 'array',
            'meta' => 'array',
            'rights' => 'array',
            'launch_date' => 'date',
            'launch_tasks' => 'array',
            'marketing_kit' => 'array',
            'list_price' => 'decimal:2',
            'word_count' => 'integer',
        ];
    }

    /** Stores this book is offered in. */
    public function channels(): HasMany
    {
        return $this->hasMany(BookChannel::class);
    }

    /** Generated files, newest first. Append-only, so this is a history. */
    public function exports(): HasMany
    {
        return $this->hasMany(BookExport::class)->latest();
    }

    /** People reading the book before launch, to review it. */
    public function advanceReaders(): HasMany
    {
        return $this->hasMany(AdvanceReader::class);
    }

    /** ISBNs assigned to this book's editions. */
    public function isbns(): HasMany
    {
        return $this->hasMany(Isbn::class);
    }

    /** The book this edition was derived from, if any. */
    public function parentBook(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_book_id');
    }

    /** Editions derived from this book. */
    public function editions(): HasMany
    {
        return $this->hasMany(self::class, 'parent_book_id');
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

    /** Everyone other than the owner who can reach this book. */
    public function members(): HasMany
    {
        return $this->hasMany(BookMember::class);
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
