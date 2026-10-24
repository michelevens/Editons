<?php

namespace App\Commerce\Partners;

use App\Commerce\Revenue;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Partner;
use App\Models\PartnerCommission;
use Illuminate\Support\Collection;

/**
 * What a partner earns on a sale, and what happens to it afterwards.
 *
 * The arithmetic, per order item, in integer cents:
 *
 *   net         what the buyer paid for the item (after any discount)
 *               less its share of the payment fee (Revenue::fee/allocate)
 *   commission  rate × net, rounded half up, never more than the royalty
 *   royalty     the seller's royalty (Revenue::royalty) LESS the commission
 *
 * so price = fee + platform share + royalty + commission, exactly: the
 * commission comes out of the seller's royalty and the platform's share is
 * the same with or without a partner.
 *
 * The rate and every amount are copied onto the commission row when the
 * order is paid. Nothing is ever recomputed from the product's current rate
 * or the program's current settings.
 *
 * Lifecycle: `pending` when the order is paid; `approved` once the hold is
 * over (partners:release-commissions); `paid` when a payout includes it. A
 * refund shrinks a commission in the same proportion as the seller's
 * royalty (Revenue::earned): before payout the row's reversed_cents grows
 * (all of it: `reversed`); after payout a negative `adjustment` row carries
 * it into the partner's balance.
 */
final class Commissions
{
    public function __construct(private readonly Program $program) {}

    /** Rate × net in basis points, rounded half up. Never negative. */
    public static function amount(int $netCents, int $rateBps): int
    {
        if ($netCents <= 0 || $rateBps <= 0) {
            return 0;
        }

        return intdiv($netCents * $rateBps + 5000, 10000);
    }

    /**
     * The split of one item: [fee, royalty, commission], with the
     * commission capped at what the seller would have earned.
     *
     * @return array{fee: int, net: int, royalty: int, commission: int, platform: int}
     */
    public static function split(int $priceCents, int $feeCents, int $rateBps): array
    {
        $net = max(0, $priceCents - $feeCents);
        $royalty = Revenue::royalty($priceCents, $feeCents);
        $commission = min($royalty, self::amount($net, $rateBps));

        return [
            'fee' => $feeCents,
            'net' => $net,
            'royalty' => $royalty - $commission,
            'commission' => $commission,
            'platform' => $net - $royalty,
        ];
    }

    /** What an item's commission is worth after its refunds, like Revenue::earned. */
    public static function earned(OrderItem $item): int
    {
        $commission = (int) $item->partner_commission_cents;

        if ($commission <= 0 || $item->refunded_cents <= 0) {
            return max(0, $commission);
        }

        $net = $item->price_cents - $item->fee_cents;

        if ($net <= 0) {
            return 0;
        }

        $left = $item->price_cents - $item->refunded_cents - $item->fee_cents;

        return max(0, (int) round($commission * $left / $net));
    }

    /**
     * The order was paid: take each attributed item's commission out of its
     * royalty and write the pending rows. Called by Fulfilment inside its
     * transaction, after fees and royalties are set; idempotent (the unique
     * index on sale rows, and the order's own state, guard repeats).
     *
     * @param  Collection<int, OrderItem>  $items
     * @return list<PartnerCommission> the rows written
     */
    public function accrue(Order $order, Collection $items): array
    {
        if (! $order->partner_id || $order->partner_refusal) {
            return [];
        }

        $partner = Partner::with('user')->find($order->partner_id);

        // Checked again now that Stripe has the buyer's real address: a
        // partner buying through their own link with a guest checkout.
        $refusal = Attribution::refusalAtPayment($partner, $order);

        if ($refusal) {
            $order->forceFill(['partner_refusal' => $refusal])->save();

            return [];
        }

        $rows = [];
        $hold = (int) $this->program->get('hold_days');

        foreach ($items as $item) {
            if (! $item->partner_rate_bps || PartnerCommission::where('order_item_id', $item->id)->where('kind', 'sale')->exists()) {
                continue;
            }

            $split = self::split($item->price_cents, $item->fee_cents, (int) $item->partner_rate_bps);

            if ($split['commission'] <= 0) {
                continue;
            }

            $item->forceFill([
                'royalty_cents' => $split['royalty'],
                'partner_commission_cents' => $split['commission'],
            ])->save();

            $row = new PartnerCommission;
            $row->forceFill([
                'partner_id' => $partner->id,
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'product_id' => $item->product_id,
                'seller_id' => $item->seller_id,
                'title' => $item->title,
                'currency' => $order->currency,
                'kind' => 'sale',
                'rate_bps' => (int) $item->partner_rate_bps,
                'base_cents' => $split['net'],
                'amount_cents' => $split['commission'],
                'status' => 'pending',
                'source' => $order->partner_source,
                'buyer_country' => $order->buyer_country,
                'hold_until' => ($order->paid_at ?? now())->copy()->addDays($hold),
            ])->save();

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The order was (partly) refunded: bring each commission down to what
     * its item now earns. Called by Fulfilment inside its transaction,
     * after the items' refunded_cents are updated. A repeat finds nothing
     * left to take.
     */
    public function refunded(Order $order): void
    {
        $sales = PartnerCommission::where('order_id', $order->id)->where('kind', 'sale')->lockForUpdate()->get();

        foreach ($sales as $sale) {
            $item = OrderItem::find($sale->order_item_id);

            if (! $item) {
                continue;
            }

            $target = self::earned($item);
            $now = (int) PartnerCommission::where('order_item_id', $item->id)
                ->whereIn('kind', ['sale', 'adjustment'])
                ->get()
                ->sum(fn (PartnerCommission $row) => $row->net());
            $take = $now - $target;

            if ($take <= 0) {
                continue;
            }

            if ($sale->status === 'paid') {
                // Already paid out: the partner owes it back, from their
                // next earnings.
                $adjustment = new PartnerCommission;
                $adjustment->forceFill([
                    'partner_id' => $sale->partner_id,
                    'order_id' => $sale->order_id,
                    'order_item_id' => $sale->order_item_id,
                    'product_id' => $sale->product_id,
                    'seller_id' => $sale->seller_id,
                    'title' => $sale->title,
                    'currency' => $sale->currency,
                    'kind' => 'adjustment',
                    'rate_bps' => $sale->rate_bps,
                    'amount_cents' => -$take,
                    'status' => 'approved',
                    'approved_at' => now(),
                    'source' => $sale->source,
                    'note' => 'Refund after payout',
                ])->save();

                continue;
            }

            $reversed = min($sale->amount_cents, $sale->reversed_cents + $take);

            $sale->forceFill([
                'reversed_cents' => $reversed,
                'reversed_at' => now(),
                'status' => $reversed >= $sale->amount_cents ? 'reversed' : $sale->status,
            ])->save();
        }
    }

    /**
     * Commissions whose hold is over become payable. Scheduled daily; any
     * run finds only what is due.
     *
     * @return int how many were approved
     */
    public function releaseDue(): int
    {
        return PartnerCommission::where('status', 'pending')
            ->where('kind', 'sale')
            ->where('hold_until', '<=', now())
            ->whereColumn('reversed_cents', '<', 'amount_cents')
            ->update(['status' => 'approved', 'approved_at' => now(), 'updated_at' => now()]);
    }
}
