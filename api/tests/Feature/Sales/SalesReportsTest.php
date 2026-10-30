<?php

use App\Commerce\Partners\Earnings;
use App\Commerce\Reports\ReportRange;
use App\Commerce\Reports\RoyaltyStatements;
use App\Commerce\Reports\SalesExplorer;
use App\Commerce\Reports\Tally;
use App\Commerce\Revenue;
use App\Commerce\SalesReport;
use App\Models\AdminAuditLog;
use App\Models\Book;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PartnerCommission;
use App\Models\Payout;
use App\Models\User;
use App\Platform\House\HouseAccounts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sales reports and royalty statements (ADR-0046): date ranges cut in the
 * account's timezone, comparisons, filters, breakdowns that add up to the
 * totals, CSVs, PDF statements whose numbers are the report's, partner
 * earnings, and nobody seeing anybody else's money.
 */

beforeEach(function () {
    config([
        'commerce.stripe_fee' => ['percent' => 2.9, 'fixed_cents' => 30],
        'commerce.platform_rate' => 0.0,
        'commerce.report_timezone' => 'America/New_York',
    ]);
});

/**
 * One paid sale, written as fulfilment writes it.
 *
 * @param  array{currency?: string, formats?: list<string>, refunded?: int, discount?: int, partner?: int, status?: string, title?: string, country?: string}  $o
 */
function srSale(User $seller, Book $book, int $price, string $paidAtUtc, array $o = []): OrderItem
{
    $fee = Revenue::fee($price);
    $royalty = Revenue::royalty($price, $fee);
    $partner = min($o['partner'] ?? 0, $royalty);
    $refunded = $o['refunded'] ?? 0;

    $order = new Order;
    $order->forceFill([
        'number' => Order::newNumber(),
        'status' => $o['status'] ?? ($refunded >= $price && $refunded > 0 ? 'refunded' : ($refunded > 0 ? 'partially_refunded' : 'paid')),
        'buyer_email' => 'r'.Str::random(6).'@example.com',
        'buyer_country' => $o['country'] ?? 'HT',
        'currency' => $o['currency'] ?? 'usd',
        'total_cents' => $price,
        'fee_cents' => $fee,
        'refunded_cents' => $refunded,
        'discount_cents' => $o['discount'] ?? 0,
        'paid_at' => CarbonImmutable::parse($paidAtUtc, 'UTC'),
        'refunded_at' => $refunded ? CarbonImmutable::parse($paidAtUtc, 'UTC')->addDay() : null,
    ])->save();

    $item = $order->items()->make()->forceFill([
        'book_id' => $book->id,
        'seller_id' => $seller->id,
        'title' => $o['title'] ?? $book->title,
        'formats' => $o['formats'] ?? ['epub', 'pdf'],
        'license_type' => 'personal',
        'price_cents' => $price,
        'fee_cents' => $fee,
        'royalty_cents' => $royalty - $partner,
        'partner_commission_cents' => $partner,
        'refunded_cents' => $refunded,
    ]);
    $item->save();

    return $item;
}

function srRange(string $from, string $to, string $tz = 'America/New_York'): ReportRange
{
    return ReportRange::fromInput(['range' => 'custom', 'from' => $from, 'to' => $to], $tz);
}

function srCsv($response): array
{
    $body = $response->streamedContent();
    expect(substr($body, 0, 3))->toBe("\xEF\xBB\xBF");
    $lines = array_values(array_filter(preg_split('/\r?\n/', substr($body, 3)), fn ($l) => $l !== ''));

    return array_map(fn ($l) => str_getcsv($l, ',', '"', ''), $lines);
}

// ── Ranges ──────────────────────────────────────────────────────────────

it('resolves every preset in the account timezone', function () {
    // 02:00 UTC on the 30th is still the 29th in New York.
    $now = CarbonImmutable::parse('2026-10-30 02:00:00', 'UTC');
    $tz = 'America/New_York';
    $of = fn (string $p, $first = null) => [ReportRange::preset($p, $tz, $first, $now)->from->toDateString(), ReportRange::preset($p, $tz, $first, $now)->to->toDateString()];

    expect($of('last_7'))->toBe(['2026-10-23', '2026-10-29'])
        ->and($of('last_30'))->toBe(['2026-09-30', '2026-10-29'])
        ->and($of('last_90'))->toBe(['2026-08-01', '2026-10-29'])
        ->and($of('this_month'))->toBe(['2026-10-01', '2026-10-29'])
        ->and($of('last_month'))->toBe(['2026-09-01', '2026-09-30'])
        ->and($of('quarter'))->toBe(['2026-10-01', '2026-10-29'])
        ->and($of('last_quarter'))->toBe(['2026-07-01', '2026-09-30'])
        ->and($of('ytd'))->toBe(['2026-01-01', '2026-10-29'])
        ->and($of('last_year'))->toBe(['2025-01-01', '2025-12-31'])
        ->and($of('all'))->toBe(['2026-10-29', '2026-10-29'])
        ->and($of('all', CarbonImmutable::parse('2024-03-05 03:00', 'UTC')))->toBe(['2024-03-04', '2026-10-29']);

    // In UTC the same moment is already the 30th.
    expect(ReportRange::preset('last_7', 'UTC', null, $now)->to->toDateString())->toBe('2026-10-30');
    // Unknown presets fall back to the default.
    expect(ReportRange::fromInput(['range' => 'bogus'], $tz)->preset)->toBe('last_30');
});

it('cuts day boundaries in local time, across both DST changes', function () {
    // 1 November 2026: New York falls back, so the day is 25 hours long.
    $nov1 = srRange('2026-11-01', '2026-11-01');
    expect($nov1->startUtc()->toDateTimeString())->toBe('2026-11-01 04:00:00')
        ->and($nov1->endUtc()->toDateTimeString())->toBe('2026-11-02 05:00:00')
        ->and($nov1->startUtc()->diffInHours($nov1->endUtc()))->toEqual(25);

    // 8 March 2026: spring forward, 23 hours.
    $mar8 = srRange('2026-03-08', '2026-03-08');
    expect($mar8->startUtc()->diffInHours($mar8->endUtc()))->toEqual(23);

    $author = User::factory()->create();
    $book = Book::factory()->for($author)->create();
    srSale($author, $book, 1000, '2026-11-01 03:30:00'); // 23:30 EDT on 31 October
    srSale($author, $book, 1000, '2026-11-02 04:30:00'); // 23:30 EST on 1 November
    srSale($author, $book, 1000, '2026-11-02 05:00:00'); // 00:00 EST on 2 November

    $explorer = new SalesExplorer([$author->id]);
    $units = fn (ReportRange $r) => $explorer->aggregate($r)['totals'][0]['units'] ?? 0;

    expect($units(srRange('2026-10-31', '2026-10-31')))->toBe(1)
        ->and($units($nov1))->toBe(1)
        ->and($units(srRange('2026-11-02', '2026-11-02')))->toBe(1)
        // The same sales in UTC days fall differently.
        ->and($units(srRange('2026-11-01', '2026-11-01', 'UTC')))->toBe(1)
        ->and($units(srRange('2026-11-02', '2026-11-02', 'UTC')))->toBe(2);

    $series = $explorer->report(srRange('2026-10-31', '2026-11-02'))['series'];
    expect(array_column($series, 'key'))->toBe(['2026-10-31', '2026-11-01', '2026-11-02'])
        ->and(array_column($series, 'units'))->toBe([1, 1, 1]);
});

it('rejects a bad custom range', function () {
    expect(fn () => srRange('2026-10-05', '2026-10-01'))->toThrow(Illuminate\Validation\ValidationException::class)
        ->and(fn () => srRange('2026-02-30', '2026-03-01'))->toThrow(Illuminate\Validation\ValidationException::class)
        ->and(fn () => ReportRange::fromInput(['range' => 'custom', 'from' => 'yesterday', 'to' => '2026-01-01'], 'UTC'))->toThrow(Illuminate\Validation\ValidationException::class);

    $this->actingAs(User::factory()->create())
        ->get('/sales?range=custom&from=2026-10-05&to=2026-10-01')
        ->assertSessionHasErrors('to');
});

it('compares with the previous period or the same period last year', function () {
    $lastMonth = srRange('2026-09-01', '2026-09-30');
    expect([$lastMonth->previous()->from->toDateString(), $lastMonth->previous()->to->toDateString()])->toBe(['2026-08-01', '2026-08-31']);

    $quarter = srRange('2026-07-01', '2026-09-30');
    expect([$quarter->previous()->from->toDateString(), $quarter->previous()->to->toDateString()])->toBe(['2026-04-01', '2026-06-30']);

    $ten = srRange('2026-10-11', '2026-10-20');
    expect([$ten->previous()->from->toDateString(), $ten->previous()->to->toDateString()])->toBe(['2026-10-01', '2026-10-10'])
        ->and([$ten->lastYear()->from->toDateString(), $ten->lastYear()->to->toDateString()])->toBe(['2025-10-11', '2025-10-20']);

    expect(srRange('2026-10-01', '2026-10-30')->bucket())->toBe('day')
        ->and(srRange('2026-07-01', '2026-09-30')->bucket())->toBe('week')
        ->and(srRange('2025-01-01', '2025-12-31')->bucket())->toBe('month')
        ->and(count(srRange('2025-01-01', '2025-12-31')->buckets()))->toBe(12);
});

it('reports deltas against the comparison, absolute and percent', function () {
    $author = User::factory()->create();
    $book = Book::factory()->for($author)->create();
    srSale($author, $book, 1000, '2026-10-12 15:00:00');
    srSale($author, $book, 1000, '2026-10-15 15:00:00');
    srSale($author, $book, 1000, '2026-10-05 15:00:00'); // the previous 10 days
    srSale($author, $book, 2000, '2025-10-12 15:00:00', ['currency' => 'eur']); // a year earlier, in euros

    $explorer = new SalesExplorer([$author->id]);
    $report = $explorer->report(srRange('2026-10-11', '2026-10-20'), 'previous');

    expect($report['compare']['mode'])->toBe('previous')
        ->and($report['compare']['range']['from'])->toBe('2026-10-01')
        ->and($report['compare']['deltas']['usd']['units'])->toBe(['abs' => 1, 'pct' => 100.0])
        ->and($report['compare']['deltas']['usd']['gross_cents'])->toBe(['abs' => 1000, 'pct' => 100.0])
        ->and(count($report['compare']['series']))->toBe(count($report['series']));

    $year = $explorer->report(srRange('2026-10-11', '2026-10-20'), 'year');
    // Nothing in dollars last year: no ratio. Euros then, none now: shown at zero.
    expect($year['compare']['deltas']['usd']['gross_cents'])->toBe(['abs' => 2000, 'pct' => null])
        ->and($year['compare']['deltas']['eur']['gross_cents'])->toBe(['abs' => -2000, 'pct' => -100.0])
        ->and(collect($year['totals'])->firstWhere('currency', 'eur')['units'])->toBe(0);
});

// ── Filters, breakdowns, reconciliation ─────────────────────────────────

it('filters by book, format, channel and currency, and never converts money', function () {
    $author = User::factory()->create();
    $a = Book::factory()->for($author)->create(['title' => 'Alpha']);
    $b = Book::factory()->for($author)->create(['title' => 'Beta']);
    srSale($author, $a, 1000, '2026-10-10 15:00:00', ['formats' => ['epub']]);
    srSale($author, $a, 1500, '2026-10-10 15:00:00', ['formats' => ['epub', 'pdf'], 'partner' => 200]);
    srSale($author, $b, 2500, '2026-10-11 15:00:00', ['formats' => ['paperback']]);
    srSale($author, $b, 900, '2026-10-11 15:00:00', ['formats' => ['pdf'], 'currency' => 'eur']);

    $range = srRange('2026-10-01', '2026-10-31');
    $units = fn (array $filters) => collect((new SalesExplorer([$author->id], $filters))->aggregate($range)['totals'])->sum('units');

    expect($units([]))->toBe(4)
        ->and($units(['books' => [$a->id]]))->toBe(2)
        ->and($units(['format' => 'epub']))->toBe(2)
        ->and($units(['format' => 'pdf']))->toBe(2)
        ->and($units(['format' => 'print']))->toBe(1)
        ->and($units(['channel' => 'partner']))->toBe(1)
        ->and($units(['channel' => 'store']))->toBe(3)
        ->and($units(['currency' => 'eur']))->toBe(1)
        ->and($units(['books' => [$b->id], 'format' => 'print']))->toBe(1);

    $report = (new SalesExplorer([$author->id]))->report($range);
    expect(collect($report['totals'])->pluck('currency')->all())->toBe(['usd', 'eur'])
        ->and(collect($report['totals'])->firstWhere('currency', 'usd')['gross_cents'])->toBe(5000)
        ->and(collect($report['totals'])->firstWhere('currency', 'eur')['gross_cents'])->toBe(900)
        ->and(collect($report['breakdowns']['format'])->pluck('label')->sort()->values()->all())->toBe(['EPUB', 'EPUB + PDF', 'PDF', 'Print'])
        ->and(collect($report['breakdowns']['channel'])->firstWhere('key', 'partner')['partner_cents'])->toBe(200);

    // The request carries the same filters.
    $this->actingAs($author)->get("/sales?range=custom&from=2026-10-01&to=2026-10-31&book[]={$a->id}&format=pdf")
        ->assertInertia(fn (Assert $page) => $page
            ->where('query.books', [$a->id])
            ->where('query.format', 'pdf')
            ->where('report.totals.0.units', 1)
            ->where('report.totals.0.gross_cents', 1500));
});

it('makes every breakdown add up exactly to the totals, refunds and discounts included', function () {
    mt_srand(42);
    $author = User::factory()->create();
    $books = Book::factory()->for($author)->count(4)->create();
    $formats = [['epub'], ['pdf'], ['epub', 'pdf'], ['paperback']];

    foreach (range(1, 80) as $i) {
        $price = mt_rand(300, 4000);
        srSale($author, $books[mt_rand(0, 3)], $price, CarbonImmutable::parse('2026-07-01 12:00', 'UTC')->addHours(mt_rand(0, 24 * 91))->toDateTimeString(), [
            'formats' => $formats[mt_rand(0, 3)],
            'currency' => mt_rand(0, 4) === 0 ? 'cad' : 'usd',
            'refunded' => mt_rand(0, 5) === 0 ? mt_rand(1, $price) : 0,
            'discount' => mt_rand(0, 3) === 0 ? mt_rand(50, 500) : 0,
            'partner' => mt_rand(0, 3) === 0 ? mt_rand(10, 300) : 0,
        ]);
    }

    $range = srRange('2026-07-01', '2026-09-30');
    $report = (new SalesExplorer([$author->id]))->report($range, 'previous');
    $money = ['units', 'gross_cents', 'discount_cents', 'refunded_cents', 'refunds', 'fee_cents', 'net_cents', 'royalty_cents', 'partner_cents', 'partner_units'];

    foreach ($report['totals'] as $total) {
        foreach (SalesExplorer::DIMENSIONS as $dimension) {
            $rows = collect($report['breakdowns'][$dimension])->where('currency', $total['currency']);

            foreach ($money as $field) {
                expect($rows->sum($field))->toBe($total[$field], "{$dimension}.{$field} in {$total['currency']}");
            }
        }
    }

    // The chart in the main currency, too.
    $usd = collect($report['totals'])->firstWhere('currency', $report['currency']);
    expect(collect($report['series'])->sum('gross_cents'))->toBe($usd['gross_cents'])
        ->and(collect($report['series'])->sum('royalty_cents'))->toBe($usd['royalty_cents']);

    // And the whole range equals the all-time report the Royalties panel shows.
    $all = SalesReport::for($author);
    foreach ($all['totals'] as $old) {
        $new = collect($report['totals'])->firstWhere('currency', $old['currency']);
        foreach (['units', 'gross_cents', 'refunded_cents', 'fee_cents', 'net_cents', 'royalty_cents', 'partner_cents'] as $field) {
            expect($new[$field])->toBe($old[$field]);
        }
    }
});

it('computes refunds and discounts per line with the store\'s own arithmetic', function () {
    $author = User::factory()->create();
    $book = Book::factory()->for($author)->create();
    // A 12.00 book with a 3.00 code: the buyer paid 9.00; half refunded.
    $item = srSale($author, $book, 900, '2026-10-10 15:00:00', ['discount' => 300, 'refunded' => 450]);

    $totals = (new SalesExplorer([$author->id]))->aggregate(srRange('2026-10-01', '2026-10-31'))['totals'][0];
    $fee = Revenue::fee(900);

    expect($totals['gross_cents'])->toBe(900)
        ->and($totals['discount_cents'])->toBe(300)
        ->and($totals['refunded_cents'])->toBe(450)
        ->and($totals['refunds'])->toBe(1)
        ->and($totals['fee_cents'])->toBe($fee)
        ->and($totals['net_cents'])->toBe(900 - 450 - $fee)
        ->and($totals['royalty_cents'])->toBe(Revenue::earned($item->fresh()));

    // A discount split across two items of one order adds up to the order's.
    $order = $item->order;
    $second = $order->items()->make()->forceFill(['book_id' => $book->id, 'seller_id' => $author->id, 'title' => 'Two', 'formats' => ['pdf'], 'license_type' => 'personal', 'price_cents' => 300, 'fee_cents' => 0, 'royalty_cents' => 300]);
    $second->save();
    $lines = (new SalesExplorer([$author->id]))->lines(srRange('2026-10-01', '2026-10-31'))->map(fn ($i) => Tally::line($i)['discount_cents'])->all();
    expect(array_sum($lines))->toBe(300)->and($lines)->toBe([225, 75]);
});

// ── CSV ─────────────────────────────────────────────────────────────────

it('exports the current view as CSV lines and a summary, defused, with a BOM', function () {
    $author = User::factory()->create();
    $book = Book::factory()->for($author)->create(['title' => 'Réussir']);
    srSale($author, $book, 1250, '2026-10-10 15:00:00', ['title' => '=HYPERLINK("http://x")', 'formats' => ['epub']]);
    srSale($author, $book, 999, '2026-10-12 15:00:00', ['title' => 'Réussir', 'currency' => 'eur', 'refunded' => 999]);
    srSale($author, $book, 500, '2026-08-01 15:00:00'); // outside the range

    $lines = srCsv($this->actingAs($author)->get('/sales/export/lines.csv?range=custom&from=2026-10-01&to=2026-10-31')->assertOk());

    expect($lines[0])->toBe(['order', 'paid_at', 'date', 'book_id', 'title', 'format', 'channel', 'currency', 'units',
        'gross', 'discount', 'refunded', 'fee_estimate', 'net', 'partner_commission', 'royalty', 'status', 'buyer_country', 'bundle'])
        ->and(count($lines))->toBe(3)
        ->and($lines[1][4])->toBe('\'=HYPERLINK("http://x")')
        ->and($lines[1][2])->toBe('2026-10-10')
        ->and($lines[1][7])->toBe('USD')
        ->and($lines[1][9])->toBe('12.50')
        ->and($lines[1][12])->toBe(number_format(Revenue::fee(1250) / 100, 2))
        ->and($lines[2][4])->toBe('Réussir')
        ->and($lines[2][7])->toBe('EUR')
        ->and($lines[2][11])->toBe('9.99')
        ->and($lines[2][16])->toBe('refunded');

    $summary = srCsv($this->get('/sales/export/summary.csv?range=custom&from=2026-10-01&to=2026-10-31&compare=previous')->assertOk());
    $totals = collect($summary)->where(0, 'total')->values();
    expect($summary[0][0])->toBe('section')
        ->and($totals->count())->toBe(2)
        ->and($totals->firstWhere(3, 'USD')[6])->toBe('12.50')
        ->and(collect($summary)->where(0, 'compare_total')->count())->toBeGreaterThan(0)
        ->and(collect($summary)->where(0, 'book')->pluck(2)->all())->toContain('\'=HYPERLINK("http://x")');
});

it('streams a 5,000-order range in bounded memory', function () {
    $author = User::factory()->create();
    $book = Book::factory()->for($author)->create();
    $now = now();
    $fee = Revenue::fee(1000);

    foreach (array_chunk(range(1, 5000), 1000) as $chunk) {
        $orders = array_map(fn ($i) => [
            'number' => 'EC-T'.str_pad((string) $i, 7, '0', STR_PAD_LEFT), 'status' => 'paid', 'currency' => 'usd',
            'total_cents' => 1000, 'fee_cents' => $fee, 'refunded_cents' => 0, 'discount_cents' => 0,
            'paid_at' => CarbonImmutable::parse('2026-01-01 12:00', 'UTC')->addMinutes($i * 90), 'created_at' => $now, 'updated_at' => $now,
        ], $chunk);
        DB::table('orders')->insert($orders);
        $ids = DB::table('orders')->whereIn('number', array_column($orders, 'number'))->pluck('id');
        DB::table('order_items')->insert($ids->map(fn ($id) => [
            'order_id' => $id, 'book_id' => $book->id, 'seller_id' => $author->id, 'title' => $book->title, 'formats' => '["epub"]',
            'license_type' => 'personal', 'price_cents' => 1000, 'fee_cents' => $fee, 'royalty_cents' => 1000 - $fee, 'refunded_cents' => 0,
            'partner_commission_cents' => 0, 'created_at' => $now, 'updated_at' => $now,
        ])->all());
    }

    $response = $this->actingAs($author)->get('/sales/export/lines.csv?range=custom&from=2026-01-01&to=2026-12-31')->assertOk();
    $before = memory_get_usage();
    $rows = 0;
    $peakDelta = 0;
    ob_start(function (string $buffer) use (&$rows, &$peakDelta, $before) {
        $rows += substr_count($buffer, "\n");
        $peakDelta = max($peakDelta, memory_get_usage() - $before);

        return '';
    }, 8192);
    $response->baseResponse->sendContent();
    ob_end_flush();

    expect($rows)->toBe(5001)
        // A whole range held at once would be tens of megabytes; streamed,
        // it is a chunk of 500 lines at a time.
        ->and($peakDelta)->toBeLessThan(24 * 1024 * 1024);

    $totals = (new SalesExplorer([$author->id]))->aggregate(srRange('2026-01-01', '2026-12-31'))['totals'][0];
    expect($totals['units'])->toBe(5000)->and($totals['gross_cents'])->toBe(5_000_000);
});

// ── Statements ──────────────────────────────────────────────────────────

it('builds a royalty statement whose numbers are the report\'s', function () {
    $author = User::factory()->create(['author_name' => 'M. Évens']);
    $a = Book::factory()->for($author)->create(['title' => 'Alpha']);
    $b = Book::factory()->for($author)->create(['title' => 'Beta']);
    srSale($author, $a, 1000, '2026-08-20 15:00:00');
    srSale($author, $a, 1200, '2026-09-03 15:00:00');
    srSale($author, $b, 1500, '2026-09-30 23:30:00', ['partner' => 150]); // 19:30 in New York: still September
    srSale($author, $b, 800, '2026-10-01 03:30:00'); // 23:30 on 30 September in New York
    srSale($author, $b, 900, '2026-10-01 04:30:00'); // 00:30 on 1 October: not September
    Payout::create(['kind' => 'royalty', 'user_id' => $author->id, 'currency' => 'usd', 'amount_cents' => 500, 'method' => 'paypal', 'paid_on' => '2026-09-15']);
    Payout::create(['kind' => 'royalty', 'user_id' => $author->id, 'currency' => 'usd', 'amount_cents' => 300, 'method' => 'paypal', 'paid_on' => '2026-10-15']);

    $statement = app(RoyaltyStatements::class)->build($author, '2026-09', 'usd');
    $report = (new SalesExplorer([$author->id], ['currency' => 'usd']))->report(srRange('2026-09-01', '2026-09-30'));
    $total = $report['totals'][0];

    expect($statement['payee']['name'])->toBe('M. Évens')
        ->and($statement['totals']['units'])->toBe(3)
        ->and($statement['royalty_due_cents'])->toBe($total['royalty_cents'])
        ->and($statement['totals']['net_cents'])->toBe($total['net_cents'])
        ->and(collect($statement['books'])->sum('royalty_cents'))->toBe($statement['royalty_due_cents'])
        ->and(collect($statement['books'])->pluck('title')->all())->toBe(['Alpha', 'Beta'])
        ->and(collect($statement['books'])->firstWhere('title', 'Beta')['partner_cents'])->toBe(150);

    $august = (new SalesExplorer([$author->id]))->aggregate(srRange('2026-08-01', '2026-08-31'))['totals'][0]['royalty_cents'];
    expect($statement['earned_to_date_cents'])->toBe($august + $total['royalty_cents'])
        ->and($statement['paid_cents'])->toBe(500)
        ->and($statement['balance_cents'])->toBe($august + $total['royalty_cents'] - 500);

    // The quarter holds August and September (and the 30 September late sale).
    $q3 = app(RoyaltyStatements::class)->build($author, '2026-Q3', 'usd');
    expect($q3['totals']['units'])->toBe(4)
        ->and($q3['royalty_due_cents'])->toBe($august + $total['royalty_cents']);

    // The monthly email cuts the month the same way.
    expect(SalesReport::month($author, CarbonImmutable::parse('2026-09-01'))['usd']['royalty_cents'])->toBe($statement['royalty_due_cents']);

    // The rendered statement prints them.
    $html = view('pdf.royalty-statement', ['s' => $statement])->render();
    expect($html)->toContain('M. Évens')
        ->toContain(number_format($statement['royalty_due_cents'] / 100, 2).' USD')
        ->toContain(number_format($statement['balance_cents'] / 100, 2).' USD');

    $pdf = $this->actingAs($author)->get('/sales/statements/2026-09.pdf?currency=usd')->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(substr($pdf->getContent(), 0, 4))->toBe('%PDF')
        ->and($pdf->headers->get('Content-Disposition'))->toContain('2026-09-usd.pdf');

    $this->get('/sales/statements/2026-13.pdf?currency=usd')->assertNotFound();
    $this->get('/sales/statements/2026-09.pdf?currency=xxx')->assertSessionHasErrors('currency');

    // The Sales page lists the months and quarters with sales.
    $this->get('/sales')->assertInertia(fn (Assert $page) => $page
        ->where('statements', fn ($list) => collect($list)->pluck('period')->intersect(['2026-10', '2026-09', '2026-08', '2026-Q3', '2026-Q4'])->count() === 5));
});

it('gives staff the house imprint\'s statement as platform revenue, audited', function () {
    $house = app(HouseAccounts::class)->create('ecrivain-prepa', 'Écrivain Prépa', 'Test imprint');
    $book = Book::factory()->for($house)->create();
    srSale($house, $book, 1000, '2026-09-10 15:00:00');
    $staff = platformAdmin('support');

    $statement = app(RoyaltyStatements::class)->build($house, '2026-09', 'usd');
    expect($statement['payee']['house'])->toBeTrue()
        ->and($statement['payee']['name'])->toBe('Écrivain Prépa')
        ->and($statement['balance_cents'])->toBe(0)
        ->and($statement['paid_cents'])->toBe(0);
    expect(view('pdf.royalty-statement', ['s' => $statement])->render())->toContain('platform revenue');

    $this->actingAs($staff)->get("/sales/statements/2026-09.pdf?currency=usd&scope=house&payee={$house->id}")->assertOk();
    expect(AdminAuditLog::where('action', 'sales.statement_downloaded')->where('subject_id', $house->id)->exists())->toBeTrue();

    // An author is not a house payee, even to staff on the house scope.
    $author = User::factory()->create();
    $this->get("/sales/statements/2026-09.pdf?currency=usd&scope=house&payee={$author->id}")->assertNotFound();

    // The console's statement for any author is audited too.
    srSale($author, Book::factory()->for($author)->create(), 1000, '2026-09-10 15:00:00');
    $this->get("/admin/sales/statements/{$author->id}/2026-09.pdf?currency=usd")->assertOk();
    expect(AdminAuditLog::where('action', 'sales.statement_downloaded')->where('subject_id', $author->id)->value('actor_id'))->toBe($staff->id);
});

// ── Security ────────────────────────────────────────────────────────────

it('shows an author only their own sales, whatever the filters say', function () {
    $author = User::factory()->create();
    $other = User::factory()->create();
    $mine = Book::factory()->for($author)->create();
    $theirs = Book::factory()->for($other)->create(['title' => 'Not yours']);
    srSale($author, $mine, 1000, now()->subDays(2)->toDateTimeString());
    srSale($other, $theirs, 5000, now()->subDays(2)->toDateTimeString());

    $this->actingAs($author)->get("/sales?book[]={$theirs->id}")
        ->assertInertia(fn (Assert $page) => $page->where('report.totals', [])->where('options.books', fn ($books) => ! collect($books)->pluck('id')->contains($theirs->id)));

    $csv = srCsv($this->get('/sales/export/lines.csv?range=all'));
    expect(count($csv))->toBe(2)->and(collect($csv)->pluck(4)->all())->not->toContain('Not yours');

    // Another author's statement does not exist for you; the house scope is staff's.
    $this->get('/sales/statements/'.now()->format('Y-m').'.pdf?currency=usd&payee='.$other->id)->assertOk(); // your own, the payee ignored
    $this->get('/sales?scope=house')->assertForbidden();
    $this->get('/sales/export/lines.csv?scope=house')->assertForbidden();
    $this->get("/admin/sales/statements/{$other->id}/2026-09.pdf?currency=usd")->assertNotFound();
});

it('gives staff the house sales with every export audited', function () {
    $house = app(HouseAccounts::class)->create('ecrivain-prepa', 'Écrivain Prépa', 'Test imprint');
    $author = User::factory()->create();
    srSale($house, Book::factory()->for($house)->create(['title' => 'Prépa 1']), 1000, now()->subDay()->toDateTimeString());
    srSale($author, Book::factory()->for($author)->create(['title' => 'An author\'s']), 2000, now()->subDay()->toDateTimeString());
    $staff = platformAdmin('support');

    $this->actingAs($staff)->get('/sales?scope=house')
        ->assertInertia(fn (Assert $page) => $page
            ->where('scope', 'house')
            ->where('report.house', true)
            ->where('report.totals.0.gross_cents', 1000)
            ->has('report.products', 1));

    $csv = srCsv($this->get('/sales/export/lines.csv?scope=house'));
    expect(collect($csv)->pluck(4)->all())->toContain('Prépa 1')->not->toContain('An author\'s');
    expect(AdminAuditLog::where('action', 'sales.exported')->where('actor_id', $staff->id)->count())->toBe(1);

    // Staff's own report is still only their own.
    $this->get('/sales')->assertInertia(fn (Assert $page) => $page->where('scope', 'own')->where('report.totals', []));
});

it('rate-limits exports', function () {
    $author = User::factory()->create();
    $this->actingAs($author);

    foreach (range(1, 10) as $i) {
        $this->get('/sales/export/summary.csv')->assertOk();
    }

    $this->get('/sales/export/summary.csv')->assertStatus(429);
});

it('keeps the default: the last 30 days, by day, in the account zone', function () {
    $author = User::factory()->create();
    $book = Book::factory()->for($author)->create();
    srSale($author, $book, 1000, now()->subDays(40)->toDateTimeString());
    srSale($author, $book, 1000, now()->subHour()->toDateTimeString());

    $this->actingAs($author)->get('/sales')
        ->assertInertia(fn (Assert $page) => $page
            ->where('query.range', 'last_30')
            ->where('query.compare', null)
            ->where('report.range.bucket', 'day')
            ->where('report.range.tz', 'America/New_York')
            ->has('report.series', 30)
            ->where('report.series.29.key', now()->setTimezone('America/New_York')->toDateString())
            ->where('report.totals.0.units', 1)
            ->where('report.compare', null));

    // The account's own zone, once chosen.
    $this->put('/sales/timezone', ['timezone' => 'Europe/Paris'])->assertRedirect();
    expect($author->fresh()->reportTimezone())->toBe('Europe/Paris');
    $this->put('/sales/timezone', ['timezone' => 'Mars/Olympus'])->assertSessionHasErrors('timezone');
    $this->get('/sales')->assertInertia(fn (Assert $page) => $page->where('report.range.tz', 'Europe/Paris'));
});

// ── Partner earnings (PP2) ──────────────────────────────────────────────

it('gives a partner a date range and CSV of their own commissions only', function () {
    partnerProgram();
    $partner = partnerFor();
    $rival = partnerFor();
    $row = fn ($p, string $at, int $cents, string $title) => tap(new PartnerCommission)->forceFill([
        'partner_id' => $p->id, 'kind' => 'sale', 'currency' => 'usd', 'rate_bps' => 2500, 'base_cents' => $cents * 4,
        'amount_cents' => $cents, 'status' => 'approved', 'title' => $title, 'buyer_country' => 'HT',
        'created_at' => CarbonImmutable::parse($at, 'UTC'), 'updated_at' => CarbonImmutable::parse($at, 'UTC'),
    ])->save();

    $row($partner, '2026-09-10 15:00', 250, 'September book');
    $row($partner, '2026-10-01 03:00', 300, 'Late September'); // 23:00 on 30 September in New York
    $row($partner, '2026-10-10 15:00', 400, '=cmd|calc');
    $row($rival, '2026-09-12 15:00', 999, 'Rival sale');

    $earnings = new Earnings($partner);
    $sept = srRange('2026-09-01', '2026-09-30');
    expect($earnings->totals($sept))->toBe([['currency' => 'usd', 'sales' => 2, 'net_cents' => 550, 'pending_cents' => 0, 'approved_cents' => 550, 'paid_cents' => 0, 'reversed_cents' => 0]]);

    $this->actingAs($partner->user)->get('/partner?tab=commissions&range=custom&from=2026-09-01&to=2026-09-30')
        ->assertInertia(fn (Assert $page) => $page
            ->has('commissions', 2)
            ->where('earnings.range.from', '2026-09-01')
            ->where('earnings.totals.0.net_cents', 550));

    // With no range: everything, as the tab always showed.
    $this->get('/partner?tab=commissions')->assertInertia(fn (Assert $page) => $page->has('commissions', 3)->where('earnings.range.preset', 'all'));

    $csv = srCsv($this->get('/partner/commissions.csv?range=custom&from=2026-09-01&to=2026-10-31')->assertOk());
    expect(count($csv))->toBe(4)
        ->and(collect($csv)->pluck(3)->all())->not->toContain('Rival sale')
        ->and(collect($csv)->pluck(3)->all())->toContain('\'=cmd|calc')
        ->and($csv[1][7])->toBe('2.50');

    // Somebody without a partner record has no commissions to export.
    $this->actingAs(User::factory()->create())->get('/partner/commissions.csv')->assertNotFound();
});
