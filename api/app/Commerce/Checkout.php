<?php

namespace App\Commerce;

use App\Commerce\Gateway\CheckoutSession;
use App\Commerce\Gateway\PaymentGateway;
use App\Commerce\Print\LuluPrinting;
use App\Models\Bundle;
use App\Models\DiscountCode;
use App\Models\Order;
use App\Models\Partner;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Starting a purchase: a pending order, then a hosted Checkout Session.
 *
 * The order exists before the session so the session can carry its id; the
 * price comes from the product row on the server, never from the request.
 * A pending order is not a sale — only a signed webhook makes it one
 * (Fulfilment).
 *
 * The price is App\Commerce\Pricing::quote for the offer bought (the
 * whole product, one format, or the paperback) at this moment, sale
 * included: the order and the session are written from it.
 *
 * A discount code lowers the price before the order is written, so the
 * order, the session and the item (and so the author's royalty) all carry
 * the price actually paid.
 *
 * A partner's attribution (App\Commerce\Partners\Attribution::resolve) is
 * written on the order too, with the product's rate at this moment copied
 * onto the item: the commission is worked out from that when the order is
 * paid, never from whatever the rate is by then.
 */
final class Checkout
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * $discount is a quote from App\Growth\Discounts::quote, already checked.
     *
     * @param  array{discount: DiscountCode, discount_cents: int, total_cents: int}|null  $discount
     * @param  array{partner: ?Partner, source: ?string, refusal: ?string, rate_bps: ?int, ip_hash?: ?string}|null  $partner
     */
    public function start(Product $product, ?User $buyer = null, ?string $email = null, ?array $discount = null, ?array $partner = null, bool $paperback = false, ?string $offer = null): CheckoutSession
    {
        $product->loadMissing('book');

        $offer = $paperback ? Pricing::PAPERBACK : ($offer ?? Pricing::defaultOffer($product));

        if (! $paperback && ! Pricing::sells($product, $offer)) {
            throw new RuntimeException('This format is not on sale.');
        }

        $quote = Pricing::quote($product, $offer);

        // A paperback (printed by Lulu, App\Commerce\Print) has its own
        // price and a flat shipping charge; discount codes are for the
        // digital edition only.
        if ($paperback) {
            // A pre-ordered paperback would be printed at once: it waits
            // for the release like the book.
            if (! app(LuluPrinting::class)->sellable($product) || $product->isPreorder()) {
                throw new RuntimeException('This paperback is not on sale.');
            }

            $discount = null;
        }

        // What the buyer pays: the offer's price now, less a discount code
        // (quoted by the caller on that same price).
        $price = $discount['total_cents'] ?? $quote['price_cents'];
        $shipping = $paperback ? (int) config('commerce.paperback.shipping_cents') : 0;

        $order = DB::transaction(function () use ($product, $buyer, $email, $discount, $price, $partner, $shipping, $quote) {
            $order = new Order;
            $order->forceFill([
                'number' => Order::newNumber(),
                'status' => 'pending',
                'buyer_user_id' => $buyer?->id,
                'buyer_email' => $email ?? $buyer?->email,
                'currency' => $product->currency,
                'total_cents' => $price + $shipping,
                'shipping_cents' => $shipping,
                'discount_code_id' => $discount ? $discount['discount']->id : null,
                'discount_cents' => $discount['discount_cents'] ?? 0,
                'partner_id' => ($partner['partner'] ?? null)?->id,
                'partner_source' => isset($partner['partner']) ? $partner['source'] : null,
                'partner_refusal' => isset($partner['partner']) ? $partner['refusal'] : null,
                'partner_ip_hash' => isset($partner['partner']) ? ($partner['ip_hash'] ?? null) : null,
            ])->save();

            $order->items()->make()->forceFill([
                'product_id' => $product->id,
                'book_id' => $product->book_id,
                'seller_id' => $product->user_id,
                'title' => $product->displayTitle(),
                'formats' => $quote['formats'],
                'offer' => $quote['offer'],
                'list_price_cents' => $quote['list_cents'],
                'license_type' => $product->license,
                'price_cents' => $price,
                'partner_rate_bps' => isset($partner['partner']) && ! $partner['refusal'] ? $partner['rate_bps'] : null,
                // A pre-order: the licence is issued at release
                // (App\Jobs\ReleasePreorders), not when it is paid.
                'release_at' => $product->isPreorder() ? $product->release_at : null,
            ])->save();

            return $order;
        });

        try {
            $session = $this->createSession($order, $product, $paperback, $quote['formats']);
        } catch (Throwable $e) {
            // No session, no way to pay: close the order rather than leave
            // it pending forever.
            $order->forceFill(['status' => 'cancelled'])->save();

            throw $e;
        }

        $order->forceFill(['stripe_session_id' => $session->id])->save();

        return $session;
    }

    /**
     * Start a bundle purchase: one order item per book, the price paid split
     * by list price (App\Commerce\Bundles::split), each item carrying its
     * own book, seller, licence and — for a pre-ordered book — release.
     * No partner commission: partners recommend books, not bundles.
     *
     * @param  array{discount: DiscountCode, discount_cents: int, total_cents: int}|null  $discount
     */
    public function startBundle(Bundle $bundle, ?User $buyer = null, ?string $email = null, ?array $discount = null): CheckoutSession
    {
        if (! Bundles::sellable($bundle)) {
            throw new RuntimeException('This bundle is not on sale.');
        }

        $price = $discount['total_cents'] ?? $bundle->price_cents;

        $order = DB::transaction(function () use ($bundle, $buyer, $email, $discount, $price) {
            $order = new Order;
            $order->forceFill([
                'number' => Order::newNumber(),
                'status' => 'pending',
                'buyer_user_id' => $buyer?->id,
                'buyer_email' => $email ?? $buyer?->email,
                'currency' => $bundle->currency,
                'total_cents' => $price,
                'shipping_cents' => 0,
                'discount_code_id' => $discount ? $discount['discount']->id : null,
                'discount_cents' => $discount['discount_cents'] ?? 0,
            ])->save();

            foreach (Bundles::split($bundle, $price) as $share) {
                $product = $share['product'];

                $order->items()->make()->forceFill([
                    'product_id' => $product->id,
                    'book_id' => $product->book_id,
                    'seller_id' => $product->user_id,
                    'title' => $product->displayTitle(),
                    'formats' => array_values($product->formats),
                    'offer' => Pricing::ALL,
                    'list_price_cents' => $share['list_cents'],
                    'bundle_id' => $bundle->id,
                    'bundle_title' => $bundle->title,
                    'license_type' => $product->license,
                    'price_cents' => $share['cents'],
                    'release_at' => $product->isPreorder() ? $product->release_at : null,
                ])->save();
            }

            return $order;
        });

        try {
            $session = $this->gateway->createCheckoutSession([
                'order_id' => $order->id,
                'order_number' => $order->number,
                'currency' => $order->currency,
                'line_items' => [[
                    'name' => $bundle->title,
                    'description' => $bundle->products->count().' books · bundle'
                        .($order->discount_cents > 0 ? ' · discount applied' : ''),
                    'amount_cents' => $order->total_cents,
                    'quantity' => 1,
                ]],
                'shipping' => null,
                'customer_email' => $order->buyer_email,
                'success_url' => route('store.checkout.success').'?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => route('store.bundle.cancelled', ['bundle' => $bundle->slug]),
            ]);
        } catch (Throwable $e) {
            $order->forceFill(['status' => 'cancelled'])->save();

            throw $e;
        }

        $order->forceFill(['stripe_session_id' => $session->id])->save();

        return $session;
    }

    /** @param  list<string>  $formats */
    private function createSession(Order $order, Product $product, bool $paperback, array $formats): CheckoutSession
    {
        $formats = implode(' + ', array_map('strtoupper', $formats));

        return $this->gateway->createCheckoutSession([
            'order_id' => $order->id,
            'order_number' => $order->number,
            'currency' => $order->currency,
            'line_items' => [[
                'name' => $product->displayTitle(),
                // Formats and license, so the buyer sees what they are paying
                // for on Stripe's page. Never an author name: it may be an
                // anonymous book.
                'description' => $paperback
                    ? 'Paperback, printed on demand and posted to you'
                    : $formats.' · '.$product->licenseTerms()['label'].' license'
                        .($order->discount_cents > 0 ? ' · discount applied' : ''),
                'amount_cents' => $order->total_cents - $order->shipping_cents,
                'quantity' => 1,
            ]],
            // A paperback is posted: Stripe collects the address and the
            // phone number the printer needs, and adds the shipping charge.
            'shipping' => $paperback ? [
                'countries' => config('commerce.paperback.countries'),
                'amount_cents' => $order->shipping_cents,
                'label' => 'Printed and posted',
            ] : null,
            'customer_email' => $order->buyer_email,
            // Stripe substitutes the session id into {CHECKOUT_SESSION_ID}.
            'success_url' => route('store.checkout.success').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('store.checkout.cancelled', ['product' => $product->slug]),
        ]);
    }
}
