<?php

namespace App\Publishing;

use App\Models\Book;
use App\Models\BookExport;
use Illuminate\Support\Carbon;

/**
 * How many pages the printed interior has.
 *
 * The real count only exists once the book is typeset, which takes
 * minutes for a long book (ADR-0018), so a print interior or cover wrap
 * records the count it was laid out to, with a fingerprint of everything
 * that moves pages: the layout, the publishing details the generated
 * pages print, and every section's last change. While the fingerprint
 * still matches, that count is the book's. Otherwise the screen gets an
 * estimate from the word count and the page geometry, labelled as one.
 */
class PrintPages
{
    /** Formats that typeset the interior and record its page count. */
    public const RECORDING = ['pdf_interior', 'cover_wrap'];

    /** What the page count depends on, as a short hash. */
    public static function fingerprint(Book $book): string
    {
        $sections = $book->sections()
            ->selectRaw('count(*) as n, max(updated_at) as changed, sum(word_count) as words')
            ->first();

        return substr(md5(json_encode([
            // The interior renderer's version: the same settings set
            // differently once it changes (InteriorDesign::VERSION).
            InteriorDesign::VERSION,
            $book->layout(),
            $book->publication(),
            [$book->title, $book->subtitle, $book->author_name, $book->series_name, $book->series_number, $book->language],
            [$sections?->n, (string) $sections?->changed, $sections?->words],
        ])), 0, 16);
    }

    /**
     * The typeset count, when the book has not changed since.
     *
     * @return array{pages: int, at: Carbon, export_id: int}|null
     */
    public static function known(Book $book): ?array
    {
        $fingerprint = self::fingerprint($book);

        // A range or a manuscript has its own count, not the book's.
        $export = BookExport::where('book_id', $book->id)
            ->fullBook()
            ->whereIn('format', self::RECORDING)
            ->whereNotNull('pages')
            ->latest('id')
            ->limit(10)
            ->get()
            ->first(fn (BookExport $e) => ($e->options['fingerprint'] ?? null) === $fingerprint);

        return $export ? ['pages' => $export->pages, 'at' => $export->created_at, 'export_id' => $export->id,
            'layout' => $export->options['layout'] ?? [], 'margin' => $export->options['margin'] ?? null,
            'outside' => $export->options['outside'] ?? null] : null;
    }

    /**
     * Pages estimated from the words and the page geometry: characters per
     * line from the measure and type size, lines per page from the text
     * height and leading, a page per chapter opening, and the front
     * matter the book generates. Close enough to choose a price band and
     * a paper; not close enough to size a spine, which always uses the
     * typeset count.
     */
    public static function estimate(Book $book): int
    {
        $layout = $book->layout();
        [$w, $h] = PageSetup::trimInches($layout['trim']);
        $margins = PageSetup::margins($layout);
        // The vertical sum as it always was (the side margin twice), so an
        // estimate for a book on a preset does not move.
        $margin = PageSetup::preset($layout['margins'])['side'];
        $size = max(8.0, (float) $layout['size']);
        $leading = max(1.0, (float) $layout['leading']);

        // An average character is about half the type size wide; a word
        // is about six characters with its space; pages run about 85% full.
        $charsPerLine = ($w - $margins['inside'] - $margins['outside']) * 72 / ($size * 0.5);
        $lines = ($h - 2 * $margin) * 72 / ($size * $leading);
        $wordsPerPage = max(50, $charsPerLine * $lines / 6 * 0.85);

        $chapters = $book->sections()->whereIn('type', ['chapter', 'part', 'front_matter', 'back_matter'])->count();
        $front = 6;

        return PrintSpecs::evenPages((int) ceil($book->word_count / $wordsPerPage) + $chapters + $front);
    }

    /** @return array{pages: int, exact: bool, at: ?string} */
    public static function for(Book $book): array
    {
        if ($known = self::known($book)) {
            return ['pages' => $known['pages'], 'exact' => true, 'at' => $known['at']->diffForHumans(),
                'layout' => $known['layout'], 'margin' => $known['margin'], 'outside' => $known['outside']];
        }

        return ['pages' => self::estimate($book), 'exact' => false, 'at' => null];
    }
}
