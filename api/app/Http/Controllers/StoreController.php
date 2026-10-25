<?php

namespace App\Http\Controllers;

use App\Commerce\Checkout;
use App\Commerce\Commerce;
use App\Commerce\Downloads;
use App\Commerce\Fulfilment;
use App\Commerce\Partners\Attribution;
use App\Commerce\Partners\PartnerPages;
use App\Commerce\Print\LuluPrinting;
use App\Growth\Discounts;
use App\Jobs\GenerateExport;
use App\Models\BookExport;
use App\Models\License;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Platform\Features;
use App\Publishing\CoverRenderer;
use App\Publishing\Exporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The public storefront: book pages, author pages, checkout and downloads.
 *
 * No sign-in to look or to buy. Everything a visitor sees is the book's
 * published face — title, description as plain text, price, formats,
 * license — and never the account behind it: the name printed is the book's
 * own author_name, and a book published without one shows no name at all
 * and is not listed on any named author's page.
 *
 * Every route answers 404 while the feature is off (App\Commerce\Commerce).
 */
class StoreController extends Controller
{
    public function __construct(private readonly Exporter $exporter) {}

    public function product(Request $request, Product $product, Attribution $attribution): Response
    {
        $product = $this->published($product);

        // ?partner=CODE on any book link: the partner gets the credit.
        $partner = PartnerPages::fromQuery($request);

        if ($partner) {
            $attribution->click($request, $partner, 'param', $product);
        }
        $book = $product->book;

        $series = null;

        if ($book->series_name) {
            $series = [
                'name' => $book->series_name,
                'number' => $book->series_number,
                'others' => Product::published()
                    ->where('user_id', $product->user_id)
                    ->whereKeyNot($product->id)
                    ->whereHas('book', fn ($q) => $q->where('series_name', $book->series_name))
                    ->with('book')
                    ->get()
                    ->sortBy(fn (Product $p) => $p->book->series_number ?? PHP_INT_MAX)
                    ->map(fn (Product $p) => $this->card($p))
                    ->values()
                    ->all(),
            ];
        }

        return Inertia::render('Store/Product', [
            'product' => $this->card($product) + [
                'subtitle' => $book->subtitle,
                'description' => $product->displayDescription(),
                'language' => $book->language,
                'license' => $product->licenseTerms(),
                'checkout_url' => route('store.checkout', $product->slug),
                // A paperback printed and posted by Lulu, when it is on sale.
                'paperback' => app(LuluPrinting::class)->sellable($product) ? [
                    'price_cents' => $product->paperback_price_cents,
                    'shipping_cents' => (int) config('commerce.paperback.shipping_cents'),
                    'countries' => config('commerce.paperback.countries'),
                ] : null,
                // "Report this book": to the console's moderation queue.
                'report_url' => route('store.report', $product->slug),
                // Only a named book links to its author's shelf: linking an
                // anonymous one would put a name to it.
                'author_url' => $product->authorName()
                    ? route('store.author', $product->user->storeHandle())
                    : null,
            ],
            'series' => $series,
            // "Recommended by …" with the partner's disclosure, when a
            // partner's link brought this reader and could earn on this book.
            'partner' => PartnerPages::recommendation($request, $product, $partner),
        ]);
    }

    /**
     * An author's shelf: their published books that carry an author name.
     *
     * Books sold without a name are left off, so the shelf never ties them
     * to a name. The heading is the name(s) the books themselves carry.
     */
    public function author(string $handle): Response
    {
        abort_unless(Commerce::enabled(), 404);

        $seller = User::where('store_handle', $handle)->firstOrFail();

        $products = Product::published()
            ->where('user_id', $seller->id)
            ->whereHas('book', fn ($q) => $q->whereNotNull('author_name')->where('author_name', '!=', ''))
            ->with('book')
            ->latest('published_at')
            ->get();

        abort_if($products->isEmpty(), 404);

        return Inertia::render('Store/Author', [
            'names' => $products->map(fn (Product $p) => $p->authorName())->filter()->unique()->values()->all(),
            'products' => $products->map(fn (Product $p) => $this->card($p))->values()->all(),
            'report_url' => route('store.author.report', $handle),
        ]);
    }

    /** The rendered front cover, for a book on sale only. */
    public function cover(Product $product, CoverRenderer $covers): StreamedResponse
    {
        $product = $this->published($product);

        return Storage::disk('files')->response($covers->ensure($product->book), null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * Start a purchase: a pending order and a Checkout Session, then off to
     * the hosted payment page. The price is the product's, on the server.
     */
    public function checkout(Request $request, Product $product, Checkout $checkout, Attribution $attribution): HttpResponse
    {
        $product = $this->published($product);

        $data = $request->validate([
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'code' => ['nullable', 'string', 'max:40'],
            'edition' => ['nullable', 'string', 'in:digital,paperback'],
        ]);

        $paperback = ($data['edition'] ?? null) === 'paperback';

        if ($paperback && ! app(LuluPrinting::class)->sellable($product)) {
            return back()->withErrors(['checkout' => 'The paperback is not available right now.']);
        }

        if ($paperback && filled($data['code'] ?? null)) {
            return back()->withErrors(['code' => 'Codes apply to the ebook only.']);
        }

        // Checked before anything is created; a code that does not apply is
        // a message under the field, not a failed checkout.
        $discount = filled($data['code'] ?? null)
            ? app(Discounts::class)->quote($data['code'], 'storefront', $product->price_cents, $product->currency, $request->user(), $data['email'] ?? $request->user()?->email)
            : null;

        // Which partner sent this buyer, if any (a partner's code beats
        // their link), and whether a commission is due.
        $partner = $attribution->resolve($request, $product, $discount['discount'] ?? null, $request->user(), $data['email'] ?? null)
            + ['ip_hash' => Attribution::hash((string) $request->ip())];

        try {
            $session = $checkout->start($product, $request->user(), $data['email'] ?? null, $discount, $partner, $paperback);
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['checkout' => 'Payment could not be started. Please try again in a moment.']);
        }

        // So the success page can show this browser its own downloads, and
        // nobody else's.
        $request->session()->push('store.checkouts', $session->id);

        return Inertia::location($session->url);
    }

    /** Where Stripe sends the buyer after paying. */
    public function success(Request $request): Response
    {
        abort_unless(Commerce::enabled(), 404);

        $order = Order::where('stripe_session_id', (string) $request->query('session_id'))
            ->with('items.license', 'items.product')
            ->firstOrFail();

        $mine = in_array($order->stripe_session_id, (array) $request->session()->get('store.checkouts', []), true)
            || ($order->buyer_user_id && $order->buyer_user_id === $request->user()?->id);

        return Inertia::render('Store/Checkout', [
            'state' => 'success',
            'order' => $this->orderView($order, withDownloads: $mine),
        ]);
    }

    /** Where Stripe sends the buyer who backs out. Nothing was charged. */
    public function cancelled(Product $product): Response
    {
        $product = $this->published($product);

        return Inertia::render('Store/Checkout', [
            'state' => 'cancelled',
            'product' => $this->card($product),
        ]);
    }

    /**
     * The fake gateway's stand-in for Stripe's payment page.
     *
     * Local development and tests only: it completes the order through the
     * same Fulfilment code a signed webhook runs, without taking a payment.
     * Refused in production whatever the configuration says.
     */
    public function fakeCheckout(string $session): Response
    {
        abort_unless(Commerce::fakeCheckoutAllowed(), 404);

        $order = Order::where('stripe_session_id', $session)->with('items')->firstOrFail();

        return Inertia::render('Store/Checkout', [
            'state' => 'test',
            'order' => $this->orderView($order, withDownloads: false),
            'session' => $session,
            'email' => $order->buyer_email,
            'shipping' => $order->shipping_cents > 0 ? ['cents' => $order->shipping_cents, 'countries' => config('commerce.paperback.countries')] : null,
        ]);
    }

    public function fakePay(Request $request, string $session, Fulfilment $fulfilment): RedirectResponse
    {
        abort_unless(Commerce::fakeCheckoutAllowed(), 404);

        $order = Order::where('stripe_session_id', $session)->firstOrFail();

        // A paperback asks for the address and phone Stripe would collect.
        $posted = $order->shipping_cents > 0;

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => [$posted ? 'required' : 'nullable', 'string', 'max:120'],
            'line1' => [$posted ? 'required' : 'nullable', 'string', 'max:200'],
            'line2' => ['nullable', 'string', 'max:200'],
            'city' => [$posted ? 'required' : 'nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:40'],
            'postal_code' => [$posted ? 'required' : 'nullable', 'string', 'max:20'],
            'country' => [$posted ? 'required' : 'nullable', 'string', 'size:2'],
            'phone' => [$posted ? 'required' : 'nullable', 'string', 'max:40'],
        ]);

        // The shape of a Stripe Checkout Session, as the webhook carries it.
        $fulfilment->checkoutCompleted([
            'id' => $session,
            'payment_status' => 'paid',
            'amount_total' => $order->total_cents,
            'currency' => $order->currency,
            'payment_intent' => 'pi_fake_'.Str::random(16),
            'customer_details' => ['email' => $data['email'], 'name' => $data['name'] ?? null, 'phone' => $data['phone'] ?? null],
            'shipping_details' => $posted ? ['name' => $data['name'], 'address' => [
                'line1' => $data['line1'], 'line2' => $data['line2'] ?? null, 'city' => $data['city'],
                'state' => $data['state'] ?? null, 'postal_code' => $data['postal_code'], 'country' => strtoupper($data['country']),
            ]] : null,
            'metadata' => ['order_id' => (string) $order->id],
        ], paymentMethod: 'Test payment (no card charged)');

        return redirect()->to(route('store.checkout.success').'?session_id='.urlencode($session));
    }

    /**
     * One purchased file.
     *
     * The route is signed and expiring (`signed:relative`); the license is
     * checked here as well, so a refund stops links already in an inbox.
     * The file is the latest ready export of that format.
     */
    public function download(Request $request, string $key, string $format): HttpResponse
    {
        $license = License::where('key', $key)->with('item.order', 'item.book')->first();

        if (! $license || ! in_array($format, $license->item->formats ?? [], true)) {
            abort(404);
        }

        abort_unless($license->isActive() && in_array($license->item->order->status, Order::COUNTED, true), 403, 'This license is no longer active.');

        $book = $license->item->book;
        abort_if(! $book || $book->trashed(), 410, 'This book is no longer available.');

        $export = $book->exports()->where('format', $format)->where('status', 'ready')->first();

        if (! $export || ! $export->path || ! Storage::disk('files')->exists($export->path)) {
            $this->rebuild($book, $format, $license->item);

            return response()->view('store.preparing', [
                'title' => $license->item->title,
                'format' => BookExport::FORMATS[$format][0] ?? strtoupper($format),
            ], 503)->header('Retry-After', '300');
        }

        $license->forceFill([
            'download_count' => $license->download_count + 1,
            'last_downloaded_at' => now(),
        ])->save();

        $name = (Str::slug($license->item->title) ?: 'book').'.'.$export->extension();

        return Storage::disk('files')->download($export->path, $name, [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** A signed-in buyer's purchases, with fresh links. */
    public function purchases(Request $request): Response
    {
        $orders = Order::where('buyer_user_id', $request->user()->id)
            ->whereNotNull('paid_at')
            ->with('items.license', 'items.product')
            ->latest('paid_at')
            ->get();

        return Inertia::render('Store/Purchases', [
            'orders' => $orders->map(fn (Order $order) => $this->orderView($order, withDownloads: true))->all(),
        ]);
    }

    // ── Internals ───────────────────────────────────────────────────────

    private function published(Product $product): Product
    {
        abort_unless(Commerce::enabled() && $product->isPublished(), 404);

        $product->loadMissing('book', 'user');

        // The seller's storefront switched off by the console: their store
        // pages go with it (App\Platform\Features).
        abort_unless(Features::enabled('storefront', $product->user), 404);

        abort_if(! $product->book, 404);

        return $product;
    }

    /** @return array<string, mixed> */
    private function card(Product $product): array
    {
        return [
            'slug' => $product->slug,
            'url' => route('store.product', $product->slug),
            'title' => $product->displayTitle(),
            // The book's author_name or nothing. See Product::authorName.
            'author' => $product->authorName(),
            'series_name' => $product->book->series_name,
            'series_number' => $product->book->series_number,
            'price_cents' => $product->price_cents,
            'currency' => $product->currency,
            'formats' => array_map(
                fn (string $f) => BookExport::FORMATS[$f][0] ?? strtoupper($f),
                $product->formats,
            ),
            'cover_url' => route('store.cover', $product->slug),
        ];
    }

    /** @return array<string, mixed> */
    private function orderView(Order $order, bool $withDownloads): array
    {
        $expires = Downloads::expiry();
        $active = in_array($order->status, Order::COUNTED, true);

        return [
            'number' => $order->number,
            'status' => $order->status,
            'total_cents' => $order->total_cents,
            'shipping_cents' => $order->shipping_cents,
            'refunded_cents' => $order->refunded_cents,
            'currency' => $order->currency,
            'paid_at' => $order->paid_at?->toFormattedDateString(),
            // Shown to the buyer only.
            'email' => $withDownloads ? $order->buyer_email : null,
            'items' => $order->items->map(fn (OrderItem $item) => [
                'title' => $item->title,
                'price_cents' => $item->price_cents,
                'formats' => array_map(fn (string $f) => $f === 'paperback' ? 'Paperback' : (BookExport::FORMATS[$f][0] ?? strtoupper($f)), $item->formats),
                // A paperback: where its print job stands, never the address.
                'shipment' => $item->isPaperback() ? [
                    'status' => $item->printJob?->status,
                    'tracking_url' => $withDownloads ? ($item->printJob?->tracking['urls'][0] ?? null) : null,
                ] : null,
                'license_key' => $withDownloads ? $item->license?->key : null,
                'license_terms' => $withDownloads ? $item->license?->terms : null,
                'downloads' => $withDownloads && $active && $item->license?->isActive()
                    ? Downloads::links($item->license->setRelation('item', $item), $expires)
                    : [],
            ])->all(),
            'links_expire_at' => $withDownloads && $active ? $expires->toDayDateTimeString() : null,
        ];
    }

    /** Queue a rebuild of a missing export, unless one is already running. */
    private function rebuild($book, string $format, OrderItem $item): void
    {
        $running = $book->exports()
            ->where('format', $format)
            ->where('status', 'generating')
            ->where('created_at', '>', now()->subMinutes(30))
            ->exists();

        if ($running || ! in_array($format, GenerateExport::FORMATS, true)) {
            return;
        }

        try {
            GenerateExport::dispatch($this->exporter->start($book, $format, $item->seller_id)->id);
        } catch (Throwable $e) {
            Log::warning('Could not queue a rebuild for a purchased download.', ['format' => $format, 'error' => $e->getMessage()]);
        }
    }
}
