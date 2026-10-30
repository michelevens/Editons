<?php

namespace App\Commerce;

use App\Billing\Refunds;
use App\Mail\Notify;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pre-orders: a product with a release date in the future.
 *
 * Checkout works as for any book, and the order is paid at once; the item
 * carries the release date (Checkout) and gets no licence when it is paid
 * (Fulfilment). `commerce:release-preorders`, every five minutes, issues
 * the licences of items whose date has come — once each, under a row lock —
 * and emails the buyer their download links.
 *
 * Until then the buyer can cancel from Your purchases: the whole order is
 * refunded through the console's refund path (App\Billing\Refunds), which
 * with Stripe waits for charge.refunded like any other refund. An order
 * with a refund under way is not released.
 */
final class Preorders
{
    public function __construct(private readonly Refunds $refunds) {}

    /** Release every item that is due. Returns how many were released. */
    public function releaseDue(): int
    {
        $orderIds = OrderItem::whereNotNull('release_at')
            ->whereNull('delivered_at')
            ->where('release_at', '<=', now())
            ->whereHas('order', fn ($q) => $q->whereIn('status', Order::COUNTED))
            ->distinct()
            ->pluck('order_id');

        $released = 0;

        foreach ($orderIds as $orderId) {
            $released += $this->release((int) $orderId);
        }

        return $released;
    }

    /** Whether the buyer can still cancel: paid, nothing delivered, no refund yet. */
    public static function cancellable(Order $order): bool
    {
        $order->loadMissing('items');

        return $order->status === 'paid'
            && $order->refunded_cents === 0
            && $order->items->isNotEmpty()
            && $order->items->every(fn (OrderItem $item) => $item->awaitingRelease())
            && ! self::refundUnderWay($order);
    }

    /** Cancel a pre-order and refund it in full. The buyer's own only. */
    public function cancel(Order $order, User $buyer): Refund
    {
        abort_unless($order->buyer_user_id === $buyer->id, 404);

        if (! self::cancellable($order)) {
            throw ValidationException::withMessages(['cancel' => __('This order can no longer be cancelled.')]);
        }

        return $this->refunds->refundOrder($order, null, 'Pre-order cancelled by the buyer before release', $buyer);
    }

    /** A refund asked for and not failed, covering the whole order. */
    public static function refundUnderWay(Order $order): bool
    {
        return Refund::where('order_id', $order->id)->where('status', '!=', 'failed')->sum('amount_cents') >= $order->total_cents;
    }

    private function release(int $orderId): int
    {
        $result = DB::transaction(function () use ($orderId) {
            $order = Order::whereKey($orderId)->lockForUpdate()->first();

            if (! $order || ! in_array($order->status, Order::COUNTED, true) || self::refundUnderWay($order)) {
                return null;
            }

            $due = $order->items()
                ->whereNotNull('release_at')
                ->whereNull('delivered_at')
                ->where('release_at', '<=', now())
                ->get()
                ->reject(fn (OrderItem $item) => $item->isPaperback());

            $licenses = $due->map(fn (OrderItem $item) => Fulfilment::license($item));

            return $due->isEmpty() ? null : [$order, $due, $licenses];
        });

        if (! $result) {
            return 0;
        }

        [$order, $items, $licenses] = $result;

        if ($order->buyer_email) {
            $expires = Downloads::expiry();

            Notify::preorderReleased(
                $order->buyer_email,
                buyerName: $order->buyer_name,
                orderNumber: $order->number,
                items: $items->values()->map(fn (OrderItem $item, int $i) => [
                    'title' => $item->title,
                    'downloads' => array_map(
                        fn (array $link) => ['detail' => $link['label'], 'url' => $link['url']],
                        Downloads::links($licenses->values()[$i]->setRelation('item', $item), $expires),
                    ),
                ])->all(),
                licenseKey: $licenses->pluck('key')->implode(', ') ?: null,
                linksExpireAt: $expires,
                receiptUrl: $order->buyer_user_id ? route('purchases.index') : null,
            );
        }

        return $items->count();
    }
}
