<?php

namespace App\Publishing;

use App\Models\Book;
use App\Models\BookChannel;

/**
 * What a copy earns, store by store, at a list price.
 *
 * Arithmetic on the rates and costs each store publishes (config/
 * publishing.php), never a forecast. US marketplaces and US dollars, with
 * no VAT, which is how the stores publish their examples. Where a store
 * does not publish a figure the calculation needs (IngramSpark's print
 * cost, its ebook "net revenue"), the result says what is missing instead
 * of filling it in.
 */
class Royalties
{
    /**
     * KDP paperback, Amazon.com: "(Royalty rate x list price) – printing
     * costs = royalty", 50% below $9.99 and 60% from it; 40% through
     * Expanded Distribution.
     *
     * @return array<string, mixed>
     */
    public static function kdpPaperback(?float $price, int $pages, string $ink, string $trim): array
    {
        $cost = PrintSpecs::printCost('kdp', $pages, $ink, $trim);
        $terms = config('publishing.print.kdp.royalty');
        $rate = fn (float $p) => $p >= $terms['threshold'] ? $terms['at_or_above'] : $terms['below'];

        // The lowest price that covers printing: at the lower rate if that
        // stays under the threshold, else at the higher rate.
        $minimum = null;

        if ($cost !== null) {
            $low = self::up($cost / $terms['below']);
            $minimum = $low < $terms['threshold'] ? $low : max($terms['threshold'], self::up($cost / $terms['at_or_above']));
        }

        return [
            'channel' => 'kdp_paperback',
            'label' => 'Amazon KDP paperback',
            'print_cost' => $cost,
            'rate' => $price !== null ? $rate($price) : null,
            'royalty' => $price !== null && $cost !== null ? round($rate($price) * $price - $cost, 2) : null,
            'expanded_rate' => $terms['expanded'],
            'expanded_royalty' => $price !== null && $cost !== null ? round($terms['expanded'] * $price - $cost, 2) : null,
            'minimum_price' => $minimum,
            'expanded_minimum_price' => $cost !== null ? self::up($cost / $terms['expanded']) : null,
            'note' => sprintf('%d%% of list below $%s, %d%% from $%s, less printing ($%s for %d pages); %d%% through Expanded Distribution.',
                $terms['below'] * 100, number_format($terms['threshold'], 2), $terms['at_or_above'] * 100, number_format($terms['threshold'], 2),
                $cost === null ? '?' : number_format($cost, 2), PrintSpecs::evenPages($pages), $terms['expanded'] * 100),
            'source' => config('publishing.print.kdp.source'),
        ];
    }

    /**
     * IngramSpark print: list price, less the wholesale discount the
     * author sets (40-55% in the US), less a print cost IngramSpark only
     * gives through its own calculator.
     *
     * @return array<string, mixed>
     */
    public static function ingramPrint(?float $price, ?float $discount = null): array
    {
        $range = config('publishing.print.ingram.discount');
        $discount = max($range['min'], min($range['max'], $discount ?? $range['max']));

        return [
            'channel' => 'ingram_print',
            'label' => 'IngramSpark paperback',
            'discount' => $discount,
            'discount_range' => [$range['min'], $range['max']],
            // Before the print cost, which is not published as a formula.
            'wholesale' => $price !== null ? round($price * (1 - $discount), 2) : null,
            'print_cost' => null,
            'royalty' => null,
            'note' => sprintf('List price less your wholesale discount (%d–%d%% in the US), less the print cost from IngramSpark\'s Print & Ship Calculator, which it does not publish as a formula.', $range['min'] * 100, $range['max'] * 100),
            'source' => 'https://www.ingramspark.com/blog/why-should-i-discount-my-book',
        ];
    }

    /**
     * Kindle eBook, Amazon.com. 70% of (list price less delivery) inside
     * the band and at least 20% under the print list price; otherwise 35%
     * of the list price.
     *
     * @return array<string, mixed>
     */
    public static function kindle(?float $price, ?float $megabytes = null, ?float $printPrice = null): array
    {
        $t = config('publishing.ebook.amazon_kdp');
        $delivery = $megabytes !== null ? max($t['delivery_min'], round($megabytes * $t['delivery_per_mb'], 2)) : null;
        $minimum = collect($t['min_price_by_mb'])->first(fn ($row) => ($megabytes ?? 0) < $row[0])[1];
        $inBand = $price !== null && $price >= $t['band'][0] && $price <= $t['band'][1];
        $underPrint = $printPrice === null || $price === null || $price <= round($printPrice * (1 - $t['below_print']), 2);
        $high = $inBand && $underPrint;
        $problem = match (true) {
            $price === null => null,
            $price < $minimum => sprintf('Kindle\'s lowest list price for this file size is $%s.', number_format($minimum, 2)),
            $price > $t['max_price'] => sprintf('Kindle\'s highest list price is $%s.', number_format($t['max_price'], 2)),
            ! $inBand => sprintf('Outside $%s–$%s, the 70%% option does not apply: 35%%.', number_format($t['band'][0], 2), number_format($t['band'][1], 2)),
            ! $underPrint => 'The 70% option needs the ebook at least 20% below the paperback price: 35%.',
            default => null,
        };

        return [
            'channel' => 'amazon_kdp',
            'label' => 'Kindle eBook',
            'rate' => $price === null ? null : ($high ? $t['high'] : $t['low']),
            'delivery' => $high ? $delivery : null,
            'royalty' => match (true) {
                $price === null => null,
                $high && $delivery === null => round($t['high'] * $price, 2),
                $high => round($t['high'] * ($price - $delivery), 2),
                default => round($t['low'] * $price, 2),
            },
            'band' => $t['band'],
            'problem' => $problem,
            'note' => sprintf('70%% of (list price less delivery at $%s/MB) between $%s and $%s, else 35%% of list.%s', number_format($t['delivery_per_mb'], 2),
                number_format($t['band'][0], 2), number_format($t['band'][1], 2), $high && $delivery === null ? ' Generate the EPUB to include the delivery cost.' : ''),
            'source' => $t['source'],
        ];
    }

    /** @return array<string, mixed> */
    public static function ebookStore(string $channel, ?float $price, ?float $printPrice = null): array
    {
        $t = config("publishing.ebook.{$channel}");
        $rate = $t['rate'];
        $note = match ($channel) {
            'apple_books' => '70% at any price.',
            'kobo' => sprintf('70%% from $%s and at least 20%% below the print price, otherwise 45%%.', number_format($t['min_for_rate'], 2)),
            'google_play' => '70% in most countries once you accept Google\'s updated terms; 52% before that and in the countries Google lists.',
            default => '',
        };

        if ($channel === 'kobo' && $price !== null) {
            $underPrint = $printPrice === null || $price <= round($printPrice * (1 - $t['below_print']), 2);
            $rate = $price >= $t['min_for_rate'] && $underPrint ? $t['rate'] : $t['low'];
        }

        return [
            'channel' => $channel,
            'label' => BookChannel::CHANNELS[$channel][0] ?? $channel,
            'rate' => $price === null ? null : $rate,
            'royalty' => $price === null ? null : round($rate * $price, 2),
            'note' => $note,
            'source' => $t['source'],
        ];
    }

    /**
     * Every calculation for a book, at the prices given (or the book's).
     *
     * @return array<string, mixed>
     */
    public static function forBook(Book $book, ?float $ebookPrice = null, ?float $printPrice = null, ?float $discount = null): array
    {
        $prices = self::prices($book);
        $ebookPrice ??= $prices['ebook'];
        $printPrice ??= $prices['print'];
        $pages = PrintPages::for($book);
        $print = $book->printSettings();
        $trim = $book->layout()['trim'];
        $epub = $book->exports()->fullBook()->where('format', 'epub')->where('status', 'ready')->latest('id')->value('byte_size');
        $megabytes = $epub ? round($epub / 1048576, 2) : null;

        return [
            'currency' => 'USD',
            'checked' => config('publishing.checked'),
            'prices' => ['ebook' => $ebookPrice, 'print' => $printPrice],
            'pages' => $pages,
            'epub_mb' => $megabytes,
            'print' => [
                self::kdpPaperback($printPrice, $pages['pages'], $print['ink'], $trim),
                self::ingramPrint($printPrice, $discount),
            ],
            'ebook' => [
                self::kindle($ebookPrice, $megabytes, $printPrice),
                self::ebookStore('apple_books', $ebookPrice, $printPrice),
                self::ebookStore('kobo', $ebookPrice, $printPrice),
                self::ebookStore('google_play', $ebookPrice, $printPrice),
            ],
        ];
    }

    /**
     * The book's prices: the list price is the ebook's; the paperback's is
     * its own when set, else none (an ebook price is no print price).
     *
     * @return array{ebook: ?float, print: ?float}
     */
    public static function prices(Book $book): array
    {
        $print = ($book->meta ?? [])['print_price'] ?? null;

        return [
            'ebook' => $book->list_price !== null ? (float) $book->list_price : null,
            'print' => $print !== null && $print !== '' ? (float) $print : null,
        ];
    }

    /** Up to the next cent. */
    private static function up(float $amount): float
    {
        return ceil(round($amount * 100, 6)) / 100;
    }
}
