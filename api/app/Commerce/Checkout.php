<?php

namespace App\Commerce;

use App\Commerce\Gateway\CheckoutSession;
use App\Commerce\Gateway\PaymentGateway;
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
 */
final class Checkout
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    public function start(Product $product, ?User $buyer = null, ?string $email = null): CheckoutSession
    {
        $product->loadMissing('book');

        $order = DB::transaction(function () use ($product, $buyer, $email) {
            $order = new Order;
            $order->forceFill([
                'number' => Order::newNumber(),
                'status' => 'pending',
                'buyer_user_id' => $buyer?->id,
                'buyer_email' => $email ?? $buyer?->email,
                'currency' => $product->currency,
                'total_cents' => $product->price_cents,
            ])->save();

            $order->items()->make()->forceFill([
                'product_id' => $product->id,
                'book_id' => $product->book_id,
                'seller_id' => $product->user_id,
                'title' => $product->displayTitle(),
                'formats' => array_values($product->formats),
                'license_type' => $product->license,
                'price_cents' => $product->price_cents,
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
                'description' => $formats.' · '.$product->licenseTerms()['label'].' license',
                'amount_cents' => $product->price_cents,
                'quantity' => 1,
            ]],
            'customer_email' => $order->buyer_email,
            // Stripe substitutes the session id into {CHECKOUT_SESSION_ID}.
            'success_url' => route('store.checkout.success').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('store.checkout.cancelled', ['product' => $product->slug]),
        ]);
    }
}
