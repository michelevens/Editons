<?php

namespace App\Publishing;

use App\Models\Book;
use App\Models\BookChannel;
use App\Models\Isbn;

/**
 * Which ISBN each edition carries, and what that allows.
 *
 * A paperback and an ebook are different products and need different
 * ISBNs. For each edition the author chooses (Book::isbn_plan) to bring
 * their own (bought from, or given by, their country's agency) or to let a
 * store assign one, which ties the edition to that store: KDP's free ISBN
 * "can only be used on KDP"; IngramSpark's free ISBN is for US publishers
 * and only on IngramSpark (config/publishing.php, docs/POD-SPECS.md).
 *
 * Nothing here makes up a number: an own ISBN is one the author entered,
 * checked against its checksum.
 */
class IsbnPlan
{
    /**
     * Each edition's choice and its number, when it has one.
     *
     * @return array<string, array{source: ?string, isbn: ?string}>
     */
    public static function plan(Book $book): array
    {
        $chosen = $book->isbn_plan ?? [];

        return collect(Book::ISBN_EDITIONS)->mapWithKeys(function (string $edition) use ($book, $chosen) {
            $isbn = self::own($book, $edition);
            $source = $chosen[$edition] ?? null;

            // A number already assigned is a choice made.
            return [$edition => ['source' => $source ?? ($isbn ? 'own' : null), 'isbn' => $isbn]];
        })->all();
    }

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
        $paperback = self::plan($book)['paperback'];

        if ($paperback['isbn']) {
            return [['pass', 'The paperback has its own ISBN.', null]];
        }

        if ($paperback['source'] === 'own') {
            return [['fail', 'You chose your own ISBN for the paperback, but none is assigned.', 'Assign it in ISBN & Rights, or choose "assigned by the store".']];
        }

        $printStores = $book->channels()->whereIn('channel', array_keys(BookChannel::PRINT_SERVICE))->pluck('channel')->all();

        // Each store would assign its own number to the same paperback,
        // and neither number may be used at the other store.
        if ($paperback['source'] === 'store' && count($printStores) > 1) {
            return [['fail', 'A store-assigned ISBN works at that store only: KDP\'s free ISBN "can only be used on KDP", and IngramSpark\'s only at IngramSpark. One paperback sold at both needs your own.', 'Choose "my own ISBN" for the paperback in ISBN & Rights.']];
        }

        return match ($service) {
            'kdp' => [['pass', 'No paperback ISBN yet: KDP can assign a free one, but it can only be used on KDP, and the imprint then shows "Independently published".', 'To sell the same paperback elsewhere, use your own ISBN.']],
            'ingram' => [['warn', 'No paperback ISBN: IngramSpark offers a free ISBN to US publishers only, usable only at IngramSpark. Outside the US you need your own.', 'Assign your own ISBN to the paperback in ISBN & Rights.']],
            default => [],
        };
    }

    /**
     * What the ebook's ISBN choice means at an ebook store, as items.
     *
     * @return list<array{0: string, 1: string, 2: ?string}>
     */
    public static function ebookIssues(Book $book, string $channel): array
    {
        $ebook = self::plan($book)['ebook'];

        if ($ebook['isbn']) {
            return [['pass', 'The ebook has its own ISBN.', null]];
        }

        if ($ebook['source'] === 'own') {
            return [['fail', 'You chose your own ISBN for the ebook, but none is assigned.', 'Assign it in ISBN & Rights, or choose "assigned by the store".']];
        }

        return match ($channel) {
            // KDP: "eBooks and low-content books are the only formats where an ISBN is optional."
            'amazon_kdp' => [['pass', 'No ebook ISBN: optional at Kindle.', null]],
            'apple_books' => [['pass', 'No ebook ISBN: optional at Apple Books, but it can only be added on the first submission.', null]],
            'google_play' => [['pass', 'No ebook ISBN: Google Play assigns its own identifier.', null]],
            default => [['warn', 'No ebook ISBN: check whether this store needs one.', 'Assign your own ISBN to the ebook in ISBN & Rights.']],
        };
    }

    /**
     * Where to get an ISBN, for the country in the publishing details.
     *
     * @return array{country: ?string, agency: string, url: string, free: ?bool}
     */
    public static function agency(Book $book): array
    {
        $country = $book->publication()['country'];
        $agencies = config('publishing.isbn_agencies');

        return ['country' => $country] + ($agencies[$country] ?? $agencies['default']);
    }
}
