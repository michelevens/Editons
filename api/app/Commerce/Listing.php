<?php

namespace App\Commerce;

use App\Models\Book;
use App\Models\BookExport;
use Illuminate\Validation\ValidationException;

/**
 * Can this book be put on sale at this price, in these formats?
 *
 * The one rule the author's storefront panel (ProductController) and the
 * console's House catalog both apply before a product goes live: a price
 * Stripe will accept, a book that is not archived or taken down, and a ready
 * export of every format promised — a buyer who pays for an EPUB must be
 * able to download one.
 */
final class Listing
{
    /** Stripe's minimum charge in each supported currency, in minor units. */
    public const MINIMUM_CENTS = ['usd' => 50, 'eur' => 50, 'cad' => 50, 'gbp' => 30];

    /**
     * @param  list<string>  $formats
     *
     * @throws ValidationException
     */
    public static function assertSellable(Book $book, int $cents, string $currency, array $formats): void
    {
        $errors = [];

        if ($cents < (self::MINIMUM_CENTS[$currency] ?? 50)) {
            $errors['price'] = 'Card payments need a price of at least '
                .number_format((self::MINIMUM_CENTS[$currency] ?? 50) / 100, 2).' '.strtoupper($currency).'.';
        }

        if ($book->status === 'archived') {
            $errors['published'] = 'Restore the book from the archive before selling it.';
        }

        if ($book->product?->isTakenDown()) {
            $errors['published'] = 'Ecrivain has taken this store page down: '.$book->product->taken_down_reason.' Write to support from Help if you think it is a mistake.';
        }

        $missing = array_values(array_filter($formats, fn (string $format) => ! self::readyExport($book, $format)));

        if ($missing !== []) {
            $errors['formats'] = 'Export '.implode(' and ', array_map(
                fn (string $f) => BookExport::FORMATS[$f][0] ?? strtoupper($f),
                $missing,
            )).' on Export & Publish first: buyers download the latest export of each format.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public static function readyExport(Book $book, string $format): ?BookExport
    {
        return $book->exports()->where('format', $format)->where('status', 'ready')->first();
    }
}
