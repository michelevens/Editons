<?php

namespace App\Models;

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

    protected $fillable = ['type', 'title', 'subtitle', 'content', 'position'];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'word_count' => 'integer',
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
