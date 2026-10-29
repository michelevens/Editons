<?php

namespace App\Commerce\Reports;

use App\Commerce\Partners\Commissions;
use App\Commerce\Revenue;
use App\Models\OrderItem;

/**
 * The money of order items, added up — the one place a sales figure is
 * computed, so every report, breakdown, CSV row and statement agrees.
 *
 * Per line (one order item, one copy):
 *
 *   gross      what the buyer paid for it (after any discount code)
 *   discount   what a discount code took off first: the order's discount,
 *              split across its items in proportion to price (Revenue::
 *              allocate), so the parts add up to the order's
 *   refunded   what was given back
 *   fee        the estimated payment fee (Revenue::fee at payment)
 *   net        gross − refunded − fee
 *   royalty    the seller's share now (Revenue::earned: after refunds, and
 *              already net of any partner's commission)
 *   partner    a partner's commission now (Commissions::earned)
 *
 * Totals are plain sums of the lines, so the rows of any breakdown add up to
 * the total exactly — there is no rounding after the line.
 */
final class Tally
{
    public const FIELDS = ['units', 'gross_cents', 'discount_cents', 'refunded_cents', 'refunds', 'fee_cents', 'net_cents', 'royalty_cents', 'partner_cents', 'partner_units'];

    /** @var array<string, int> */
    private array $sums;

    /** @var array<int, true> */
    private array $orders = [];

    public function __construct()
    {
        $this->sums = array_fill_keys(self::FIELDS, 0);
    }

    /**
     * One item's figures.
     *
     * @return array{gross_cents: int, discount_cents: int, refunded_cents: int, fee_cents: int, net_cents: int, royalty_cents: int, partner_cents: int}
     */
    public static function line(OrderItem $item): array
    {
        $gross = (int) $item->price_cents;
        $refunded = (int) $item->refunded_cents;
        $fee = (int) $item->fee_cents;

        return [
            'gross_cents' => $gross,
            'discount_cents' => self::discount($item),
            'refunded_cents' => $refunded,
            'fee_cents' => $fee,
            'net_cents' => $gross - $refunded - $fee,
            'royalty_cents' => Revenue::earned($item),
            'partner_cents' => Commissions::earned($item),
        ];
    }

    /** @param  array<string, int>|null  $line  the item's line(), when already computed */
    public function add(OrderItem $item, ?array $line = null): void
    {
        $line ??= self::line($item);

        $this->sums['units']++;
        $this->sums['refunds'] += $line['refunded_cents'] > 0 ? 1 : 0;
        $this->sums['partner_units'] += (int) $item->partner_commission_cents > 0 ? 1 : 0;

        foreach ($line as $field => $cents) {
            $this->sums[$field] += $cents;
        }

        $this->orders[(int) $item->order_id] = true;
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return ['units' => $this->sums['units'], 'orders' => count($this->orders)] + $this->sums;
    }

    /**
     * @param  iterable<OrderItem>  $items
     * @return array<string, int>
     */
    public static function of(iterable $items): array
    {
        $tally = new self;

        foreach ($items as $item) {
            $tally->add($item);
        }

        return $tally->toArray();
    }

    /** A tally with nothing in it, for a currency with no sales in a range. */
    public static function zero(): array
    {
        return (new self)->toArray();
    }

    /**
     * This item's part of its order's discount. Checkout writes one item
     * per order, so this is nearly always the whole discount; an order with
     * several items splits it by price.
     */
    private static function discount(OrderItem $item): int
    {
        $order = $item->relationLoaded('order') ? $item->order : null;
        $discount = (int) ($order?->discount_cents ?? 0);

        if ($discount <= 0) {
            return 0;
        }

        $siblings = $order->relationLoaded('items') ? $order->items : $order->items()->orderBy('id')->get(['id', 'price_cents']);

        if ($siblings->count() <= 1) {
            return $discount;
        }

        $siblings = $siblings->sortBy('id')->values();
        $parts = Revenue::allocate($discount, $siblings->pluck('price_cents')->map(fn ($p) => (int) $p)->all());
        $index = $siblings->search(fn ($s) => (int) $s->id === (int) $item->id);

        return $index === false ? 0 : $parts[$index];
    }
}
