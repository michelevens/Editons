<?php

namespace App\Http\Controllers;

use App\Commerce\Commerce;
use App\Commerce\Partners\Commissions;
use App\Commerce\Partners\Program;
use App\Commerce\Revenue;
use App\Models\Book;
use App\Models\BookExport;
use App\Models\Product;
use App\Platform\Features;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Selling a book from the author's own storefront: the product behind it.
 *
 * `manage`, not `update`: putting a price on a book and taking money for it
 * is the owner's decision, not a co-editor's. The product's owner is the
 * book's owner, set here, never read from the request.
 *
 * A product can be saved as a draft at any time. Publishing it — making the
 * storefront page live and the Buy button work — needs a price Stripe will
 * accept and a ready export of every format it promises, because a buyer
 * who pays for an EPUB must be able to download one.
 */
class ProductController extends Controller
{
    /** Stripe's minimum charge in each supported currency, in minor units. */
    private const MINIMUM_CENTS = ['usd' => 50, 'eur' => 50, 'cad' => 50, 'gbp' => 30];

    public function show(Request $request, Book $book): JsonResponse
    {
        $this->authorize('manage', $book);

        return response()->json($this->payload($book, $request));
    }

    public function update(Request $request, Book $book): JsonResponse
    {
        $this->authorize('manage', $book);
        // Commerce::enabled() and the storefront flag (App\Platform\Features).
        abort_unless(Features::enabled('storefront', $request->user()), 404);

        $data = $request->validate([
            'price' => ['required', 'numeric', 'min:0', 'max:9999.99'],
            'currency' => ['required', 'string', 'in:'.implode(',', config('commerce.currencies'))],
            'formats' => ['required', 'array', 'min:1'],
            'formats.*' => ['string', 'distinct', 'in:'.implode(',', config('commerce.formats'))],
            'license' => ['required', 'string', 'in:'.implode(',', array_keys(config('commerce.licenses')))],
            'title' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'published' => ['required', 'boolean'],
            // Écrivain Partners: whether partners may sell it, and for what
            // share of the net. Out of the seller's own royalty.
            'partner_enabled' => ['sometimes', 'boolean'],
            'partner_rate' => ['nullable', 'integer'],
        ]);

        $partners = $this->partnerSettings($request, $data, $book->product);

        $cents = (int) round(((float) $data['price']) * 100);

        if ($data['published']) {
            $this->assertSellable($book, $cents, $data['currency'], $data['formats']);
        }

        $product = $book->product ?? new Product;
        $wasPublished = $product->exists && $product->published_at !== null;

        $product->fill([
            'title' => $data['title'] ?? null,
            // Stored as typed, shown as text: the storefront never renders
            // it as HTML. Tags are stripped on the way in as well, so what
            // the author sees in the form is what a buyer will see.
            'description' => isset($data['description']) ? strip_tags($data['description']) : null,
            'price_cents' => $cents,
            'currency' => $data['currency'],
            'formats' => array_values($data['formats']),
            'license' => $data['license'],
            'published_at' => $data['published'] ? ($wasPublished ? $product->published_at : now()) : null,
        ]);
        $product->forceFill($partners);

        if (! $product->exists) {
            $product->user_id = $book->user_id;
            $product->book_id = $book->id;
            $product->setRelation('book', $book);
        }

        $product->save();

        return response()->json($this->payload($book->fresh(), $request));
    }

    /**
     * The partner switch and rate, held to the program's range. With the
     * program off the stored values are kept as they are.
     *
     * @return array{partner_enabled?: bool, partner_rate_bps?: ?int}
     */
    private function partnerSettings(Request $request, array $data, ?Product $product): array
    {
        if (! Program::enabled($request->user()) || ! array_key_exists('partner_enabled', $data)) {
            return [];
        }

        $rates = app(Program::class)->rates();
        $rate = $data['partner_rate'] ?? ($product?->partner_rate_bps ? intdiv($product->partner_rate_bps, 100) : $rates['default']);

        if ($rate < $rates['min'] || $rate > $rates['max']) {
            throw ValidationException::withMessages(['partner_rate' => "Choose a rate from {$rates['min']} % to {$rates['max']} %."]);
        }

        return ['partner_enabled' => (bool) $data['partner_enabled'], 'partner_rate_bps' => $rate * 100];
    }

    /** @param  list<string>  $formats */
    private function assertSellable(Book $book, int $cents, string $currency, array $formats): void
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

        $missing = array_values(array_filter($formats, fn (string $format) => ! $this->readyExport($book, $format)));

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

    /** @return array{price_cents: int, without: int, with: int, commission: int, fee: int} */
    public static function partnerPreview(int $priceCents, int $rateBps): array
    {
        $fee = Revenue::fee($priceCents);
        $split = Commissions::split($priceCents, $fee, $rateBps);

        return [
            'price_cents' => $priceCents,
            'fee' => $fee,
            'without' => Revenue::royalty($priceCents, $fee),
            'with' => $split['royalty'],
            'commission' => $split['commission'],
        ];
    }

    private function readyExport(Book $book, string $format): ?BookExport
    {
        return $book->exports()->where('format', $format)->where('status', 'ready')->first();
    }

    /** @return array<string, mixed> */
    private function payload(Book $book, Request $request): array
    {
        $product = $book->product;

        return [
            'enabled' => Features::enabled('storefront', $request->user()),
            'gateway' => Features::enabled('storefront', $request->user()) ? config('commerce.gateway') : null,
            'product' => $product ? [
                'id' => $product->id,
                'slug' => $product->slug,
                'title' => $product->title,
                'description' => $product->description,
                'price' => number_format($product->price_cents / 100, 2, '.', ''),
                'price_cents' => $product->price_cents,
                'currency' => $product->currency,
                'formats' => $product->formats,
                'license' => $product->license,
                'published' => $product->isPublished(),
                'published_at' => $product->published_at?->toIso8601String(),
                // Taken down by Ecrivain (App\Platform\Trust\Moderation):
                // the author sees why, and cannot put it back on sale.
                'taken_down' => $product->isTakenDown() ? [
                    'at' => $product->taken_down_at?->toIso8601String(),
                    'reason' => $product->taken_down_reason,
                ] : null,
                'url' => $product->isPublished() ? route('store.product', $product->slug) : null,
                'partner_enabled' => (bool) $product->partner_enabled,
                'partner_rate' => $product->partner_rate_bps ? intdiv($product->partner_rate_bps, 100) : null,
            ] : null,
            // Écrivain Partners: the switch, the allowed range, and what a
            // sale pays with and without a partner (Commissions::split, the
            // same arithmetic the order will use).
            'partners' => Program::enabled($request->user()) ? [
                'rates' => app(Program::class)->rates(),
                'fee' => config('commerce.stripe_fee'),
                'platform_rate' => (float) config('commerce.platform_rate'),
                'preview' => $product ? self::partnerPreview($product->price_cents, $product->partner_rate_bps ?: app(Program::class)->rates()['default'] * 100) : null,
            ] : null,
            'defaults' => [
                'title' => $book->title,
                'description' => trim(strip_tags((string) $book->description)),
                'price' => $book->list_price ? number_format((float) $book->list_price, 2, '.', '') : '',
                // Whether the storefront will print a name at all: only the
                // book's own author_name, never the account's.
                'author_name' => trim((string) $book->author_name) ?: null,
            ],
            'store_url' => Commerce::enabled() && $product?->isPublished()
                ? route('store.author', $book->user->storeHandle())
                : null,
            'exports' => collect(config('commerce.formats'))
                ->mapWithKeys(function (string $format) use ($book) {
                    $export = $this->readyExport($book, $format);

                    return [$format => [
                        'label' => BookExport::FORMATS[$format][0] ?? strtoupper($format),
                        'ready' => $export !== null,
                        'created_at' => $export?->created_at?->toFormattedDateString(),
                    ]];
                })
                ->all(),
            'options' => [
                'currencies' => config('commerce.currencies'),
                'licenses' => collect(config('commerce.licenses'))
                    ->map(fn (array $license, string $key) => ['key' => $key, 'label' => $license['label'], 'terms' => $license['terms']])
                    ->values()
                    ->all(),
            ],
        ];
    }
}
