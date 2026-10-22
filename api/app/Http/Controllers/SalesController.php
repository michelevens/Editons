<?php

namespace App\Http\Controllers;

use App\Commerce\Commerce;
use App\Commerce\SalesReport;
use App\Models\Book;
use App\Models\BookChannel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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
 * published rate for each one, not a guess.
 *
 * Direct sales are different: the author's own storefront takes real orders
 * (App\Commerce), so those figures are real — gross, estimated payment fees,
 * net, royalty, refunds — and only ever the owner's own. A co-editor who can
 * view the book sees the store terms but not the money.
 */
class SalesController extends Controller
{
    /**
     * What each store keeps, and what the author earns per copy.
     *
     * Published rates. eBook royalty rates depend on price banding at the
     * major stores, so the band is named rather than a single rate implied.
     */
    private const TERMS = [
        'amazon_kdp' => [
            'rate' => 0.70,
            'note' => '70% between $2.99 and $9.99, 35% outside that band, less delivery costs.',
        ],
        'apple_books' => ['rate' => 0.70, 'note' => '70% at any price.'],
        'kobo' => ['rate' => 0.70, 'note' => '70% at $2.99 and above, 45% below.'],
        'google_play' => ['rate' => 0.70, 'note' => '70% at any price.'],
        'ingram_print' => [
            'rate' => 0.45,
            'note' => '45% of list, less the print cost of each copy.',
        ],
    ];

    public function show(Request $request, Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        // The owner's own sales of this book, and nobody else's eyes.
        $direct = Commerce::enabled() && $request->user()->can('manage', $book)
            ? ['product' => $book->product ? [
                'published' => $book->product->isPublished(),
                'url' => $book->product->isPublished() ? route('store.product', $book->product->slug) : null,
            ] : null] + SalesReport::for($book->user, $book)
            : null;

        $channels = $book->channels()->get();
        $price = (float) ($book->list_price ?? 0);

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
                ->map(function (BookChannel $channel) use ($price) {
                    $terms = self::TERMS[$channel->channel] ?? ['rate' => 0.70, 'note' => ''];

                    return [
                        'channel' => $channel->channel,
                        'label' => $channel->label(),
                        'format' => $channel->format,
                        'status' => $channel->status,
                        'went_live_at' => $channel->went_live_at?->toFormattedDateString(),
                        'rate' => $terms['rate'],
                        'note' => $terms['note'],
                        // Per copy at the current list price. Arithmetic on
                        // a published rate, not a projection of sales.
                        'per_copy' => $price > 0
                            ? round($price * $terms['rate'], 2)
                            : null,
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
                    ? 'Your storefront is selling this book; its sales are real orders, shown below. '
                        .'No other store has the book on sale yet.'
                    : ($live->isEmpty()
                    ? 'No store has this book on sale yet. Sales and royalties appear here once one does.'
                    : 'Sales reporting is not connected yet, so the figures below are not live. '
                        .'Each store reports on its own schedule, usually monthly.'),
            ],
        ]);
    }

    /**
     * Every direct sale this author has made, across their books.
     *
     * Scoped to the signed-in seller: there is no parameter to point it at
     * anybody else.
     */
    public function overview(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Sales', [
            'enabled' => Commerce::enabled(),
            'report' => SalesReport::for($user, null, 30),
            'products' => $user->products()
                ->with('book:id,title')
                ->get()
                ->map(fn ($product) => [
                    'title' => $product->displayTitle(),
                    'book_id' => $product->book_id,
                    'published' => $product->isPublished(),
                    'url' => $product->isPublished() ? route('store.product', $product->slug) : null,
                    'price_cents' => $product->price_cents,
                    'currency' => $product->currency,
                ])
                ->all(),
        ]);
    }
}
