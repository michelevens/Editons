<?php

namespace App\Commerce;

use App\Models\Bundle;
use App\Models\Product;
use App\Models\User;
use App\Platform\Features;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bundles: several of one seller's products at one price.
 *
 * The royalty rule (documented in docs/PRICING.md and the ADR): a bundle
 * sale becomes one order item per book, and the price actually paid —
 * after any discount code — is split across the books IN PROPORTION TO
 * THEIR LIST PRICES (each product's whole-product price, sale prices
 * ignored), with Revenue::allocate's rounding: whole cents, the last book
 * taking the remainder, so the parts always add up to what the buyer paid.
 * The card fee is then split the same way (as for any multi-item order),
 * and each item's royalty is worked out from its own share. Every book's
 * statement, sales report and licence therefore stand on their own.
 *
 * Only the seller's own products, in the bundle's currency. A bundle is on
 * sale only while every book in it is (a book taken off sale takes the
 * bundle with it), so nobody pays for a book they cannot download.
 */
final class Bundles
{
    public const MAX_PRODUCTS = 30;

    /**
     * Create or update a seller's bundle from the validated form.
     *
     * @param  array{title: string, description?: ?string, price: mixed, currency: string, product_ids: list<int>, published: bool}  $data
     */
    public function save(User $seller, array $data, ?Bundle $bundle = null): Bundle
    {
        $ids = array_values(array_unique(array_map('intval', $data['product_ids'])));
        $products = Product::where('user_id', $seller->id)->whereKey($ids)->with('book')->get()->keyBy('id');
        $cents = (int) round(((float) $data['price']) * 100);
        $errors = [];

        if ($products->count() !== count($ids)) {
            $errors['product_ids'] = __('Choose among your own books.');
        } elseif (count($ids) < 2) {
            $errors['product_ids'] = __('A bundle needs at least two books.');
        } elseif ($products->contains(fn (Product $p) => $p->currency !== $data['currency'])) {
            $errors['product_ids'] = __('Every book in a bundle must sell in the bundle’s currency.');
        }

        if ($cents < (Listing::MINIMUM_CENTS[$data['currency']] ?? 50)) {
            $errors['price'] = __('Card payments need a price of at least :amount.', [
                'amount' => number_format((Listing::MINIMUM_CENTS[$data['currency']] ?? 50) / 100, 2).' '.strtoupper($data['currency']),
            ]);
        }

        if ($data['published'] && $errors === [] && $products->contains(fn (Product $p) => ! $p->isPublished() || ! $p->book)) {
            $errors['published'] = __('Put every book in the bundle on sale first.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($seller, $data, $bundle, $ids, $cents) {
            $bundle ??= new Bundle;
            $wasPublished = $bundle->exists && $bundle->published_at !== null;

            $bundle->fill([
                'title' => $data['title'],
                'description' => isset($data['description']) ? strip_tags((string) $data['description']) : null,
                'price_cents' => $cents,
                'currency' => $data['currency'],
                'published_at' => $data['published'] ? ($wasPublished ? $bundle->published_at : now()) : null,
            ]);
            $bundle->user_id ??= $seller->id;
            $bundle->save();

            $bundle->products()->sync(collect($ids)->mapWithKeys(fn (int $id, int $i) => [$id => ['position' => $i]])->all());

            return $bundle->fresh('products.book');
        });
    }

    /** Whether a buyer can buy it now. */
    public static function sellable(Bundle $bundle): bool
    {
        $bundle->loadMissing('products.book', 'user');

        return Commerce::enabled()
            && $bundle->isPublished()
            && $bundle->user && Features::enabled('storefront', $bundle->user)
            && $bundle->products->count() >= 2
            && $bundle->products->every(fn (Product $p) => $p->isPublished() && $p->book && $p->currency === $bundle->currency);
    }

    /**
     * $paidCents split across the bundle's products by list price.
     *
     * @return list<array{product: Product, cents: int, list_cents: int}>
     */
    public static function split(Bundle $bundle, int $paidCents): array
    {
        $products = $bundle->products->values();
        $lists = $products->map(fn (Product $p) => Pricing::listPrice($p, Pricing::ALL))->all();
        $parts = Revenue::allocate($paidCents, $lists);

        return $products->map(fn (Product $p, int $i) => [
            'product' => $p,
            'cents' => $parts[$i],
            'list_cents' => $lists[$i],
        ])->all();
    }

    /** What the books cost bought one by one now (each one's cheapest way). */
    public static function separately(Bundle $bundle): int
    {
        return (int) $bundle->products->sum(fn (Product $p) => Pricing::quote($p, Pricing::ALL)['price_cents']);
    }
}
