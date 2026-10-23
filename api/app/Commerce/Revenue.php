<?php

namespace App\Commerce;

use App\Models\OrderItem;

/**
 * Where a sale's money goes: gross, the payment fee, and the author's share.
 *
 *   gross    what the buyer paid
 *   fee      Stripe's fee, ESTIMATED from its standard card pricing
 *            (config commerce.stripe_fee): the real figure lives on the
 *            balance transaction, one more Stripe call per order to read
 *   net      gross − fee
 *   royalty  net − the platform's share (config commerce.platform_rate,
 *            zero until pricing is decided)
 *
 * Refunds: Stripe does not return its fee on a refund, so a refunded sale
 * still cost the fee. The author's royalty shrinks in proportion to what is
 * left of the net, and never goes below zero — a fully refunded sale earns
 * nothing and owes nothing.
 */
final class Revenue
{
    /** The estimated payment fee on one charge of $grossCents. */
    public static function fee(int $grossCents): int
    {
        if ($grossCents <= 0) {
            return 0;
        }

        $fee = (int) round($grossCents * config('commerce.stripe_fee.percent') / 100)
            + (int) config('commerce.stripe_fee.fixed_cents');

        return min($fee, $grossCents);
    }

    /** The author's share of a sale whose fee is $feeCents. */
    public static function royalty(int $grossCents, int $feeCents): int
    {
        $net = max(0, $grossCents - $feeCents);

        return max(0, $net - (int) round($net * (float) config('commerce.platform_rate')));
    }

    /**
     * Split one order's fee across its items, in proportion to price, so the
     * parts add up to the whole.
     *
     * @param  list<int>  $prices
     * @return list<int>
     */
    public static function allocate(int $total, array $prices): array
    {
        $sum = array_sum($prices);

        if ($sum <= 0 || $prices === []) {
            return array_fill(0, count($prices), 0);
        }

        $parts = [];
        $given = 0;

        foreach ($prices as $i => $price) {
            $part = $i === array_key_last($prices)
                ? $total - $given
                : intdiv($total * $price, $sum);
            $parts[] = $part;
            $given += $part;
        }

        return $parts;
    }

    /** What an item earns the author now, after any refund. */
    public static function earned(OrderItem $item): int
    {
        if ($item->refunded_cents <= 0) {
            return $item->royalty_cents;
        }

        $net = $item->price_cents - $item->fee_cents;

        if ($net <= 0) {
            return 0;
        }

        $left = $item->price_cents - $item->refunded_cents - $item->fee_cents;

        return max(0, (int) round($item->royalty_cents * $left / $net));
    }
}
