<?php

namespace App\Research;

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Publishing\Anatomy;
use App\Publishing\BookPart;

/**
 * The reference list an export adds when the manuscript cites sources
 * (ADR — Journey friction fixes).
 *
 * Citations used to live only on the References screen: an author who
 * cited ten sources exported a book with none of them in it. When at least
 * one citation names a source, every export that walks the book's anatomy
 * (PDF, EPUB, Word) now ends with a Bibliography of the cited sources, in
 * the book's citation style, from the same formatter as the References
 * screen (Bibliography::forBook) so the two never disagree.
 *
 * Nothing changes for a book without cited sources, for a book whose author
 * wrote their own Bibliography section (theirs takes its place, as a
 * hand-written copyright page takes the generated one's), or when the book's
 * `references` setting is off. An export profile can leave it out like any
 * other back matter (its matter key is `bibliography`).
 *
 * The list is a section that is never saved: it reaches the renderers the
 * way any back-matter section does, so each format sets it in its own
 * style, with no renderer of its own to drift.
 */
final class BookReferences
{
    /** The unsaved section's id. Negative, so it can never meet a real row. */
    public const SECTION_ID = -1;

    public static function enabled(Book $book): bool
    {
        return (bool) ($book->settings()['references'] ?? true);
    }

    /**
     * The generated Bibliography section, or null when the book gets none.
     * Built on each call: citations change without touching the book, and
     * a queue worker outlives any one export.
     */
    public static function section(Book $book): ?ManuscriptSection
    {
        if (! self::enabled($book)) {
            return null;
        }

        // The author's own list wins.
        if ($book->sections()->where('part', BookPart::Bibliography->value)->exists()) {
            return null;
        }

        $cited = $book->citations()
            ->whereNotNull('research_source_id')
            ->pluck('research_source_id')
            ->unique()
            ->all();

        if ($cited === []) {
            return null;
        }

        $style = Bibliography::normalise($book->settings()['citation_style'] ?? null);
        $entries = array_values(array_filter(
            app(Bibliography::class)->forBook($book, $style),
            fn (array $entry) => in_array($entry['id'], $cited, true),
        ));

        if ($entries === []) {
            return null;
        }

        $section = new ManuscriptSection;
        $section->forceFill([
            'id' => self::SECTION_ID,
            'book_id' => $book->id,
            'parent_id' => null,
            'type' => 'back_matter',
            'part' => BookPart::Bibliography->value,
            'title' => BookPart::Bibliography->title(Anatomy::language($book)),
            'position' => PHP_INT_MAX,
            'content' => [
                'type' => 'doc',
                'content' => array_map(fn (array $entry) => self::paragraph($entry['html']), $entries),
            ],
        ]);
        $section->exists = false;
        $section->setRelation('book', $book);

        return $section;
    }

    /**
     * One entry as a paragraph, its <i> runs (Bibliography::html) as italic
     * marks.
     *
     * @return array<string, mixed>
     */
    private static function paragraph(string $html): array
    {
        $runs = [];

        foreach (preg_split('/(<i>.*?<\/i>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $piece) {
            $italic = str_starts_with($piece, '<i>');
            $text = html_entity_decode(strip_tags($piece), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if ($text === '') {
                continue;
            }

            $runs[] = ['type' => 'text', 'text' => $text] + ($italic ? ['marks' => [['type' => 'italic']]] : []);
        }

        return ['type' => 'paragraph', 'content' => $runs];
    }
}
