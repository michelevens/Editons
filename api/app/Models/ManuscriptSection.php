<?php

namespace App\Models;

use App\Publishing\BookPart;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A node in the manuscript binder: front matter, a part, a chapter, a
 * sub-section, or back matter.
 *
 * `content` is ProseMirror JSON (ADR-0004) — a structural tree, not markup.
 * That is what lets one manuscript render to DOCX, EPUB and PDF without
 * inferring intent from HTML.
 */
class ManuscriptSection extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const TYPES = ['front_matter', 'part', 'chapter', 'section', 'back_matter'];

    /** Gap between sibling positions, so a drop between two needs no renumber. */
    public const POSITION_STEP = 10;

    protected $fillable = [
        'type', 'part', 'title', 'subtitle', 'content', 'position', 'status', 'reviewer_id',
    ];

    /**
     * A new section is named from its title when nobody named it: the
     * scaffolder's "Preface", an import's "Épilogue", the binder's "Chapter
     * 4". Only on create: renaming a chapter later never silently changes
     * what kind of page it is.
     */
    protected static function booted(): void
    {
        static::creating(function (self $section) {
            if ($section->part === null) {
                $language = $section->book_id
                    ? Book::whereKey($section->book_id)->value('language')
                    : null;

                $section->part = BookPart::infer($section->title, $section->type, $language)?->value;
            }

            // A section created with content counts its words, whoever
            // created it: editions, templates and imports copied content in
            // and left the count at 0 until somebody re-saved the chapter.
            if (! $section->isDirty('word_count') && $section->content) {
                $section->word_count = self::countWords($section->content);
            }
        });
    }

    /** The conventional part this section is, if it has been named. */
    public function bookPart(): ?BookPart
    {
        return $this->part ? BookPart::tryFrom($this->part) : null;
    }

    /** The review states a chapter moves through, in board order. */
    public const STATUSES = ['draft', 'review', 'revisions', 'approved'];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'word_count' => 'integer',
            'word_goal' => 'integer',
            'position' => 'integer',
        ];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ManuscriptVersion::class)->latest('version_number');
    }

    /** Comment threads anchored in this section. */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /** Claims in this section and the sources backing them. */
    /** Who is reviewing this chapter, for the workflow board. */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function citations(): HasMany
    {
        return $this->hasMany(Citation::class);
    }

    /**
     * Count words in a ProseMirror document.
     *
     * Walks the node tree collecting text nodes rather than stripping tags
     * from rendered HTML: the JSON is the canonical form, and rendering it
     * just to count would introduce a second source of truth.
     *
     * Deliberately simple — whitespace-separated tokens. A "word" in a
     * manuscript is a human-facing estimate shown next to reading time, not
     * a figure anything depends on.
     */
    public static function countWords(?array $doc): int
    {
        if (! $doc) {
            return 0;
        }

        $text = '';
        $walk = function (array $node) use (&$walk, &$text): void {
            if (isset($node['text'])) {
                $text .= ' '.$node['text'];
            }
            foreach ($node['content'] ?? [] as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($doc);

        $trimmed = trim(preg_replace('/\s+/u', ' ', $text));

        return $trimmed === '' ? 0 : count(explode(' ', $trimmed));
    }

    /**
     * The section's prose as plain text.
     *
     * Paragraph boundaries become blank lines so a reviewer can tell
     * paragraphs apart; everything else flattens. Drivers receive this rather
     * than the ProseMirror tree — a provider should not need to understand
     * the document format, and offsets into this string are what findings
     * anchor to.
     */
    public function plainText(): string
    {
        if (! $this->content) {
            return '';
        }

        $blocks = [];
        $walk = function (array $node, bool $topLevel = false) use (&$walk, &$blocks): string {
            $text = $node['text'] ?? '';
            foreach ($node['content'] ?? [] as $child) {
                if (is_array($child)) {
                    $text .= $walk($child);
                }
            }

            return $text;
        };

        foreach ($this->content['content'] ?? [] as $block) {
            if (is_array($block)) {
                $t = trim($walk($block));
                if ($t !== '') {
                    $blocks[] = $t;
                }
            }
        }

        return implode("\n\n", $blocks);
    }

    /** A part is a container; it holds chapters rather than prose. */
    public function isContainer(): bool
    {
        return $this->type === 'part';
    }

    /**
     * Next free position among a parent's children.
     *
     * Sparse by POSITION_STEP so reordering can pick a midpoint instead of
     * rewriting every sibling row.
     */
    public static function nextPosition(int $bookId, ?int $parentId): int
    {
        $max = static::where('book_id', $bookId)
            ->where('parent_id', $parentId)
            ->max('position');

        return (int) $max + self::POSITION_STEP;
    }
}
