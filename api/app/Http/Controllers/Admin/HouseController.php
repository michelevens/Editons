<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Platform\House\HouseAccounts;
use App\Platform\House\HouseCatalog;
use App\Platform\House\HouseTransfer;
use App\Publishing\PrintSpecs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The House catalog (ADR — house catalog and house publisher accounts).
 *
 * Every book a house publisher account owns, with what staff need to run an
 * imprint: exports, print readiness, price, store status and sales. All
 * staff read it; every action is the owner's, needs a reason and is audited
 * by HouseCatalog / HouseTransfer in the same transaction as the change.
 * Writing in the book itself happens in the studio, which the owner opens
 * as themselves (BookPolicy, via App\Platform\House\House).
 */
class HouseController extends Controller
{
    public function index(Request $request, HouseCatalog $catalog, HouseAccounts $accounts): Response
    {
        $books = $catalog->books();

        return Inertia::render('Admin/House/Index', [
            'houses' => $accounts->all()->map(fn ($h) => [
                'id' => $h->id,
                'name' => $h->name,
                'slug' => $h->house_slug,
                'books' => $books->where('user_id', $h->id)->count(),
                'account' => route('admin.accounts.show', $h->id),
            ])->values(),
            'books' => $books->map(fn (Book $book) => $catalog->row($book))->values(),
            'totals' => $catalog->totals($books),
            'options' => [
                'exports' => [
                    ['pdf', 'PDF'],
                    ['pdf_interior', 'Print interior'],
                    ['cover_wrap', 'Print cover wrap'],
                    ['epub', 'EPUB'],
                ],
                'services' => collect(PrintSpecs::services())->map(fn ($s) => [$s, PrintSpecs::label($s)])->values(),
                'currencies' => config('commerce.currencies'),
                'formats' => config('commerce.formats'),
                'isbn_editions' => Book::ISBN_EDITIONS,
                'isbn_sources' => Book::ISBN_SOURCES,
            ],
            'results' => $request->session()->get('house_results'),
            'can' => ['manage' => $request->user()->isPlatformOwner()],
        ]);
    }

    /** The dry run of a transfer: what would move, and what would stop it. */
    public function preview(Request $request, HouseTransfer $transfer, HouseAccounts $accounts): JsonResponse
    {
        $data = $this->transferInput($request);

        return response()->json($transfer->plan($data['books'], $this->house($accounts, $data['to']), ! $data['drop_previous']));
    }

    public function transfer(Request $request, HouseTransfer $transfer, HouseAccounts $accounts): RedirectResponse
    {
        $data = $this->transferInput($request, reason: true);
        $plan = $transfer->apply($data['books'], $this->house($accounts, $data['to']), $data['reason'], $request->user(), ! $data['drop_previous']);

        return back()->with('platform_notice', count($plan['books']).' book(s) moved to '.$plan['house']['name'].'.');
    }

    public function export(Request $request, Book $book, HouseCatalog $catalog): RedirectResponse
    {
        $data = $request->validate([
            'format' => ['required', 'string', 'in:'.implode(',', HouseCatalog::EXPORTS)],
            'options' => ['sometimes', 'array'],
            'options.service' => ['sometimes', 'string', 'max:20'],
            'options.paper' => ['sometimes', 'string', 'max:20'],
            'options.spine' => ['sometimes', 'nullable', 'numeric'],
            'reason' => ['required', 'string', 'min:4', 'max:1000'],
        ]);

        $export = $catalog->export($book, $data['format'], $data['options'] ?? [], $request->user(), $data['reason']);

        return back()->with('platform_notice', $export->wasRecentlyCreated ? 'Export queued.' : 'That export is already rendering.');
    }

    public function price(Request $request, Book $book, HouseCatalog $catalog): RedirectResponse
    {
        $data = $this->priceInput($request);
        $catalog->price($book, $data['price_cents'], $data['currency'], $data['formats'], $request->user(), $data['reason']);

        return back()->with('platform_notice', 'Price saved.');
    }

    public function publish(Request $request, Book $book, HouseCatalog $catalog): RedirectResponse
    {
        $catalog->publish($book, $request->user(), $this->reason($request));

        return back()->with('platform_notice', 'On sale.');
    }

    public function unpublish(Request $request, Book $book, HouseCatalog $catalog): RedirectResponse
    {
        $catalog->unpublish($book, $request->user(), $this->reason($request));

        return back()->with('platform_notice', 'Taken off sale. Buyers keep their copies.');
    }

    public function isbnPlan(Request $request, Book $book, HouseCatalog $catalog): RedirectResponse
    {
        $data = $request->validate([
            'edition' => ['required', 'string', 'in:'.implode(',', Book::ISBN_EDITIONS)],
            'source' => ['required', 'string', 'in:'.implode(',', Book::ISBN_SOURCES)],
            'reason' => ['required', 'string', 'min:4', 'max:1000'],
        ]);

        $catalog->isbnPlan($book, $data['edition'], $data['source'], $request->user(), $data['reason']);

        return back()->with('platform_notice', 'ISBN plan saved.');
    }

    /** One action across the selected books; each book's result is shown. */
    public function bulk(Request $request, HouseCatalog $catalog): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'in:'.implode(',', HouseCatalog::BULK)],
            'books' => ['required', 'array', 'min:1', 'max:200'],
            'books.*' => ['integer'],
            'format' => ['required_if:action,export', 'nullable', 'string', 'in:'.implode(',', HouseCatalog::EXPORTS)],
            'options' => ['sometimes', 'array'],
            'options.service' => ['sometimes', 'string', 'max:20'],
            'options.paper' => ['sometimes', 'string', 'max:20'],
            'price' => ['required_if:action,price', 'nullable', 'numeric', 'min:0', 'max:9999.99'],
            'currency' => ['required_if:action,price', 'nullable', 'string', 'in:'.implode(',', config('commerce.currencies'))],
            'formats' => ['sometimes', 'nullable', 'array'],
            'formats.*' => ['string', 'in:'.implode(',', config('commerce.formats'))],
            'reason' => ['required', 'string', 'min:4', 'max:1000'],
        ]);

        $results = $catalog->bulk($data['action'], $data['books'], [
            'format' => $data['format'] ?? null,
            'options' => $data['options'] ?? [],
            'price_cents' => isset($data['price']) ? (int) round(((float) $data['price']) * 100) : null,
            'currency' => $data['currency'] ?? null,
            'formats' => $data['formats'] ?? null,
        ], $request->user(), $data['reason']);

        $ok = collect($results)->where('ok', true)->count();

        return back()
            ->with('house_results', ['action' => $data['action'], 'rows' => $results])
            ->with('platform_notice', "{$ok} of ".count($results).' book(s) done.');
    }

    // ── Internals ───────────────────────────────────────────────────────

    /** @return array{books: list<int>, to: string, drop_previous: bool, reason?: string} */
    private function transferInput(Request $request, bool $reason = false): array
    {
        $data = $request->validate([
            'books' => ['required', 'array', 'min:1', 'max:200'],
            'books.*' => ['integer'],
            'to' => ['required', 'string', 'max:40'],
            'drop_previous' => ['sometimes', 'boolean'],
            'reason' => [$reason ? 'required' : 'nullable', 'string', 'min:4', 'max:1000'],
        ]);

        return [
            'books' => array_map('intval', $data['books']),
            'to' => $data['to'],
            'drop_previous' => (bool) ($data['drop_previous'] ?? false),
            'reason' => $data['reason'] ?? '',
        ];
    }

    /** @return array{price_cents: int, currency: string, formats: ?list<string>, reason: string} */
    private function priceInput(Request $request): array
    {
        $data = $request->validate([
            'price' => ['required', 'numeric', 'min:0', 'max:9999.99'],
            'currency' => ['required', 'string', 'in:'.implode(',', config('commerce.currencies'))],
            'formats' => ['sometimes', 'nullable', 'array', 'min:1'],
            'formats.*' => ['string', 'distinct', 'in:'.implode(',', config('commerce.formats'))],
            'reason' => ['required', 'string', 'min:4', 'max:1000'],
        ]);

        return [
            'price_cents' => (int) round(((float) $data['price']) * 100),
            'currency' => $data['currency'],
            'formats' => $data['formats'] ?? null,
            'reason' => $data['reason'],
        ];
    }

    private function reason(Request $request): string
    {
        return $request->validate(['reason' => ['required', 'string', 'min:4', 'max:1000']])['reason'];
    }

    private function house(HouseAccounts $accounts, string $to)
    {
        return $accounts->find($to) ?? throw ValidationException::withMessages(['to' => 'No house account with that id or slug.']);
    }
}
