<?php

namespace App\Commerce;

use App\Commerce\Gateway\CheckoutSession;
use App\Commerce\Gateway\PaymentGateway;
use App\Models\DiscountCode;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Starting a purchase: a pending order, then a hosted Checkout Session.
 *
 * The order exists before the session so the session can carry its id; the
 * price comes from the product row on the server, never from the request.
 * A pending order is not a sale — only a signed webhook makes it one
 * (Fulfilment).
 *
 * A discount code lowers the price before the order is written, so the
 * order, the session and the item (and so the author's royalty) all carry
 * the price actually paid.
 */
final class Checkout
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * $discount is a quote from App\Growth\Discounts::quote, already checked.
     *
     * @param  array{discount: DiscountCode, discount_cents: int, total_cents: int}|null  $discount
     */
    public function start(Product $product, ?User $buyer = null, ?string $email = null, ?array $discount = null): CheckoutSession
    {
        $product->loadMissing('book');
        // What the buyer pays: the product's price, less a discount code.
        $price = $discount['total_cents'] ?? $product->price_cents;

        $order = DB::transaction(function () use ($product, $buyer, $email, $discount, $price) {
            $order = new Order;
            $order->forceFill([
                'number' => Order::newNumber(),
                'status' => 'pending',
                'buyer_user_id' => $buyer?->id,
                'buyer_email' => $email ?? $buyer?->email,
                'currency' => $product->currency,
                'total_cents' => $price,
                'discount_code_id' => $discount ? $discount['discount']->id : null,
                'discount_cents' => $discount['discount_cents'] ?? 0,
            ])->save();

            $order->items()->make()->forceFill([
                'product_id' => $product->id,
                'book_id' => $product->book_id,
                'seller_id' => $product->user_id,
                'title' => $product->displayTitle(),
                'formats' => array_values($product->formats),
                'license_type' => $product->license,
                'price_cents' => $price,
            ])->save();

            return $order;
        });

        try {
            $session = $this->createSession($order, $product);
        } catch (Throwable $e) {
            // No session, no way to pay: close the order rather than leave
            // it pending forever.
            $order->forceFill(['status' => 'cancelled'])->save();

            throw $e;
        }

        $order->forceFill(['stripe_session_id' => $session->id])->save();

        return $session;
    }

    private function createSession(Order $order, Product $product): CheckoutSession
    {
        $formats = implode(' + ', array_map('strtoupper', $product->formats));

        return $this->gateway->createCheckoutSession([
            'order_id' => $order->id,
            'order_number' => $order->number,
            'currency' => $order->currency,
            'line_items' => [[
                'name' => $product->displayTitle(),
                // Formats and license, so the buyer sees what they are paying
                // for on Stripe's page. Never an author name: it may be an
                // anonymous book.
                'description' => $formats.' · '.$product->licenseTerms()['label'].' license'
                    .($order->discount_cents > 0 ? ' · discount applied' : ''),
                'amount_cents' => $order->total_cents,
                'quantity' => 1,
            ]],
            'customer_email' => $order->buyer_email,
            // Stripe substitutes the session id into {CHECKOUT_SESSION_ID}.
            'success_url' => route('store.checkout.success').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('store.checkout.cancelled', ['product' => $product->slug]),
        ]);
    }
}
