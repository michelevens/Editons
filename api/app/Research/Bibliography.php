<?php

namespace App\Research;

use App\Models\Book;
use App\Models\ResearchSource;

/**
 * Formats a book's sources as a bibliography.
 *
 * Server-side because a citation style is a publishing rule, not a display
 * preference: the same entry has to come out identically here, in an export,
 * and in anything that later checks a manuscript against its references.
 * Three implementations of it on three clients would be three chances to
 * disagree.
 *
 * These are approximations of APA, Chicago and MLA — enough to hand a
 * publisher a starting point, not a substitute for a style manual. The screen
 * says so rather than implying the output is authoritative.
 */
class Bibliography
{
    public const STYLES = ['APA', 'Chicago', 'MLA'];

    /**
     * Every source, sorted the way a bibliography is read: by author, falling
     * back to title when a source has none.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forBook(Book $book, string $style = 'APA'): array
    {
        return $book->sources()
            ->get()
            ->sortBy(fn (ResearchSource $source) => mb_strtolower(
                $source->authors ?: $source->title,
            ))
            ->values()
            ->map(fn (ResearchSource $source) => [
                'id' => $source->id,
                'title' => $source->title,
                'verification' => $source->verification,
                'entry' => $this->format($source, $style),
            ])
            ->all();
    }

    /** One entry, in the requested style. */
    public function format(ResearchSource $source, string $style = 'APA'): string
    {
        // Author fields routinely arrive already punctuated — "Smith, J."
        // is how a person types a name — and appending the style's own period
        // then yields "Smith, J..". Trim it once here rather than in three
        // match arms.
        $authors = rtrim(trim((string) $source->authors), '.');
        $year = trim((string) $source->published) ?: 'n.d.';
        $title = rtrim(trim($source->title), '.');
        $publisher = trim((string) $source->publisher);

        $entry = match ($style) {
            'Chicago' => $authors
                ? "{$authors}. {$year}. {$title}."
                : "{$title}. {$year}.",
            'MLA' => $authors
                ? "{$authors}. {$title}. {$year}."
                : "{$title}. {$year}.",
            // APA is the default rather than an error: a style that does not
            // exist should still produce a usable entry.
            default => $authors
                ? "{$authors}. ({$year}). {$title}."
                : "{$title}. ({$year}).",
        };

        if ($publisher) {
            $entry .= " {$publisher}.";
        }

        // A DOI is how a reader finds the thing, so it outranks a URL when
        // both are present.
        if ($source->doi) {
            $entry .= ' https://doi.org/'.ltrim($source->doi, '/');
        } elseif ($source->url) {
            $entry .= ' '.$source->url;
        }

        return $entry;
    }

    /** The plain-text bibliography, for copying. */
    public function asText(Book $book, string $style = 'APA'): string
    {
        return collect($this->forBook($book, $style))
            ->pluck('entry')
            ->implode("\n");
    }
}
