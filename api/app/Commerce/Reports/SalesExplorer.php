<?php

namespace App\Commerce\Reports;

use App\Commerce\PlatformSales;
use App\Commerce\Revenue;
use App\Models\Book;
use App\Models\OrderItem;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;

/**
 * A sales report over a date range: totals, an optional comparison, the
 * chart, breakdowns by book, format, channel and period, and the lines a
 * CSV export writes — all from one query and one arithmetic (Tally).
 *
 * The scope is a list of seller ids, fixed by the caller (the signed-in
 * author; the house accounts for staff; one author for a staff statement).
 * Every query starts from it, and no filter can widen it: a book id that
 * is not the scope's matches nothing.
 *
 * Filters:
 *   book      one or more of the scope's book ids
 *   format    epub | pdf | print — the formats the item delivered (an
 *             EPUB + PDF bundle matches both)
 *   channel   store (the storefront, nobody referring) | partner (a
 *             partner's commission was due on it). Stores that do not
 *             report sales (Amazon, Apple…, App\Publishing\Royalties) have
 *             no rows to filter: their sales are not recorded.
 *   currency  one of the stored currencies. Money is never converted:
 *             every total and row is per currency.
 *
 * A sale counts on the day it was paid, in the range's timezone, with its
 * refund (whenever that came) on the same line — so the period rows, like
 * the others, add up to the total. The older 30-day chart on a book's
 * Royalties panel (SalesReport::for) still plots refunds on the day they
 * happened.
 *
 * Lines stream (lazyById), so a range with many thousands of orders adds up
 * in constant memory and a CSV export never holds the whole range.
 */
final class SalesExplorer
{
    public const FORMATS = ['epub' => 'EPUB', 'pdf' => 'PDF', 'print' => 'Print'];

    public const CHANNELS = ['store' => 'Own storefront', 'partner' => 'Partner referral'];

    public const DIMENSIONS = ['book', 'format', 'channel', 'period'];

    private const CHUNK = 500;

    /**
     * @param  list<int>  $sellerIds
     * @param  array{books?: list<int>, format?: ?string, channel?: ?string, currency?: ?string}  $filters
     */
    public function __construct(
        public readonly array $sellerIds,
        public readonly array $filters = [],
        public readonly bool $house = false,
    ) {}

    /**
     * Only what is allowed, from a request's query.
     *
     * @param  array<string, mixed>  $input
     * @return array{books: list<int>, format: ?string, channel: ?string, currency: ?string}
     */
    public static function filtersFrom(array $input): array
    {
        $books = $input['book'] ?? [];
        $books = is_array($books) ? $books : explode(',', (string) $books);

        return [
            'books' => array_values(array_unique(array_filter(array_map('intval', array_slice($books, 0, 50)), fn ($id) => $id > 0))),
            'format' => array_key_exists((string) ($input['format'] ?? ''), self::FORMATS) ? (string) $input['format'] : null,
            'channel' => array_key_exists((string) ($input['channel'] ?? ''), self::CHANNELS) ? (string) $input['channel'] : null,
            'currency' => in_array($input['currency'] ?? null, config('commerce.currencies'), true) ? (string) $input['currency'] : null,
        ];
    }

    /** The scope's items paid in $range, filtered, oldest first. */
    public function query(?ReportRange $range): Builder
    {
        $f = $this->filters;

        return OrderItem::query()
            ->select('order_items.*')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('order_items.seller_id', $this->sellerIds === [] ? [0] : $this->sellerIds)
            ->whereIn('orders.status', PlatformSales::SOLD)
            ->whereNotNull('orders.paid_at')
            ->when($range, fn ($q) => $q
                ->where('orders.paid_at', '>=', $range->startUtc())
                ->where('orders.paid_at', '<', $range->endUtc()))
            ->when($f['books'] ?? [], fn ($q, $books) => $q->whereIn('order_items.book_id', $books))
            ->when($f['format'] ?? null, fn ($q, $format) => $q->whereJsonContains('order_items.formats', $format === 'print' ? 'paperback' : $format))
            ->when(($f['channel'] ?? null) === 'partner', fn ($q) => $q->where('order_items.partner_commission_cents', '>', 0))
            ->when(($f['channel'] ?? null) === 'store', fn ($q) => $q->where('order_items.partner_commission_cents', '=', 0))
            ->when($f['currency'] ?? null, fn ($q, $currency) => $q->where('orders.currency', $currency));
    }

    /**
     * Every line, streamed, with its order (and the order's other items,
     * for splitting a discount).
     *
     * @return LazyCollection<int, OrderItem>
     */
    public function lines(?ReportRange $range): LazyCollection
    {
        return $this->query($range)
            ->with(['order:id,number,status,currency,paid_at,refunded_at,buyer_country,discount_cents', 'order.items:id,order_id,price_cents'])
            ->lazyById(self::CHUNK, 'order_items.id', 'id');
    }

    /** When the scope first sold anything (for "All time"). */
    public function firstSale(): ?CarbonImmutable
    {
        $at = $this->query(null)->min('orders.paid_at');

        return $at ? CarbonImmutable::parse($at, 'UTC') : null;
    }

    /**
     * The report: what the Sales page shows.
     *
     * @return array<string, mixed>
     */
    public function report(ReportRange $range, ?string $compare = null): array
    {
        $main = $this->aggregate($range);
        $currency = $main['primary'] ?? $this->fallbackCurrency();

        $report = [
            'range' => $range->toArray(),
            'currency' => $currency,
            'house' => $this->house,
            'totals' => $main['totals'],
            'products' => $main['breakdowns']['book'],
            'breakdowns' => $main['breakdowns'],
            'series' => $main['series'][$currency] ?? self::emptySeries($range),
            'compare' => null,
            'recent' => $this->recent($range),
            'fee_is_estimate' => true,
        ];

        if (in_array($compare, ReportRange::COMPARE, true)) {
            $other = $range->compare($compare);
            $then = $this->aggregate($other);
            $currencies = collect($main['totals'])->pluck('currency')->merge(collect($then['totals'])->pluck('currency'))->unique()->values();
            $nowBy = collect($main['totals'])->keyBy('currency');
            $thenBy = collect($then['totals'])->keyBy('currency');

            $report['compare'] = [
                'mode' => $compare,
                'range' => $other->toArray(),
                // Every currency of either range, at zero where it sold nothing.
                'totals' => $currencies->map(fn (string $code) => $thenBy[$code] ?? ['currency' => $code] + Tally::zero())->all(),
                'deltas' => $currencies->mapWithKeys(fn (string $code) => [
                    $code => self::deltas($nowBy[$code] ?? ['currency' => $code] + Tally::zero(), $thenBy[$code] ?? ['currency' => $code] + Tally::zero()),
                ])->all(),
                // Bucket by bucket against the main range, by position: the
                // second day of this range against the second of that one.
                'series' => array_slice($then['series'][$currency] ?? self::emptySeries($other), 0, count($report['series'])),
            ];

            // A currency sold then but not now still shows, at zero.
            foreach ($currencies as $code) {
                if (! $nowBy->has($code)) {
                    $report['totals'][] = ['currency' => $code] + Tally::zero();
                }
            }
        }

        return $report;
    }

    /**
     * Totals per currency, by currency for each breakdown, and the series.
     *
     * @return array{totals: list<array<string, mixed>>, breakdowns: array<string, list<array<string, mixed>>>, series: array<string, list<array<string, mixed>>>, primary: ?string}
     */
    public function aggregate(ReportRange $range): array
    {
        /** @var array<string, Tally> $totals */
        $totals = [];
        /** @var array<string, array<string, array{meta: array<string, mixed>, tally: Tally}>> $groups */
        $groups = array_fill_keys(self::DIMENSIONS, []);
        $buckets = collect($range->buckets())->keyBy('key');

        foreach ($this->lines($range) as $item) {
            $line = Tally::line($item);
            $code = $item->order->currency;
            ($totals[$code] ??= new Tally)->add($item, $line);

            foreach ($this->keys($item, $range) as $dimension => [$key, $meta]) {
                $slot = $key.'|'.$code;
                $groups[$dimension][$slot] ??= ['meta' => $meta + ['key' => $key, 'currency' => $code], 'tally' => new Tally];
                $groups[$dimension][$slot]['tally']->add($item, $line);
            }
        }

        $rows = fn (array $group) => collect($group)
            ->map(fn (array $g) => $g['meta'] + $g['tally']->toArray())
            ->values();

        $byBook = $rows($groups['book']);
        $this->linkBooks($byBook);

        $primary = collect($totals)->sortByDesc(fn (Tally $t) => $t->toArray()['gross_cents'])->keys()->first();

        // The period table in range order, every bucket present per
        // currency that sold, zero-filled.
        $periods = [];
        $series = [];

        foreach (array_keys($totals) as $code) {
            $series[$code] = $buckets->map(function (array $b) use ($groups, $code) {
                $tally = $groups['period'][$b['key'].'|'.$code]['tally'] ?? null;

                return $b + ['currency' => $code] + ($tally ? $tally->toArray() : Tally::zero());
            })->values()->all();

            foreach ($series[$code] as $row) {
                if ($row['units'] > 0) {
                    $periods[] = $row;
                }
            }
        }

        return [
            'totals' => collect($totals)
                ->map(fn (Tally $t, string $code) => ['currency' => $code] + $t->toArray())
                ->sortByDesc('gross_cents')
                ->values()
                ->all(),
            'breakdowns' => [
                'book' => $byBook->sortByDesc('gross_cents')->values()->all(),
                'format' => $rows($groups['format'])->sortByDesc('gross_cents')->values()->all(),
                'channel' => $rows($groups['channel'])->sortByDesc('gross_cents')->values()->all(),
                'period' => $periods,
            ],
            'series' => $series,
            'primary' => $primary,
        ];
    }

    /**
     * The books, formats, channels and currencies this scope has sold or
     * has on sale: what the filters offer.
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        $sold = OrderItem::query()
            ->whereIn('seller_id', $this->sellerIds === [] ? [0] : $this->sellerIds)
            ->whereNotNull('book_id')
            ->selectRaw('book_id, max(title) as title')
            ->groupBy('book_id')
            ->pluck('title', 'book_id');

        $books = Book::withTrashed()
            ->whereIn('id', $sold->keys())
            ->orWhereIn('id', Product::whereIn('user_id', $this->sellerIds === [] ? [0] : $this->sellerIds)->select('book_id'))
            ->get(['id', 'title'])
            ->map(fn (Book $book) => ['id' => $book->id, 'title' => $book->title ?: ($sold[$book->id] ?? 'Untitled')])
            ->sortBy('title', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        $currencies = $this->query(null)->select('orders.currency')->distinct()->orderBy('orders.currency')->pluck('currency')->all();

        return [
            'books' => $books,
            'formats' => collect(self::FORMATS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all(),
            'channels' => collect(self::CHANNELS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all(),
            'currencies' => $currencies,
        ];
    }

    /**
     * One CSV row per line, header first. Amounts in decimal, with the
     * currency beside them; dates in the range's timezone.
     *
     * @return \Generator<int, list<mixed>>
     */
    public function csvLines(ReportRange $range): \Generator
    {
        yield ['order', 'paid_at', 'date', 'book_id', 'title', 'format', 'channel', 'currency', 'units',
            'gross', 'discount', 'refunded', 'fee_estimate', 'net', 'partner_commission', 'royalty', 'status', 'buyer_country',
            // The bundle the book was sold in, if any: its gross is the
            // book's share of the bundle price (App\Commerce\Bundles).
            'bundle'];

        foreach ($this->lines($range) as $item) {
            $line = Tally::line($item);
            $paid = CarbonImmutable::instance($item->order->paid_at)->setTimezone($range->tz);

            yield [
                $item->order->number,
                $paid->toIso8601String(),
                $paid->format('Y-m-d'),
                $item->book_id,
                $item->title,
                self::formatLabel($item),
                self::CHANNELS[self::channel($item)],
                strtoupper($item->order->currency),
                1,
                self::decimal($line['gross_cents']),
                self::decimal($line['discount_cents']),
                self::decimal($line['refunded_cents']),
                self::decimal($line['fee_cents']),
                self::decimal($line['net_cents']),
                self::decimal($line['partner_cents']),
                self::decimal($line['royalty_cents']),
                $item->order->status,
                $item->order->buyer_country,
                $item->bundle_title,
            ];
        }
    }

    /**
     * The totals and every breakdown as CSV rows, header first.
     *
     * @param  array<string, mixed>  $report  this explorer's report()
     * @return list<list<mixed>>
     */
    public static function csvSummary(array $report): array
    {
        $rows = [['section', 'key', 'label', 'currency', 'units', 'orders', 'gross', 'discounts', 'refunds', 'fees_estimate', 'net', 'partner_commissions', 'royalties']];
        $add = function (string $section, string $key, string $label, array $row) use (&$rows) {
            $rows[] = [$section, $key, $label, strtoupper($row['currency']), $row['units'], $row['orders'],
                self::decimal($row['gross_cents']), self::decimal($row['discount_cents']), self::decimal($row['refunded_cents']),
                self::decimal($row['fee_cents']), self::decimal($row['net_cents']), self::decimal($row['partner_cents']), self::decimal($row['royalty_cents'])];
        };

        foreach ($report['totals'] as $row) {
            $add('total', $report['range']['from'].'..'.$report['range']['to'], $report['range']['label'], $row);
        }

        foreach ($report['compare']['totals'] ?? [] as $row) {
            $add('compare_total', $report['compare']['range']['from'].'..'.$report['compare']['range']['to'], $report['compare']['range']['span'], $row);
        }

        foreach (self::DIMENSIONS as $dimension) {
            foreach ($report['breakdowns'][$dimension] as $row) {
                $add($dimension, (string) $row['key'], (string) $row['label'], $row);
            }
        }

        return $rows;
    }

    public static function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    public static function channel(OrderItem $item): string
    {
        return (int) $item->partner_commission_cents > 0 ? 'partner' : 'store';
    }

    /** "EPUB + PDF", "Print". */
    public static function formatLabel(OrderItem $item): string
    {
        return implode(' + ', array_map(fn ($f) => $f === 'paperback' ? 'Print' : strtoupper($f), self::formatKeys($item)));
    }

    /** @return list<string> */
    private static function formatKeys(OrderItem $item): array
    {
        $formats = array_values(array_unique(array_map('strval', $item->formats ?? [])));
        sort($formats);

        return $formats === [] ? ['unknown'] : $formats;
    }

    /**
     * The breakdown keys of one line.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    private function keys(OrderItem $item, ReportRange $range): array
    {
        $formats = self::formatKeys($item);
        $channel = self::channel($item);
        $bookKey = $item->book_id ? (string) $item->book_id : 'deleted:'.$item->title;

        return [
            'book' => [$bookKey, ['book_id' => $item->book_id, 'product_id' => $item->product_id, 'title' => $item->title, 'label' => $item->title]],
            'format' => [implode('+', $formats), ['label' => self::formatLabel($item)]],
            'channel' => [$channel, ['label' => self::CHANNELS[$channel]]],
            'period' => [$range->keyFor($item->order->paid_at), ['label' => '']],
        ];
    }

    /** The storefront link for each book row whose product is on sale. */
    private function linkBooks($rows): void
    {
        $ids = $rows->pluck('product_id')->filter()->unique()->all();
        $products = $ids === [] ? collect() : Product::whereIn('id', $ids)->get(['id', 'slug', 'published_at'])->keyBy('id');

        $rows->transform(fn (array $row) => $row + [
            'url' => ($p = $products[$row['product_id']] ?? null) && $p->isPublished() ? route('store.product', $p->slug) : null,
        ]);
    }

    /** The 20 latest lines in the range, without the buyer. */
    private function recent(ReportRange $range): array
    {
        return $this->query($range)
            ->with(['order:id,number,status,currency,paid_at,refunded_at,buyer_country,discount_cents'])
            ->orderByDesc('orders.paid_at')
            ->orderByDesc('order_items.id')
            ->limit(20)
            ->get()
            ->map(fn (OrderItem $item) => [
                'number' => $item->order->number,
                'date' => CarbonImmutable::instance($item->order->paid_at)->setTimezone($range->tz)->toDateString(),
                'title' => $item->title,
                'format' => self::formatLabel($item),
                'channel' => self::channel($item),
                'amount_cents' => $item->price_cents,
                'refunded_cents' => $item->refunded_cents,
                'royalty_cents' => Revenue::earned($item),
                'partner_cents' => Tally::line($item)['partner_cents'],
                'currency' => $item->order->currency,
                'status' => $item->order->status,
                'country' => $item->order->buyer_country,
            ])
            ->all();
    }

    private function fallbackCurrency(): string
    {
        return $this->filters['currency']
            ?? Product::whereIn('user_id', $this->sellerIds === [] ? [0] : $this->sellerIds)->value('currency')
            ?? 'usd';
    }

    /** @return list<array<string, mixed>> */
    private static function emptySeries(ReportRange $range): array
    {
        return array_map(fn (array $b) => $b + Tally::zero(), $range->buckets());
    }

    /**
     * Absolute and percentage change per figure; the percentage is null
     * when there was nothing before (a change from zero has no ratio).
     *
     * @return array<string, array{abs: int, pct: ?float}>
     */
    private static function deltas(array $now, array $then): array
    {
        $out = [];

        foreach (['units', 'orders', 'gross_cents', 'discount_cents', 'refunded_cents', 'fee_cents', 'net_cents', 'royalty_cents', 'partner_cents'] as $field) {
            $a = (int) ($now[$field] ?? 0);
            $b = (int) ($then[$field] ?? 0);
            $out[$field] = ['abs' => $a - $b, 'pct' => $b !== 0 ? round(($a - $b) / abs($b) * 100, 1) : null];
        }

        return $out;
    }
}
