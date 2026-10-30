<?php

namespace App\Http\Controllers;

use App\Commerce\Partners\Program;
use App\Commerce\Partners\SellerPartners;
use App\Commerce\Reports\ReportRange;
use App\Commerce\Reports\RoyaltyStatements;
use App\Commerce\Reports\SalesExplorer;
use App\Commerce\SalesReport;
use App\Models\Book;
use App\Models\BookChannel;
use App\Models\User;
use App\Platform\AdminAudit;
use App\Platform\Features;
use App\Platform\House\House;
use App\Publishing\PrintPages;
use App\Publishing\Royalties;
use App\Shelf\ShelfBulk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sales & Distribution, and Royalties.
 *
 * There is no store integration, so there are no sales. The prototype fills
 * both screens from a seeded random generator and tags the result "Sample
 * data"; that is a reasonable thing for a prototype to do and the wrong thing
 * here, because a number on a royalties screen is one an author will believe.
 *
 * So these report what is actually known — which stores have the book, and
 * what each would pay per copy at the configured price — and say plainly that
 * figures appear once a store starts reporting. The store share is the
 * published rate for each one (config/publishing.php), not a guess.
 *
 * Direct sales are different: the author's own storefront takes real orders
 * (App\Commerce), so those figures are real — gross, estimated payment fees,
 * net, royalty, refunds — and only ever the owner's own. A co-editor who can
 * view the book sees the store terms but not the money.
 */
class SalesController extends Controller
{
    public function show(Request $request, Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        // The owner's own sales of this book, and nobody else's eyes.
        $direct = Features::enabled('storefront', $request->user()) && $request->user()->can('manage', $book)
            ? ['product' => $book->product ? [
                'published' => $book->product->isPublished(),
                'url' => $book->product->isPublished() ? route('store.product', $book->product->slug) : null,
            ] : null] + SalesReport::for($book->user, $book)
            : null;

        $channels = $book->channels()->get();
        $prices = Royalties::prices($book);
        $price = $prices['ebook'];

        $live = $channels->where('status', 'live');
        $reviewing = $channels->where('status', 'in_review');

        return response()->json([
            'price' => $book->list_price,
            'currency' => 'USD',
            'status' => [
                'live' => $live->count(),
                'in_review' => $reviewing->count(),
                'selected' => $channels->count(),
            ],
            'channels' => $channels
                ->map(function (BookChannel $channel) use ($book, $price, $prices) {
                    $terms = $this->terms($book, $channel->channel, $price, $prices['print']);

                    return [
                        'channel' => $channel->channel,
                        'label' => $channel->label(),
                        'format' => $channel->format,
                        'status' => $channel->status,
                        'went_live_at' => $channel->went_live_at?->toFormattedDateString(),
                        // The store's own id (an ASIN), once the author gave it.
                        'external_id' => $channel->external_id,
                        'submitted_at' => $channel->submitted_at?->toFormattedDateString(),
                        'rate' => $terms['rate'],
                        'note' => $terms['note'],
                        // Per copy at the current list price: arithmetic on
                        // a published rate (App\Publishing\Royalties), not a
                        // projection of sales. Null where a store does not
                        // publish what the arithmetic needs.
                        'per_copy' => $terms['royalty'],
                        // Always zero until a store reports. Present so the
                        // table has a column rather than appearing broken.
                        'units' => 0,
                        'earnings' => 0,
                    ];
                })
                ->values()
                ->all(),
            // Real orders from the author's own storefront, owner only.
            'direct' => $direct,
            // Said once, plainly, rather than printed beside every zero.
            'reporting' => [
                'connected' => false,
                'message' => $live->isEmpty() && ($direct['product']['published'] ?? false)
                    ? __('Your storefront is selling this book; its sales are real orders, shown below. No other store has the book on sale yet.')
                    : ($live->isEmpty()
                    ? __('No store has this book on sale yet. Sales and royalties appear here once one does.')
                    : __('Sales reporting is not connected yet, so the figures below are not live. Each store reports on its own schedule, usually monthly.')),
            ],
        ]);
    }

    /**
     * A store's published terms at a price (config/publishing.php). eBook
     * rates depend on price bands at the major stores, so the note names
     * the band rather than implying one rate; print royalties subtract the
     * print cost, from the paperback's own price.
     *
     * @return array{rate: ?float, royalty: ?float, note: string}
     */
    private function terms(Book $book, string $channel, ?float $price, ?float $printPrice): array
    {
        $result = match ($channel) {
            'amazon_kdp' => Royalties::kindle($price, null, $printPrice),
            'apple_books', 'kobo', 'google_play' => Royalties::ebookStore($channel, $price, $printPrice),
            'kdp_paperback' => Royalties::kdpPaperback($printPrice, PrintPages::for($book)['pages'], $book->printSettings()['ink'], $book->layout()['trim']),
            'ingram_print' => Royalties::ingramPrint($printPrice),
            default => ['rate' => null, 'royalty' => null, 'note' => ''],
        };

        return [
            'rate' => $result['rate'] ?? null,
            'royalty' => $price === null && in_array($channel, ['amazon_kdp', 'apple_books', 'kobo', 'google_play'], true) ? null : ($result['royalty'] ?? null),
            'note' => $result['note'],
        ];
    }

    /**
     * Every direct sale this author has made, across their books, over a
     * date range (ADR-0046 — Sales reports and royalty statements).
     *
     * Scoped to the signed-in seller: there is no parameter to point it at
     * anybody else. Staff may ask for `scope=house`, the house accounts'
     * sales (ADR-0035) — the console's own books, never another author's.
     *
     * The range, comparison and filters are query parameters, so a report
     * can be bookmarked and shared; with none it is the last 30 days.
     */
    public function overview(Request $request): Response
    {
        $user = $request->user();
        [$explorer, $tz, $house] = $this->explorer($request);
        [$range, $compare] = $this->range($request, $explorer, $tz);

        return Inertia::render('Sales', [
            'enabled' => Features::enabled('storefront', $user),
            'report' => $explorer->report($range, $compare),
            'query' => [
                'scope' => $house ? 'house' : null,
                'range' => $range->preset,
                'from' => $range->from->toDateString(),
                'to' => $range->to->toDateString(),
                'compare' => $compare,
            ] + $explorer->filters,
            'options' => $explorer->options() + [
                'presets' => collect(ReportRange::LABELS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
                'timezones' => self::timezones($tz),
            ],
            'timezone' => $tz,
            'scope' => $house ? 'house' : 'own',
            'can_house' => House::canViewCatalog($user),
            'statements' => $this->statementList($request, $explorer, $tz, $house),
            'products' => $house ? [] : $user->products()
                ->with('book:id,title')
                ->get()
                ->map(fn ($product) => [
                    'title' => $product->displayTitle(),
                    'book_id' => $product->book_id,
                    'published' => $product->isPublished(),
                    'url' => $product->isPublished() ? route('store.product', $product->slug) : null,
                    'price_cents' => $product->price_cents,
                    'currency' => $product->currency,
                    'partner_enabled' => (bool) $product->partner_enabled,
                ])
                ->all(),
            // Écrivain Partners selling this author's books (SellerPartners).
            'partners' => ! $house && Program::enabled($user) ? SellerPartners::for($user) : null,
        ]);
    }

    /**
     * The current view as CSV: one row per order line (`lines`) or the
     * totals and every breakdown (`summary`). Streamed, with a byte-order
     * mark, every text cell defused (ShelfBulk::csvCell, ADR-0045).
     */
    public function export(Request $request, string $kind): StreamedResponse
    {
        [$explorer, $tz, $house] = $this->explorer($request);
        [$range, $compare] = $this->range($request, $explorer, $tz);

        if ($house) {
            AdminAudit::transaction(fn () => AdminAudit::record('sales.exported', null, null, [
                'scope' => 'house',
                'kind' => $kind,
                'range' => [$range->from->toDateString(), $range->to->toDateString()],
                'filters' => $explorer->filters,
            ], 'Sales CSV from the house sales report', $request->user()));
        }

        $rows = $kind === 'summary'
            ? SalesExplorer::csvSummary($explorer->report($range, $compare))
            : $explorer->csvLines($range);

        return self::csv($rows, ($house ? 'house-' : '').'sales-'.$kind.'-'.$range->from->toDateString().'-'.$range->to->toDateString().'.csv');
    }

    /** A royalty statement for one period and currency, as a PDF. */
    public function statement(Request $request, string $period, RoyaltyStatements $statements): HttpResponse
    {
        [$explorer, , $house] = $this->explorer($request);
        $currency = $request->validate(['currency' => ['required', Rule::in(config('commerce.currencies'))]])['currency'];

        // The payee: the signed-in author, or one of the house accounts.
        $payee = $house
            ? User::where('account_kind', 'house')->whereKey((int) $request->query('payee'))->first()
            : $request->user();
        abort_unless($payee && in_array($payee->id, $explorer->sellerIds, true), 404);

        $statement = $statements->build($payee, $period, $currency);

        if ($house) {
            AdminAudit::transaction(fn () => AdminAudit::record('sales.statement_downloaded', $payee, null, [
                'period' => $period, 'currency' => $currency,
            ], 'Royalty statement from the house sales report', $request->user()));
        }

        return self::pdf($statements, $statement);
    }

    /** The zone this account's reports cut days in. */
    public function timezone(Request $request): RedirectResponse
    {
        $data = $request->validate(['timezone' => ['required', 'string', Rule::in(\DateTimeZone::listIdentifiers())]]);
        $request->user()->forceFill(['timezone' => $data['timezone']])->save();

        return back()->with('platform_notice', __('Sales reports now use :timezone.', ['timezone' => $data['timezone']]));
    }

    /**
     * Rows as a CSV download: streamed, a byte-order mark so Excel reads
     * "Réussir" as UTF-8, every cell defused (ShelfBulk::csvCell).
     *
     * @param  iterable<list<mixed>>  $rows
     */
    public static function csv(iterable $rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            foreach ($rows as $row) {
                fputcsv($out, array_map([ShelfBulk::class, 'csvCell'], $row), ',', '"', '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /** @param  array<string, mixed>  $statement */
    public static function pdf(RoyaltyStatements $statements, array $statement): HttpResponse
    {
        return response($statements->pdf($statement), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.RoyaltyStatements::filename($statement).'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Whose sales, in which zone: the signed-in seller's own, or — for
     * staff asking for scope=house — every house account's.
     *
     * @return array{0: SalesExplorer, 1: string, 2: bool}
     */
    private function explorer(Request $request): array
    {
        $user = $request->user();
        $filters = SalesExplorer::filtersFrom($request->query());

        if ($request->query('scope') === 'house') {
            abort_unless(House::canViewCatalog($user), 403);

            return [new SalesExplorer(User::where('account_kind', 'house')->orderBy('id')->pluck('id')->all(), $filters, true), $user->reportTimezone(), true];
        }

        return [new SalesExplorer([$user->id], $filters, $user->isHouse()), $user->reportTimezone(), false];
    }

    /** @return array{0: ReportRange, 1: ?string} */
    private function range(Request $request, SalesExplorer $explorer, string $tz): array
    {
        $range = ReportRange::fromInput($request->query(), $tz, $request->query('range') === 'all' ? $explorer->firstSale() : null);
        $compare = in_array($request->query('compare'), ReportRange::COMPARE, true) ? $request->query('compare') : null;

        return [$range, $compare];
    }

    /** @return list<array<string, mixed>> */
    private function statementList(Request $request, SalesExplorer $explorer, string $tz, bool $house): array
    {
        $payees = $house
            ? User::whereIn('id', $explorer->sellerIds)->orderBy('name')->get(['id', 'name', 'account_kind'])
            : collect([$request->user()]);

        return $payees->flatMap(fn (User $payee) => collect(RoyaltyStatements::periods([$payee->id], $tz, 12))
            ->map(fn (array $p) => $p + ['payee' => ['id' => $payee->id, 'name' => $payee->name]]))
            ->values()
            ->all();
    }

    /** A short list for the zone picker, with the current zone in it. @return list<string> */
    private static function timezones(string $current): array
    {
        return collect(['America/New_York', 'America/Port-au-Prince', 'America/Toronto', 'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'Europe/Paris', 'Europe/London', 'UTC'])
            ->push($current)
            ->unique()
            ->values()
            ->all();
    }
}
