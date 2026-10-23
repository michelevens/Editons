<?php

namespace App\Publishing;

use App\Models\Book;
use App\Models\Isbn;

/**
 * Which ISBN each edition carries, and what that allows.
 *
 * A paperback and an ebook are different products and need different
 * ISBNs. An author either brings their own (bought from their country's
 * agency, or free where the agency gives them away) or lets a store assign
 * one, which ties the edition to that store: KDP's free ISBN "can only be
 * used on KDP", IngramSpark's free ISBN is for US publishers and only on
 * IngramSpark (config/publishing.php, docs/POD-SPECS.md).
 */
class IsbnPlan
{
    /** The edition's own ISBN, from the ISBN pool or the publishing details. */
    public static function own(Book $book, string $edition): ?string
    {
        $assigned = $book->isbns()->where('edition_format', $edition)->value('isbn');

        if ($assigned) {
            return $assigned;
        }

        $publication = $book->publication();
        $stated = match ($edition) {
            'paperback' => $publication['isbn_print'],
            'ebook' => $publication['isbn_epub'] ?: $book->isbn,
            default => null,
        };

        return $stated && Isbn::isValid($stated) ? Isbn::normalise($stated) : null;
    }

    /**
     * What the paperback's ISBN means at one print service, as print-check
     * items: [status, message, fix].
     *
     * @return list<array{0: string, 1: string, 2: ?string}>
     */
    public static function printIssues(Book $book, string $service): array
    {
        if (self::own($book, 'paperback')) {
            return [['pass', 'The paperback has its own ISBN.', null]];
        }

        return match ($service) {
            'kdp' => [['pass', 'No paperback ISBN yet: KDP can assign a free one, but it can only be used on KDP, and the imprint then shows "Independently published".', 'To sell the same paperback elsewhere, assign your own ISBN in ISBN & Rights.']],
            'ingram' => [['warn', 'No paperback ISBN: IngramSpark offers a free ISBN to US publishers only, usable only at IngramSpark. Elsewhere you need your own.', 'Assign your own ISBN to the paperback in ISBN & Rights.']],
            default => [],
        };
    }
}
