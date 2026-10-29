<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Editorial\Style\HouseGuideTemplate;
use App\Models\Book;
use App\Models\HouseStyle;
use App\Platform\House\HouseAccounts;
use App\Platform\House\HouseCatalog;
use App\Platform\House\HouseTransfer;
use App\Publishing\PrintSpecs;
use App\Publishing\Publish\BulkPrepare;
use App\Publishing\Publish\GooglePlayBulk;
use App\Publishing\Publish\KdpGuide;
use App\Shelf\Shelf;
use App\Shelf\ShelfPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
    /**
     * The catalog as a shelf (ADR-0045 — Shelf views and bulk actions):
     * searched, sorted, grouped and paged on the server, each page's books
     * with the catalog's full row. Counts and sales totals are the whole
     * catalog's.
     */
    public function index(Request $request, HouseCatalog $catalog, HouseAccounts $accounts, ShelfPage $shelf): Response
    {
        $houses = $accounts->all();
        $state = $shelf->state($request, 'house');
        $page = $shelf->build($request, 'house', $state, fn (Book $book) => $catalog->row($book),
            fn ($query) => $query->with(['user:id,name,house_slug,account_kind', 'product', 'houseStyle:id,name']));
        $perHouse = Book::whereIn('user_id', $houses->pluck('id'))->toBase()
            ->selectRaw('user_id, count(*) as n')->groupBy('user_id')->pluck('n', 'user_id');

        return Inertia::render('Admin/House/Index', $page + [
            'houses' => $houses->map(fn ($h) => [
                'id' => $h->id,
                'name' => $h->name,
                'slug' => $h->house_slug,
                'books' => (int) ($perHouse[$h->id] ?? 0),
                'account' => route('admin.accounts.show', $h->id),
            ])->values(),
            'counts' => Shelf::counts($request->user(), 'house'),
            'totals' => $catalog->totals(Book::whereIn('user_id', $houses->pluck('id'))->get(['id'])),
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
                // Each house account's styles (HouseStyles), saved from one
                // of its books in Design & Layout.
                'house_styles' => HouseStyle::whereIn('user_id', $houses->pluck('id'))->orderBy('name')
                    ->get(['id', 'name', 'user_id', 'version'])->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'house_id' => $s->user_id, 'version' => $s->version])->values(),
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

    /**
     * The house style guide (ADR — Style guides and review options): what
     * it would hold — its rules and the Kreyòl glossary words found in the
     * house's own books — before anything is created.
     */
    public function styleGuidePreview(Request $request, HouseAccounts $accounts, HouseGuideTemplate $template): JsonResponse
    {
        $data = $request->validate(['house' => ['required']]);

        return response()->json($template->preview($this->house($accounts, (string) $data['house'])));
    }

    /** Create it on the house account, with a reason, audited. */
    public function styleGuide(Request $request, HouseAccounts $accounts, HouseGuideTemplate $template): RedirectResponse
    {
        $data = $request->validate(['house' => ['required'], 'reason' => ['required', 'string', 'min:4', 'max:1000']]);
        $guide = $template->create($this->house($accounts, (string) $data['house']), $request->user(), $data['reason']);

        return back()->with('platform_notice', "Style guide “{$guide->name}” created. Attach it to the house's books from the AI Editor.");
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
            'house_style_id' => ['required_if:action,house_style', 'nullable', 'integer'],
            'follow' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'min:4', 'max:1000'],
        ]);

        $results = $catalog->bulk($data['action'], $data['books'], [
            'house_style_id' => $data['house_style_id'] ?? null,
            'follow' => $data['follow'] ?? true,
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

    /**
     * "Prepare for publishing" across the selected house books: step 1 of
     * Publish for each, stale files regenerated, and links to one Google
     * Play book list and zip and one KDP checklist. Audited per book.
     */
    public function prepare(Request $request, HouseCatalog $catalog, BulkPrepare $bulk): RedirectResponse
    {
        $data = $request->validate([
            'books' => ['required', 'array', 'min:1', 'max:200'],
            'books.*' => ['integer'],
            'reason' => ['required', 'string', 'min:4', 'max:1000'],
        ]);

        $books = $this->houseBooks($catalog, $data['books']);
        $rows = $bulk->run($books, $request->user(), $data['reason']);
        $query = ['books' => $books->pluck('id')->all()];

        return back()
            ->with('house_results', [
                'action' => 'prepare',
                'rows' => $rows,
                'links' => [
                    'google_play' => route('admin.house.google-play', $query),
                    'kdp_checklist' => route('admin.house.kdp-checklist', $query),
                ],
            ])
            ->with('platform_notice', collect($rows)->where('status', 'green')->count().' of '.count($rows).' book(s) ready; files regenerating where stale.');
    }

    /** One Google Play book list and zip for the selected house books. */
    public function googlePlay(Request $request, HouseCatalog $catalog, GooglePlayBulk $bulk): BinaryFileResponse
    {
        $books = $this->houseBooks($catalog, (array) $request->query('books', []));

        return response()->download($bulk->zip($books), 'google-play-house-'.now()->format('Y-m-d').'.zip', ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }

    /** One KDP guided page per selected house book, in catalog order. */
    public function kdpChecklist(Request $request, HouseCatalog $catalog, KdpGuide $kdp): Response
    {
        $books = $this->houseBooks($catalog, (array) $request->query('books', []));

        return Inertia::render('Publish/KdpChecklist', $kdp->checklist($books, route('admin.house.index')));
    }

    // ── Internals ───────────────────────────────────────────────────────

    /**
     * The selected books, every one a house book, in the order asked.
     *
     * @param  list<mixed>  $ids
     * @return Collection<int, Book>
     */
    private function houseBooks(HouseCatalog $catalog, array $ids)
    {
        $ids = array_values(array_unique(array_slice(array_map('intval', $ids), 0, 200)));
        abort_if($ids === [], 404);

        $books = Book::with('user', 'product')->whereIn('id', $ids)->get()
            ->sortBy(fn (Book $b) => array_search($b->id, $ids, true))->values();

        abort_if($books->count() !== count($ids), 404);
        $books->each(fn (Book $book) => $catalog->assertHouseBook($book));

        return $books;
    }

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
