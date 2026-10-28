<?php

namespace App\Models;

use App\Publishing\AccessibilityAudit;
use App\Publishing\CoverRenderer;
use App\Publishing\InteriorDesign;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'studio', 'book_type', 'started_with', 'assistance',
        'journey_stage', 'journey_tasks',
        'premise', 'audience', 'promise',
        'series_name', 'series_number', 'publication',
    ];

    /**
     * What the generated pages print (App\Publishing\Anatomy). Every key
     * defaults to null: an empty field prints nothing, it is never filled
     * in with a guess.
     *
     * credits is a list of {role, name}; the rest are strings, except
     * copyright_year, an integer.
     */
    public const DEFAULT_PUBLICATION = [
        'publisher' => null,
        'imprint' => null,
        'place' => null,
        'edition_statement' => null,
        'copyright_holder' => null,
        'copyright_year' => null,
        'rights_statement' => null,
        'isbn_print' => null,
        'isbn_epub' => null,
        'isbn_pdf' => null,
        'legal_deposit' => null,
        // ISO 3166 code of the country of publication: which ISBN agency
        // and which legal deposit rules apply (config/publishing.php).
        'country' => null,
        'printing' => null,
        'credits' => [],
        'disclaimer' => null,
    ];

    /** The two product experiences. Academic Studio is not built yet. */
    public const STUDIOS = ['author', 'academic'];

    /** How much the author wants the AI to do. */
    public const ASSISTANCE = ['author_led', 'coach', 'collaborative'];

    public const JOURNEY_STAGES = [
        'discover', 'plan', 'create', 'improve', 'design', 'publish',
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
    ] + InteriorDesign::DEFAULTS;

    /**
     * How the printed book is made: paper, interior ink and cover finish.
     * The spine width and the print cost both depend on the first two
     * (config/publishing.php). Set through the print kit, never in bulk.
     */
    public const DEFAULT_PRINT = [
        'paper' => 'white',
        'ink' => 'bw',
        'finish' => 'matte',
        // The spine and a designed back cover: null takes the colour of the
        // front cover's spine-side edge; a #rrggbb sets one, so a series
        // can share a spine on the shelf.
        'spine_color' => null,
    ];

    public const PRINT_OPTIONS = [
        'paper' => ['white', 'cream'],
        'ink' => ['bw', 'standard_color', 'premium_color'],
        'finish' => ['matte', 'glossy'],
    ];

    /** Editions an author chooses an ISBN source for (IsbnPlan). */
    public const ISBN_EDITIONS = ['ebook', 'paperback', 'hardcover'];

    /** own: the author's ISBN; store: assigned by the store (tied to it). */
    public const ISBN_SOURCES = ['own', 'store'];

    /**
     * Standard print trim sizes offered on the Design screen: every size
     * KDP and IngramSpark list for black-and-white paperbacks and
     * hardcovers (config/publishing.php, docs/POD-SPECS.md), narrowest
     * first. A custom size within the services' range is also accepted
     * (PageSetup::normaliseTrim).
     */
    public const TRIMS = [
        '4 x 6 in', '4 x 7 in', '4.25 x 7 in', '4.37 x 7 in', '4.72 x 7.48 in',
        '5 x 7 in', '5 x 8 in', '5.06 x 7.81 in', '5.25 x 8 in',
        '5.5 x 7.5 in', '5.5 x 8.25 in', '5.5 x 8.38 in', '5.5 x 8.5 in', '5.83 x 8.27 in',
        '6 x 9 in', '6.14 x 9.21 in', '6.5 x 6.5 in', '6.625 x 10.25 in', '6.69 x 9.61 in',
        '7 x 10 in', '7.44 x 9.69 in', '7.5 x 9.25 in',
        '8 x 8 in', '8 x 10 in', '8 x 10.88 in', '8.25 x 6 in', '8.25 x 8.25 in', '8.25 x 10.75 in', '8.25 x 11 in',
        '8.27 x 11.69 in', '8.5 x 8.5 in', '8.5 x 9 in', '8.5 x 11 in',
    ];

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
        // The author's choice, and what the references screen and exports use.
        'citation_style' => 'APA',
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

    /**
     * The cover, as anything showing a thumbnail needs it.
     *
     * Returns the uploaded artwork's URL when there is one, and otherwise the
     * palette the author chose, so a book with no uploaded image still has a
     * cover rather than a grey box saying NO COVER. The sidebar asked for
     * `cover_url` and nothing ever produced it, so every book in the product
     * showed that box — the demo one and everyone else's.
     *
     * @return array<string, mixed>
     */
    public function coverFor(): array
    {
        $cover = $this->cover ?? [];
        $palettes = AccessibilityAudit::PALETTES;
        $palette = $palettes[$cover['palette'] ?? 0] ?? $palettes[0];

        return [
            // The rendered front cover, always: a book with no uploaded
            // artwork still has a designed cover. Through the app, since the
            // bucket is private, and versioned by the design so a change is
            // never hidden by the cache.
            'url' => $this->exists
                ? route('design.cover.image', ['book' => $this->id, 'v' => app(CoverRenderer::class)->version($this)])
                : null,
            // The same cover at shelf size, for the library and the sidebar.
            'thumb' => $this->exists
                ? route('design.cover.image', ['book' => $this->id, 'v' => app(CoverRenderer::class)->version($this), 'size' => 'thumb'])
                : null,
            'title' => $cover['title'] ?? $this->title,
            'author' => $cover['author'] ?? $this->author_name,
            'from' => $palette['from'],
            'to' => $palette['to'],
            'ink' => $palette['ink'],
        ];
    }

    /** Layout with defaults applied, so the Design screen never reads null. */
    public function layout(): array
    {
        $stored = $this->layout ?? [];

        // The interior design groups (openers, heads, contents, boxes) are
        // filled in key by key, so a book saved before one existed, or
        // with only part of one, still reads every value.
        return array_merge(self::DEFAULT_LAYOUT, $stored, InteriorDesign::resolve($stored));
    }

    /** Print settings with defaults applied. */
    public function printSettings(): array
    {
        $print = array_merge(self::DEFAULT_PRINT, array_intersect_key($this->print ?? [], self::DEFAULT_PRINT));

        // Colour interiors print on white: the services' spine and page
        // tables have no cream colour paper.
        if ($print['ink'] !== 'bw') {
            $print['paper'] = 'white';
        }

        return $print;
    }

    /** Publishing metadata with defaults applied. */
    public function publication(): array
    {
        return array_merge(
            self::DEFAULT_PUBLICATION,
            array_intersect_key($this->publication ?? [], self::DEFAULT_PUBLICATION),
        );
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
            'print' => 'array',
            'isbn_plan' => 'array',
            'rights_declaration' => 'array',
            'ai_disclosure' => 'array',
            // The Publish screen's choices (App\Publishing\Publish\PublishPlan).
            'publish_plan' => 'array',
            'cover' => 'array',
            'meta' => 'array',
            'rights' => 'array',
            'publication' => 'array',
            'series_number' => 'integer',
            'launch_date' => 'date',
            'launch_tasks' => 'array',
            'marketing_kit' => 'array',
            'journey_tasks' => 'array',
            'list_price' => 'decimal:2',
            'word_count' => 'integer',
        ];
    }

    /** The house style this book follows, if any (App\Publishing\HouseStyles). */
    public function houseStyle(): BelongsTo
    {
        return $this->belongsTo(HouseStyle::class);
    }

    /** Stores this book is offered in. */
    public function channels(): HasMany
    {
        return $this->hasMany(BookChannel::class);
    }

    /** The book on sale in the author's own storefront, if it is. */
    public function product(): HasOne
    {
        return $this->hasOne(Product::class);
    }

    /** Generated files, newest first. Append-only, so this is a history. */
    public function exports(): HasMany
    {
        return $this->hasMany(BookExport::class)->latest();
    }

    /**
     * How many editorial reviews have been run across this book.
     *
     * Through the sections, because a review belongs to a chapter rather than
     * to the book. Used to tell whether the Improve stage has been started at
     * all, which is a different question from how many findings are open.
     */
    public function reviewsCount(): int
    {
        return EditorialReview::whereIn(
            'manuscript_section_id',
            $this->sections()->select('id'),
        )->count();
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
