<?php

namespace App\Commerce;

use App\Commerce\Partners\Commissions;
use App\Commerce\Partners\PartnerNotices;
use App\Growth\Discounts;
use App\Mail\Notify;
use App\Models\License;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What happens when Stripe says money moved.
 *
 * Called with the `data.object` of a signed webhook event (or, locally, of
 * the fake gateway's test checkout). Every method is idempotent on the
 * order's own state, under a row lock: a retried or duplicated event finds
 * the work done and does nothing — no second license, no second email. The
 * webhook controller also claims each event id (ProcessedWebhook), so the
 * state check is the second of two guards, not the only one.
 *
 * Emails go out after the transaction commits, so nobody is told about a
 * sale that was rolled back.
 */
final class Fulfilment
{
    /**
     * checkout.session.completed (and async_payment_succeeded).
     *
     * @param  array<string, mixed>  $session  a Stripe Checkout Session
     */
    public function checkoutCompleted(array $session, ?string $paymentMethod = null): ?Order
    {
        // A bank debit completes the session before the money arrives; its
        // async_payment_succeeded event brings the session back as paid.
        if (($session['payment_status'] ?? null) !== 'paid') {
            return null;
        }

        $order = $this->findOrder($session['id'] ?? null, $session['metadata']['order_id'] ?? null);

        if (! $order) {
            Log::warning('Checkout completed for an unknown order.', ['session' => $session['id'] ?? null]);

            return null;
        }

        $fulfilled = DB::transaction(function () use ($order, $session, $paymentMethod) {
            $order = Order::whereKey($order->id)->lockForUpdate()->first();

            if ($order->status !== 'pending' && $order->status !== 'cancelled') {
                return null;
            }

            // The amount Stripe took must be the amount the order asked for.
            // The session was built from the order, so a mismatch means
            // something tampered with or misrouted it: refuse, loudly.
            if ((int) ($session['amount_total'] ?? -1) !== $order->total_cents
                || strtolower((string) ($session['currency'] ?? '')) !== $order->currency) {
                Log::error('Checkout amount does not match the order; not fulfilled.', [
                    'order' => $order->number,
                    'expected' => $order->total_cents.' '.$order->currency,
                    'received' => ($session['amount_total'] ?? '?').' '.($session['currency'] ?? '?'),
                ]);

                return null;
            }

            $customer = $session['customer_details'] ?? [];
            $items = $order->items()->get();
            $fee = Revenue::fee($order->total_cents);
            $fees = Revenue::allocate($fee, $items->pluck('price_cents')->all());

            $order->forceFill([
                'status' => 'paid',
                'paid_at' => now(),
                'fee_cents' => $fee,
                'buyer_email' => $customer['email'] ?? $session['customer_email'] ?? $order->buyer_email,
                'buyer_name' => $customer['name'] ?? $order->buyer_name,
                'buyer_country' => isset($customer['address']['country'])
                    ? strtoupper(substr((string) $customer['address']['country'], 0, 2))
                    : null,
                'stripe_payment_intent' => is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : null,
                'payment_method' => $paymentMethod,
            ])->save();

            foreach ($items->values() as $i => $item) {
                $item->forceFill([
                    'fee_cents' => $fees[$i],
                    'royalty_cents' => Revenue::royalty($item->price_cents, $fees[$i]),
                ])->save();

                $license = new License;
                $license->forceFill([
                    'order_item_id' => $item->id,
                    'key' => License::newKey(),
                    'license' => $item->license_type,
                    'terms' => (config("commerce.licenses.{$item->license_type}") ?? config('commerce.licenses.personal'))['terms'],
                    'status' => 'active',
                ])->save();
            }

            // A discount code counts as used once the order is paid.
            app(Discounts::class)->redeem($order);

            // A partner's commission comes out of the seller's royalty, so
            // it is taken before anybody is told what they earned.
            $commissions = app(Commissions::class)->accrue($order, $items);

            return [$order, $commissions];
        });

        if ($fulfilled) {
            [$fulfilled, $commissions] = $fulfilled;
            $this->announceSale($fulfilled->fresh(['items.license', 'items.product.book', 'items.book', 'items.seller']));
            PartnerNotices::earned($commissions);
        }

        return $fulfilled;
    }

    /**
     * checkout.session.expired: the buyer never paid.
     *
     * @param  array<string, mixed>  $session
     */
    public function checkoutExpired(array $session): void
    {
        $order = $this->findOrder($session['id'] ?? null, $session['metadata']['order_id'] ?? null);

        if ($order && $order->status === 'pending') {
            Order::whereKey($order->id)->where('status', 'pending')->update(['status' => 'cancelled']);
        }
    }

    /**
     * charge.refunded. Stripe sends the charge with its CUMULATIVE
     * amount_refunded, so the refund this event reports is the difference
     * from what the order has already recorded — which also makes a repeat
     * of the same event a no-op.
     *
     * @param  array<string, mixed>  $charge  a Stripe Charge
     */
    public function chargeRefunded(array $charge): ?Order
    {
        $order = $this->findOrderForCharge($charge);

        if (! $order) {
            return null;
        }

        $result = DB::transaction(function () use ($order, $charge) {
            $order = Order::whereKey($order->id)->lockForUpdate()->first();

            if (! in_array($order->status, Order::COUNTED, true)) {
                return null;
            }

            $refunded = min($order->total_cents, (int) ($charge['amount_refunded'] ?? 0));
            $delta = $refunded - $order->refunded_cents;

            if ($delta <= 0) {
                return null;
            }

            $full = $refunded >= $order->total_cents;
            $items = $order->items()->get();

            // Spread this refund over what each item has left to refund.
            $left = $items->map(fn (OrderItem $item) => $item->price_cents - $item->refunded_cents)->all();
            $parts = Revenue::allocate($delta, $left);

            foreach ($items->values() as $i => $item) {
                $item->forceFill(['refunded_cents' => min($item->price_cents, $item->refunded_cents + $parts[$i])])->save();
            }

            $order->forceFill([
                'refunded_cents' => $refunded,
                'refunded_at' => now(),
                'status' => $full ? 'refunded' : 'partially_refunded',
            ])->save();

            // Everything back means nothing kept: the links stop working.
            if ($full) {
                License::whereIn('order_item_id', $items->pluck('id'))
                    ->where('status', 'active')
                    ->update(['status' => 'revoked', 'revoked_at' => now()]);
            }

            // A partner's commission shrinks with the sale it came from.
            app(Commissions::class)->refunded($order);

            return [$order, $delta];
        });

        if (! $result) {
            return null;
        }

        [$order, $delta] = $result;
        $order->load('items.book');

        if ($order->buyer_email) {
            Notify::refundProcessed(
                $order->buyer_email,
                buyerName: $order->buyer_name,
                orderNumber: $order->number,
                amountCents: $delta,
                currency: $order->currency,
                refundedAt: now(),
                itemTitles: $order->items->pluck('title')->all(),
                paymentMethod: $order->payment_method ?? self::cardLabel($charge),
                sellerName: $this->sellerName($order),
            );
        }

        return $order;
    }

    // ── Internals ───────────────────────────────────────────────────────

    private function findOrder(mixed $sessionId, mixed $orderId): ?Order
    {
        if (is_string($sessionId) && $sessionId !== '') {
            if ($order = Order::where('stripe_session_id', $sessionId)->first()) {
                return $order;
            }
        }

        // Only as a fallback, and only when the ids agree: a session id we
        // have never seen must not be able to name somebody else's order.
        if (is_numeric($orderId) && ($order = Order::find((int) $orderId)) && ! $order->stripe_session_id) {
            return $order;
        }

        return null;
    }

    /** @param  array<string, mixed>  $charge */
    private function findOrderForCharge(array $charge): ?Order
    {
        $intent = $charge['payment_intent'] ?? null;

        if (is_string($intent) && $intent !== '' && ($order = Order::where('stripe_payment_intent', $intent)->first())) {
            return $order;
        }

        $orderId = $charge['metadata']['order_id'] ?? null;

        // The charge's metadata is copied from the payment intent we
        // created, so it names our order; the intent must still match.
        if (is_numeric($orderId) && ($order = Order::find((int) $orderId))
            && (! $order->stripe_payment_intent || $order->stripe_payment_intent === $intent)) {
            return $order;
        }

        return null;
    }

    private function announceSale(Order $order): void
    {
        $expires = Downloads::expiry();

        $items = $order->items->map(fn (OrderItem $item) => [
            'title' => $item->title,
            'format' => implode(' + ', array_map('strtoupper', $item->formats)),
            'amount_cents' => $item->price_cents,
            'downloads' => $item->license
                ? array_map(fn (array $link) => ['detail' => $link['label'], 'url' => $link['url']], Downloads::links($item->license, $expires))
                : [],
        ])->all();

        if ($order->buyer_email) {
            $licenses = $order->items->map(fn (OrderItem $item) => $item->license)->filter();

            Notify::orderReceipt(
                $order->buyer_email,
                buyerName: $order->buyer_name,
                orderNumber: $order->number,
                items: $items,
                totalCents: $order->total_cents,
                currency: $order->currency,
                placedAt: $order->paid_at ?? now(),
                licenseKey: $licenses->pluck('key')->implode(', ') ?: null,
                licenseTerms: $licenses->pluck('terms')->unique()->implode(' ') ?: null,
                linksExpireAt: $expires,
                receiptUrl: $order->buyer_user_id ? route('purchases.index') : null,
                sellerName: $this->sellerName($order),
                paymentMethod: $order->payment_method,
            );
        }

        foreach ($order->items as $item) {
            $book = $item->book;

            if (! $book || ! $item->seller) {
                continue;
            }

            Notify::newSale(
                $item->seller,
                book: $book,
                format: implode(' + ', array_map('strtoupper', $item->formats)),
                amountCents: $item->price_cents,
                royaltyCents: $item->royalty_cents,
                currency: $order->currency,
                soldAt: $order->paid_at ?? now(),
                channel: __('Écrivain store'),
                buyerCountry: self::countryName($order->buyer_country),
                salesThisMonth: OrderItem::where('seller_id', $item->seller_id)
                    ->whereHas('order', fn ($q) => $q->whereIn('status', Order::COUNTED)
                        ->where('paid_at', '>=', now()->startOfMonth()))
                    ->count(),
            );
        }
    }

    /**
     * The name a buyer sees as the seller: the book's author name, and only
     * when every item agrees on one. An anonymous book has none, and the
     * receipt then names the store rather than the account behind it.
     */
    private function sellerName(Order $order): ?string
    {
        $names = $order->items
            ->map(fn (OrderItem $item) => trim((string) $item->book?->author_name) ?: null)
            ->unique();

        return $names->count() === 1 ? $names->first() : null;
    }

    /** @param  array<string, mixed>  $charge */
    private static function cardLabel(array $charge): ?string
    {
        $card = $charge['payment_method_details']['card'] ?? null;

        return is_array($card) && isset($card['brand'], $card['last4'])
            ? ucfirst((string) $card['brand']).' ending '.$card['last4']
            : null;
    }

    private static function countryName(?string $code): ?string
    {
        if (! $code) {
            return null;
        }

        return class_exists(\Locale::class)
            ? (\Locale::getDisplayRegion('-'.$code, 'en') ?: $code)
            : $code;
    }
}
