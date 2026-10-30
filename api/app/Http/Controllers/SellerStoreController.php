<?php

namespace App\Http\Controllers;

use App\Commerce\Bundles;
use App\Commerce\Pricing;
use App\Growth\Discounts;
use App\Models\Bundle;
use App\Models\DiscountCode;
use App\Models\Product;
use App\Models\User;
use App\Platform\Features;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * An author's own storefront tools on the Sales page: discount codes for
 * their books, and bundles of their books (ADR — per-format pricing, sales,
 * author codes, bundles and pre-orders).
 *
 * Tenant-scoped by construction: every query starts from the signed-in
 * seller, and a code or bundle that is somebody else's answers 404 — the
 * same as one that does not exist. The owner's site-wide codes stay on the
 * console (Admin\Business\GrowthController).
 */
class SellerStoreController extends Controller
{
    public function discounts(Request $request, Discounts $discounts): JsonResponse
    {
        $seller = $this->seller($request);
        $zone = $seller->reportTimezone();

        return response()->json([
            'codes' => DiscountCode::where('seller_id', $seller->id)->latest('id')->get()
                ->map(fn (DiscountCode $d) => [
                    'id' => $d->id,
                    'code' => $d->code,
                    'description' => $d->description,
                    'kind' => $d->kind,
                    'label' => $d->label(),
                    'percent_off' => $d->percent_off,
                    'amount_off_cents' => $d->amount_off_cents,
                    'currency' => $d->currency,
                    'product_ids' => $d->product_ids,
                    'bundle_ids' => $d->bundle_ids,
                    'max_redemptions' => $d->max_redemptions,
                    'used' => $discounts->used($d),
                    'expires_on' => $d->expires_at?->setTimezone($zone)->toDateString(),
                    'expired' => $d->expires_at?->isPast() ?? false,
                    'active' => $d->active,
                ])->all(),
        ] + $this->choices($request));
    }

    public function storeDiscount(Request $request, Discounts $discounts): JsonResponse
    {
        $seller = $this->seller($request);

        $data = $request->validate([
            'code' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'description' => ['nullable', 'string', 'max:255'],
            'kind' => ['required', 'in:percent,amount'],
            'percent_off' => ['required_if:kind,percent', 'nullable', 'integer', 'min:1', 'max:100'],
            'amount_off' => ['required_if:kind,amount', 'nullable', 'numeric', 'min:0.01', 'max:9999.99'],
            'currency' => ['required_if:kind,amount', 'nullable', 'in:'.implode(',', config('commerce.currencies'))],
            'product_ids' => ['nullable', 'array', 'max:200'],
            'product_ids.*' => ['integer'],
            'bundle_ids' => ['nullable', 'array', 'max:200'],
            'bundle_ids.*' => ['integer'],
            'max_redemptions' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $discounts->createForSeller($data + ['expires_at' => $data['expires_on'] ?? null], $seller);

        return $this->discounts($request, $discounts);
    }

    public function updateDiscount(Request $request, DiscountCode $discount, Discounts $discounts): JsonResponse
    {
        $seller = $this->seller($request);
        abort_unless($discount->seller_id === $seller->id, 404);

        $data = $request->validate([
            'active' => ['required', 'boolean'],
            'description' => ['nullable', 'string', 'max:255'],
            'product_ids' => ['nullable', 'array', 'max:200'],
            'product_ids.*' => ['integer'],
            'bundle_ids' => ['nullable', 'array', 'max:200'],
            'bundle_ids.*' => ['integer'],
            'max_redemptions' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $discounts->updateForSeller($discount, $data + ['expires_at' => $data['expires_on'] ?? null], $seller);

        return $this->discounts($request, $discounts);
    }

    public function bundles(Request $request): JsonResponse
    {
        $seller = $this->seller($request);

        return response()->json([
            'bundles' => Bundle::where('user_id', $seller->id)->with('products.book')->latest('id')->get()
                ->map(fn (Bundle $b) => [
                    'id' => $b->id,
                    'title' => $b->title,
                    'description' => $b->description,
                    'price' => number_format($b->price_cents / 100, 2, '.', ''),
                    'price_cents' => $b->price_cents,
                    'currency' => $b->currency,
                    'product_ids' => $b->products->pluck('id')->all(),
                    'separately_cents' => Bundles::separately($b),
                    'published' => $b->isPublished(),
                    // On sale only while every book in it is.
                    'on_sale' => Bundles::sellable($b),
                    'url' => Bundles::sellable($b) ? route('store.bundle', $b->slug) : null,
                ])->all(),
        ] + $this->choices($request));
    }

    public function storeBundle(Request $request, Bundles $bundles): JsonResponse
    {
        $bundles->save($this->seller($request), $this->bundleData($request));

        return $this->bundles($request);
    }

    public function updateBundle(Request $request, Bundle $bundle, Bundles $bundles): JsonResponse
    {
        $seller = $this->seller($request);
        abort_unless($bundle->user_id === $seller->id, 404);

        $bundles->save($seller, $this->bundleData($request), $bundle);

        return $this->bundles($request);
    }

    /** Deleting a bundle never touches a sale: order items keep its title. */
    public function destroyBundle(Request $request, Bundle $bundle): JsonResponse
    {
        abort_unless($bundle->user_id === $this->seller($request)->id, 404);

        $bundle->delete();

        return $this->bundles($request);
    }

    // ── Internals ───────────────────────────────────────────────────────

    /** The signed-in seller, while their storefront is on. */
    private function seller(Request $request): User
    {
        abort_unless(Features::enabled('storefront', $request->user()), 404);

        return $request->user();
    }

    /** @return array<string, mixed> */
    private function bundleData(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'currency' => ['required', 'in:'.implode(',', config('commerce.currencies'))],
            'product_ids' => ['required', 'array', 'max:'.Bundles::MAX_PRODUCTS],
            'product_ids.*' => ['integer'],
            'published' => ['required', 'boolean'],
        ]);
    }

    /**
     * The seller's own products and bundles, to choose from.
     *
     * @return array<string, mixed>
     */
    private function choices(Request $request): array
    {
        $seller = $request->user();

        return [
            'products' => Product::where('user_id', $seller->id)->with('book:id,title')->get()
                ->filter(fn (Product $p) => $p->book)
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'title' => $p->displayTitle(),
                    'currency' => $p->currency,
                    'price_cents' => Pricing::listPrice($p, Pricing::ALL),
                    'published' => $p->isPublished(),
                ])->values()->all(),
            'bundle_choices' => Bundle::where('user_id', $seller->id)->get(['id', 'title'])->all(),
            'currencies' => config('commerce.currencies'),
            'timezone' => $seller->reportTimezone(),
        ];
    }
}
