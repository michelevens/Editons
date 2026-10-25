<?php

namespace App\Http\Controllers;

use App\Commerce\Print\LuluPrinting;
use App\Models\Book;
use App\Publishing\Publish\BulkPrepare;
use App\Publishing\Publish\ChannelSubmission;
use App\Publishing\Publish\GooglePlayBulk;
use App\Publishing\Publish\KdpGuide;
use App\Publishing\Publish\PublishCheck;
use App\Publishing\Publish\PublishPlan;
use App\Publishing\Publish\Regenerate;
use App\Publishing\Publish\StoreListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Publish in three steps (ADR — publish in three steps,
 * docs/PUBLISHING-FLOW.md): Check, Choose channels, Publish.
 *
 * Reading the screen and the work that does not spend or sell — checks,
 * channel choices, regenerating files, KDP values, marking a store
 * submitted — needs `update`, like Export & Publish (an editor may do
 * them). Putting the book on sale and sending its files to Lulu needs
 * `manage`, like the storefront's product: that is the owner's money. A
 * house book is the platform owner's on both counts (BookPolicy).
 */
class PublishFlowController extends Controller
{
    public function __construct(
        private readonly PublishCheck $check,
        private readonly PublishPlan $plan,
        private readonly KdpGuide $kdp,
        private readonly LuluPrinting $lulu,
    ) {}

    public function show(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        return response()->json($this->payload($book, $request));
    }

    /** Step 1's one-click fix for stale or missing files. */
    public function regenerate(Request $request, Book $book, Regenerate $regenerate): JsonResponse
    {
        $this->authorize('update', $book);

        $results = $regenerate->stale($book, $request->user());

        return response()->json([
            'results' => $results,
            'message' => $results === [] ? 'Every file is up to date.' : 'Making '.count($results).' file'.(count($results) === 1 ? '' : 's').'. They appear here when they are ready.',
        ] + $this->payload($book->fresh(), $request));
    }

    public function channel(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'card' => ['required', 'string', 'in:'.implode(',', array_keys(PublishPlan::CARDS))],
            'on' => ['required', 'boolean'],
        ]);

        $this->plan->choose($book, $data['card'], $data['on']);

        return response()->json($this->payload($book->fresh(), $request));
    }

    /** The KDP values the author edits: keywords, categories, audience. */
    public function kdp(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $limits = config('publishing.publish.kdp');
        $data = $request->validate([
            'keywords' => ['sometimes', 'array', 'max:'.$limits['keywords']],
            'keywords.*' => ['nullable', 'string', 'max:'.$limits['keyword_chars']],
            'categories' => ['sometimes', 'array', 'max:'.$limits['categories']],
            'categories.*' => ['nullable', 'string', 'max:200'],
            'explicit' => ['sometimes', 'nullable', 'boolean'],
            'min_age' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:18'],
            'max_age' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:18', 'gte:min_age'],
        ]);

        $saved = ($book->publish_plan ?? [])['kdp'] ?? [];

        foreach (['keywords', 'categories'] as $list) {
            if (array_key_exists($list, $data)) {
                $saved[$list] = array_values(array_filter(array_map(fn ($v) => trim(preg_replace('/\s+/', ' ', (string) $v)), $data[$list]), fn ($v) => $v !== ''));
            }
        }

        foreach (['explicit', 'min_age', 'max_age'] as $field) {
            if (array_key_exists($field, $data)) {
                $saved[$field] = $data[$field];
            }
        }

        $this->plan->save($book, ['kdp' => $saved]);

        return response()->json($this->payload($book->fresh(), $request));
    }

    /** Our store: put the ebook on sale (Commerce\Listing's rule). */
    public function store(Request $request, Book $book, StoreListing $listing): JsonResponse
    {
        $this->authorize('manage', $book);

        $product = $listing->publish($book, $request->user());

        return response()->json([
            'message' => 'On sale in your store.',
            'store_url' => route('store.product', $product->slug),
        ] + $this->payload($book->fresh(), $request));
    }

    /** Lulu: send the print files to Lulu's validation. */
    public function lulu(Request $request, Book $book): JsonResponse
    {
        $this->authorize('manage', $book);

        $this->lulu->prepare($book, $request->user());

        return response()->json(['message' => 'Sent to Lulu. Its checks take a few minutes.'] + $this->payload($book->fresh(), $request));
    }

    public function luluRefresh(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $this->lulu->refresh($book);

        return response()->json($this->payload($book->fresh(), $request));
    }

    /** Lulu: sell the paperback in the store (or stop). */
    public function luluOffer(Request $request, Book $book): JsonResponse
    {
        $this->authorize('manage', $book);

        $data = $request->validate(['on' => ['required', 'boolean']]);
        $data['on'] ? $this->lulu->offer($book) : $this->lulu->withdraw($book);

        return response()->json([
            'message' => $data['on'] ? 'The paperback is on sale in your store.' : 'The paperback is no longer offered. Paid orders are still printed.',
        ] + $this->payload($book->fresh(), $request));
    }

    /** "I've submitted it": KDP (with the ASIN) or Google Play. */
    public function submitted(Request $request, Book $book, ChannelSubmission $submission): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'card' => ['required', 'string', 'in:'.implode(',', array_keys(ChannelSubmission::CARDS))],
            'external_id' => ['nullable', 'string', 'max:40'],
            'date' => ['required', 'date'],
            'state' => ['required', 'string', 'in:in_review,live'],
        ]);

        $submission->record($book, $data['card'], $data['external_id'] ?? null, $data['date'], $data['state'], $request->user());

        return response()->json(['message' => 'Recorded.'] + $this->payload($book->fresh(), $request));
    }

    /** Google Play bulk upload for this book: the book list and files. */
    public function googlePlay(Book $book, GooglePlayBulk $bulk): BinaryFileResponse
    {
        $this->authorize('update', $book);

        return response()->download($bulk->zip(collect([$book])), 'google-play-'.(Str::slug($book->title) ?: 'book').'.zip', ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }

    /**
     * "Prepare for publishing" for several books from the shelf: step 1 for
     * each, stale files regenerated, and the combined Google Play and KDP
     * links. Every book must be one the author may update.
     */
    public function prepareMany(Request $request, BulkPrepare $bulk): JsonResponse
    {
        $books = $this->booksFrom($request->validate([
            'books' => ['required', 'array', 'min:1', 'max:50'],
            'books.*' => ['integer'],
        ])['books']);

        $rows = $bulk->run($books, $request->user());
        $query = ['books' => $books->pluck('id')->all()];

        return response()->json([
            'rows' => $rows,
            'google_play' => route('publish.google-play.many', $query),
            'kdp_checklist' => route('publish.checklist', $query),
        ]);
    }

    /** One Google Play book list and zip for several books. */
    public function googlePlayMany(Request $request, GooglePlayBulk $bulk): BinaryFileResponse
    {
        $books = $this->booksFrom((array) $request->query('books', []));

        return response()->download($bulk->zip($books), 'google-play-bulk-'.now()->format('Y-m-d').'.zip', ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }

    /** The KDP guided page, printable, for one book or several. */
    public function checklist(Request $request): Response
    {
        $books = $this->booksFrom((array) $request->query('books', []));

        return Inertia::render('Publish/KdpChecklist', $this->kdp->checklist($books, route('books.index')));
    }

    /**
     * Books by id, in the order given, each one the person may update.
     *
     * @param  list<mixed>  $ids
     * @return Collection<int, Book>
     */
    private function booksFrom(array $ids): Collection
    {
        $ids = array_values(array_unique(array_slice(array_map('intval', $ids), 0, 200)));
        abort_if($ids === [], 404);

        $books = Book::with('user', 'product')->whereIn('id', $ids)->get()
            ->sortBy(fn (Book $b) => array_search($b->id, $ids, true))->values();

        abort_if($books->count() !== count($ids), 404);

        foreach ($books as $book) {
            $this->authorize('update', $book);
        }

        return $books;
    }

    /** @return array<string, mixed> */
    private function payload(Book $book, Request $request): array
    {
        $chosen = PublishPlan::chosen($book);

        return [
            'book' => [
                'id' => $book->id,
                'title' => $book->title,
                'house' => $book->user?->isHouse() ?? false,
                'language' => $book->language,
            ],
            'check' => $this->check->run($book),
            'cards' => $this->plan->cards($book, $request->user()),
            'kdp' => collect(KdpGuide::FORMATS)
                ->filter(fn ($f) => in_array($f === 'ebook' ? 'kdp_ebook' : 'kdp_paperback', $chosen, true))
                ->mapWithKeys(fn ($f) => [$f => $this->kdp->for($book, $f)])
                ->all(),
            'lulu' => $this->lulu->status($book),
            'store' => ($product = $book->product()->first()) ? [
                'state' => $product->isTakenDown() ? 'taken_down' : ($product->isPublished() ? 'live' : 'draft'),
                'url' => $product->isPublished() ? route('store.product', $product->slug) : null,
                'price_cents' => $product->price_cents,
                'currency' => $product->currency,
                'paperback_price_cents' => $product->paperback_price_cents,
            ] : null,
            'google' => in_array('google_play', $chosen, true) ? app(GooglePlayBulk::class)->plan(collect([$book]))['skipped'] : [],
            'can' => [
                'update' => $request->user()->can('update', $book),
                'manage' => $request->user()->can('manage', $book),
            ],
        ];
    }
}
