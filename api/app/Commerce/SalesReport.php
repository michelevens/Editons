<?php

namespace App\Commerce;

use App\Commerce\Partners\Commissions;
use App\Models\Book;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * What an author's storefront has sold, from real orders.
 *
 * Only the seller's own order items, ever: every query starts from
 * seller_id. Buyers are not named — a country is as much as an author sees,
 * as at any store.
 *
 *   gross     what buyers paid
 *   refunded  what was given back
 *   fee       estimated payment fees (App\Commerce\Revenue)
 *   net       gross − refunded − fee
 *   royalty   the author's share, after refunds — and after partners'
 *             commissions, which are their own figure (partner) so a
 *             statement shows where the difference went
 *
 * Money is never added across currencies: totals come per currency, and the
 * daily series is in the currency with the most sales.
 */
final class SalesReport
{
    /** @return array<string, mixed> */
    public static function for(User $seller, ?Book $book = null, int $days = 30): array
    {
        $items = OrderItem::query()
            ->where('seller_id', $seller->id)
            ->when($book, fn ($q) => $q->where('book_id', $book->id))
            ->whereHas('order', fn ($q) => $q->whereNotNull('paid_at'))
            ->with(['order:id,number,status,currency,paid_at,refunded_at,buyer_country', 'product:id,slug,published_at'])
            ->get();

        $byCurrency = $items->groupBy(fn (OrderItem $item) => $item->order->currency);
        $currency = $byCurrency->sortByDesc(fn (Collection $rows) => $rows->sum('price_cents'))->keys()->first()
            ?? ($book?->product?->currency ?? 'usd');

        return [
            'currency' => $currency,
            'totals' => $byCurrency
                ->map(fn (Collection $rows, string $code) => ['currency' => $code] + self::sum($rows))
                ->values()
                ->all(),
            'products' => $items
                ->groupBy(fn (OrderItem $item) => ($item->product_id ?? 'deleted-'.$item->title).'|'.$item->order->currency)
                ->map(function (Collection $rows) {
                    $first = $rows->first();

                    return [
                        'product_id' => $first->product_id,
                        'title' => $first->title,
                        'url' => $first->product?->isPublished() ? route('store.product', $first->product->slug) : null,
                        'currency' => $first->order->currency,
                    ] + self::sum($rows);
                })
                ->sortByDesc('gross_cents')
                ->values()
                ->all(),
            'daily' => self::daily($items->filter(fn (OrderItem $item) => $item->order->currency === $currency), $days),
            'recent' => $items
                ->sortByDesc(fn (OrderItem $item) => $item->order->paid_at)
                ->take(20)
                ->map(fn (OrderItem $item) => [
                    'number' => $item->order->number,
                    'date' => $item->order->paid_at->toDateString(),
                    'title' => $item->title,
                    'amount_cents' => $item->price_cents,
                    'refunded_cents' => $item->refunded_cents,
                    'royalty_cents' => Revenue::earned($item),
                    'partner_cents' => Commissions::earned($item),
                    'currency' => $item->order->currency,
                    'status' => $item->order->status,
                    'country' => $item->order->buyer_country,
                ])
                ->values()
                ->all(),
            'fee_is_estimate' => true,
        ];
    }

    /**
     * @param  Collection<int, OrderItem>  $rows
     * @return array<string, int>
     */
    private static function sum(Collection $rows): array
    {
        $gross = (int) $rows->sum('price_cents');
        $refunded = (int) $rows->sum('refunded_cents');
        $fee = (int) $rows->sum('fee_cents');

        return [
            'units' => $rows->count(),
            'orders' => $rows->pluck('order_id')->unique()->count(),
            'gross_cents' => $gross,
            'refunded_cents' => $refunded,
            'refunds' => $rows->where('refunded_cents', '>', 0)->count(),
            'fee_cents' => $fee,
            'net_cents' => $gross - $refunded - $fee,
            'royalty_cents' => (int) $rows->sum(fn (OrderItem $item) => Revenue::earned($item)),
            'partner_cents' => (int) $rows->sum(fn (OrderItem $item) => Commissions::earned($item)),
            'partner_units' => $rows->where('partner_commission_cents', '>', 0)->count(),
        ];
    }

    /**
     * Zero-filled, so the chart has an even axis and a quiet day reads as
     * quiet rather than missing.
     *
     * @param  Collection<int, OrderItem>  $items
     * @return list<array<string, mixed>>
     */
    private static function daily(Collection $items, int $days): array
    {
        $sold = $items->groupBy(fn (OrderItem $item) => $item->order->paid_at->toDateString());
        $refunded = $items
            ->filter(fn (OrderItem $item) => $item->refunded_cents > 0 && $item->order->refunded_at)
            ->groupBy(fn (OrderItem $item) => $item->order->refunded_at->toDateString());

        return collect(range($days - 1, 0))
            ->map(function (int $ago) use ($sold, $refunded) {
                $day = now()->subDays($ago);
                $key = $day->toDateString();
                $rows = $sold[$key] ?? collect();

                return [
                    'date' => $key,
                    'label' => $day->format('j M'),
                    'units' => $rows->count(),
                    'gross_cents' => (int) $rows->sum('price_cents'),
                    'royalty_cents' => (int) $rows->sum(fn (OrderItem $item) => Revenue::earned($item)),
                    'refunded_cents' => (int) ($refunded[$key] ?? collect())->sum('refunded_cents'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * One author's month, per product and currency, for a statement.
     *
     * Sales are counted in the month they were paid; a refund in the month
     * it happened reduces that month's royalty through Revenue::earned on
     * the item as it stands now — simple, and never negative.
     *
     * Sales a partner brought in show at the royalty before the partner's
     * commission, and the commissions are their own line ("Partner
     * commissions", negative), so the lines add up to what was earned.
     *
     * @return array<string, array{units: int, royalty_cents: int, lines: list<array{title: string, channel: string, units: int, royalty_cents: int}>}>
     */
    public static function month(User $seller, CarbonInterface $month): array
    {
        $items = OrderItem::query()
            ->where('seller_id', $seller->id)
            ->whereHas('order', fn ($q) => $q
                ->whereIn('status', [...Order::COUNTED, 'refunded'])
                ->where('paid_at', '>=', $month->copy()->startOfMonth())
                ->where('paid_at', '<', $month->copy()->startOfMonth()->addMonth()))
            ->with('order:id,currency')
            ->get();

        return $items
            ->groupBy(fn (OrderItem $item) => $item->order->currency)
            ->map(function (Collection $rows) {
                $lines = $rows
                    ->groupBy(fn (OrderItem $item) => $item->product_id ?? $item->title)
                    ->map(fn (Collection $group) => [
                        'title' => $group->first()->title,
                        'channel' => __('Écrivain store'),
                        'units' => $group->filter(fn (OrderItem $item) => $item->refunded_cents < $item->price_cents)->count(),
                        'royalty_cents' => (int) $group->sum(fn (OrderItem $item) => Revenue::earned($item) + Commissions::earned($item)),
                    ])
                    ->values()
                    ->all();

                $commissions = (int) $rows->sum(fn (OrderItem $item) => Commissions::earned($item));

                if ($commissions > 0) {
                    $lines[] = [
                        'title' => __('Partner commissions'),
                        'channel' => __('Écrivain Partners'),
                        // Not copies: the copies are counted on their books.
                        'units' => 0,
                        'royalty_cents' => -$commissions,
                    ];
                }

                return [
                    'units' => array_sum(array_column($lines, 'units')),
                    'royalty_cents' => array_sum(array_column($lines, 'royalty_cents')),
                    'lines' => $lines,
                ];
            })
            ->all();
    }
}
