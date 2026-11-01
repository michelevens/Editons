<?php

namespace App\Models;

use App\Publishing\BookPart;
use App\Publishing\Suggestions;
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

        // A part's chapters go with it. Soft deletes never reach the
        // database's cascade, so deleting a part left its chapters live
        // under a deleted parent: invisible in the binder, undeletable,
        // and still counted, reviewed and exported (ADR — Author
        // walkthrough fixes).
        static::deleting(function (self $section) {
            if (! $section->isForceDeleting()) {
                $section->children()->get()->each->delete();
            }
        });
    }

    /**
     * Is this block of text one of the scaffold's chapter prompts
     * (App\Journey\Scaffolder)? A question put there for the author is not
     * their writing: it counts as no words, and a chapter holding only its
     * prompt is unwritten.
     */
    public static function isPrompt(string $text): bool
    {
        static $prompts = null;

        $prompts ??= array_flip(array_filter([
            'What happens in this chapter?',
            ...array_column(config('booktypes', []), 'chapter_prompt'),
        ]));

        return isset($prompts[trim(preg_replace('/\s+/u', ' ', $text))]);
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
     *
     * Counts the book as it stands: pending suggestions as if rejected
     * (Suggestions::published), the same text every export prints.
     */
    public static function countWords(?array $doc): int
    {
        $doc = Suggestions::published($doc);

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

        // Block by block, so a scaffold prompt left in place counts nothing.
        foreach ($doc['content'] ?? [] as $block) {
            if (! is_array($block)) {
                continue;
            }

            $before = strlen($text);
            $walk($block);

            if (self::isPrompt(substr($text, $before))) {
                $text = substr($text, 0, $before);
            }
        }

        $trimmed = trim(preg_replace('/\s+/u', ' ', $text));

        return $trimmed === '' ? 0 : count(explode(' ', $trimmed));
    }

    /**
     * The section's prose as plain text.
     *
     * Paragraph boundaries become blank lines so a reviewer can tell
     * paragraphs apart; a block inside another (a list item, a quoted
     * paragraph) gets a line of its own. Drivers receive this rather
     * than the ProseMirror tree — a provider should not need to understand
     * the document format, and offsets into this string are what findings
     * anchor to.
     *
     * The book as it stands, pending suggestions read as rejected, unless
     * $withSuggestions asks for every word on the page, inserted and
     * deleted alike (what a comment's excerpt may have been copied from).
     */
    public function plainText(bool $withSuggestions = false): string
    {
        return self::flatten($withSuggestions ? $this->content : Suggestions::published($this->content), false);
    }

    /**
     * The text an editorial reviewer reads (ADR — Safe apply, honest
     * prices, citation fixes): plainText() with each list item marked
     * ("- ", "1. ", indented when nested) and each quoted line marked
     * ("> "). Without the markers a reviewer read a list as a run of
     * sentences and reported "missing break between list items" in every
     * chapter, billed. Findings and selections anchor to this text.
     */
    public function reviewText(): string
    {
        return self::flatten(Suggestions::published($this->content), true);
    }

    /**
     * Top-level blocks apart by a blank line; every block inside them (a
     * list item, a quoted paragraph, a table cell) on its own line. They
     * used to run together: "…everything else.Add daily immersion…".
     *
     * @param  array<string, mixed>|null  $content
     */
    private static function flatten(?array $content, bool $markers): string
    {
        if (! $content) {
            return '';
        }

        $blocks = [];

        foreach ($content['content'] ?? [] as $block) {
            if (is_array($block)) {
                $lines = [];
                self::lines($block, $lines, $markers, '', '');
                $t = trim(implode("\n", $lines));

                if ($t !== '') {
                    $blocks[] = $t;
                }
            }
        }

        return implode("\n\n", $blocks);
    }

    /**
     * The lines of one node: a text block is one line (its first line
     * prefixed with $first, any after a hard break with $rest); a container
     * gives each child its own lines.
     *
     * @param  array<string, mixed>  $node
     * @param  list<string>  $lines
     */
    private static function lines(array $node, array &$lines, bool $markers, string $first, string $rest): void
    {
        $children = array_values(array_filter($node['content'] ?? [], 'is_array'));
        $container = collect($children)->contains(fn (array $c) => isset($c['content']));

        if (! $container) {
            $text = self::inline($node);

            foreach (explode("\n", $text) as $i => $line) {
                if (trim($line) !== '') {
                    $lines[] = ($i === 0 && $first !== null ? $first : $rest).trim($line);
                    $first = $rest;
                }
            }

            return;
        }

        $type = $node['type'] ?? null;
        $number = (int) ($node['attrs']['start'] ?? 1);

        foreach ($children as $i => $child) {
            [$childFirst, $childRest] = match (true) {
                ! $markers => [$rest, $rest],
                $type === 'bulletList' || $type === 'taskList' => [$rest.'- ', $rest.'  '],
                $type === 'orderedList' => [$rest.($number + $i).'. ', $rest.str_repeat(' ', strlen(($number + $i).'. '))],
                $type === 'blockquote' => [($i === 0 ? $first : $rest).'> ', $rest.'> '],
                default => [$i === 0 ? $first : $rest, $rest],
            };

            self::lines($child, $lines, $markers, $childFirst, $childRest);
        }
    }

    /** @param  array<string, mixed>  $node */
    private static function inline(array $node): string
    {
        if (($node['type'] ?? null) === 'hardBreak') {
            return "\n";
        }

        $text = $node['text'] ?? '';

        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                $text .= self::inline($child);
            }
        }

        return $text;
    }

    /**
     * This section as the book prints it: a copy whose content has every
     * pending suggestion rejected (Suggestions::published). For renderers
     * only, never saved: the canonical content keeps the suggestions.
     */
    public function asPublished(): static
    {
        if (Suggestions::count($this->content) === 0) {
            return $this;
        }

        $copy = clone $this;
        $copy->content = Suggestions::published($this->content);

        return $copy;
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
