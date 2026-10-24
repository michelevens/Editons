<?php

namespace App\Commerce;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payout;
use App\Models\User;
use App\Platform\Mask;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Storefront sales across the platform, for the console (SalesReport is one
 * author's view of the same rows).
 *
 * Royalties come from the same arithmetic the authors' statements use
 * (Revenue::earned: the share after the estimated fee, shrunk by refunds,
 * never negative). What is owed is what was earned less the payouts the
 * owner has recorded — paid outside Écrivain, because Stripe Connect is not
 * built, so nothing pays an author automatically.
 *
 * Money is never added across currencies: every total is per currency.
 * Buyers' addresses are masked.
 */
final class PlatformSales
{
    /** Orders that took money (a full refund still did, then gave it back). */
    public const SOLD = ['paid', 'partially_refunded', 'refunded'];

    /** @param  array{status?: ?string, currency?: ?string, q?: ?string}  $filters */
    public function orders(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $term = trim((string) ($filters['q'] ?? ''));

        return Order::query()
            ->with(['items:id,order_id,title,seller_id,book_id,price_cents,refunded_cents', 'items.seller:id,name,email'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status), fn ($q) => $q->where('status', '!=', 'pending'))
            ->when($filters['currency'] ?? null, fn ($q, $currency) => $q->where('currency', $currency))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('number', 'ilike', '%'.addcslashes($term, '%_\\').'%')
                ->orWhereHas('items', fn ($i) => $i->where('title', 'ilike', '%'.addcslashes($term, '%_\\').'%'))))
            ->orderByRaw('coalesce(paid_at, created_at) desc')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Order $order) => [
                'id' => $order->id,
                'number' => $order->number,
                'at' => ($order->paid_at ?? $order->created_at)->toIso8601String(),
                'books' => $order->items->pluck('title')->all(),
                'authors' => $order->items->map(fn (OrderItem $item) => $item->seller ? ['id' => $item->seller->id, 'name' => $item->seller->name] : null)->filter()->unique('id')->values()->all(),
                'buyer' => Mask::email($order->buyer_email),
                'amount_cents' => $order->total_cents,
                'discount_cents' => (int) $order->discount_cents,
                'refunded_cents' => $order->refunded_cents,
                'refundable_cents' => in_array($order->status, Order::COUNTED, true) && $order->stripe_payment_intent ? $order->total_cents - $order->refunded_cents : 0,
                'currency' => $order->currency,
                'status' => $order->status,
            ]);
    }

    /**
     * Per currency: gross, refunded, estimated fees, net, royalties, and
     * what is left to the platform.
     *
     * @return list<array<string, mixed>>
     */
    public function totals(?int $days = null): array
    {
        return $this->soldItems($days)
            ->groupBy(fn ($item) => $item->currency)
            ->map(fn (Collection $rows, string $currency) => ['currency' => $currency] + self::sum($rows))
            ->sortByDesc('gross_cents')
            ->values()
            ->all();
    }

    /**
     * What each author has earned, been paid and is owed, per currency.
     *
     * @return list<array<string, mixed>>
     */
    public function royalties(): array
    {
        $earned = $this->soldItems(null)
            ->groupBy(fn ($item) => $item->seller_id.'|'.$item->currency)
            ->map(fn (Collection $rows) => [
                'seller_id' => (int) $rows->first()->seller_id,
                'currency' => $rows->first()->currency,
                'units' => $rows->count(),
                'earned_cents' => (int) $rows->sum(fn ($item) => self::earned($item)),
            ]);

        $paid = Payout::select('user_id', 'currency', DB::raw('sum(amount_cents) as paid'), DB::raw('max(paid_on) as last_paid'))
            ->groupBy('user_id', 'currency')
            ->get()
            ->keyBy(fn ($row) => $row->user_id.'|'.$row->currency);

        $keys = $earned->keys()->merge($paid->keys())->unique();
        $sellers = User::whereIn('id', $keys->map(fn ($k) => (int) explode('|', $k)[0])->unique())->get(['id', 'name', 'email', 'author_name'])->keyBy('id');

        return $keys->map(function (string $key) use ($earned, $paid, $sellers) {
            [$sellerId, $currency] = explode('|', $key);
            $e = $earned[$key]['earned_cents'] ?? 0;
            $p = (int) ($paid[$key]->paid ?? 0);
            $seller = $sellers[(int) $sellerId] ?? null;

            return [
                'account' => $seller ? ['id' => $seller->id, 'name' => $seller->name, 'email' => $seller->email] : null,
                'currency' => $currency,
                'units' => $earned[$key]['units'] ?? 0,
                'earned_cents' => $e,
                'paid_cents' => $p,
                'owed_cents' => $e - $p,
                'last_paid' => isset($paid[$key]) ? (string) $paid[$key]->last_paid : null,
            ];
        })
            ->sortByDesc('owed_cents')
            ->values()
            ->all();
    }

    /**
     * Best sellers over $days days, per currency.
     *
     * @return array{books: list<array<string, mixed>>, authors: list<array<string, mixed>>}
     */
    public function top(int $days = 30, int $limit = 10): array
    {
        $items = $this->soldItems($days);
        $sellers = User::whereIn('id', $items->pluck('seller_id')->unique())->get(['id', 'name'])->keyBy('id');

        $rank = fn (Collection $groups) => $groups
            ->map(fn (Collection $rows) => self::sum($rows) + ['currency' => $rows->first()->currency, 'first' => $rows->first()])
            ->sortByDesc('gross_cents')
            ->take($limit)
            ->values();

        return [
            'books' => $rank($items->groupBy(fn ($i) => ($i->book_id ?? 'x'.$i->title).'|'.$i->currency))
                ->map(fn (array $row) => [
                    'title' => $row['first']->title,
                    'book_id' => $row['first']->book_id,
                    'author' => isset($sellers[$row['first']->seller_id]) ? ['id' => $row['first']->seller_id, 'name' => $sellers[$row['first']->seller_id]->name] : null,
                ] + array_diff_key($row, ['first' => 1]))
                ->all(),
            'authors' => $rank($items->groupBy(fn ($i) => $i->seller_id.'|'.$i->currency))
                ->map(fn (array $row) => [
                    'author' => isset($sellers[$row['first']->seller_id]) ? ['id' => $row['first']->seller_id, 'name' => $sellers[$row['first']->seller_id]->name] : null,
                ] + array_diff_key($row, ['first' => 1]))
                ->all(),
        ];
    }

    /**
     * Gross per day for the last $days days, in the currency with the most
     * sales, zero-filled.
     *
     * @return array{currency: string, days: list<array<string, mixed>>}
     */
    public function daily(int $days = 30): array
    {
        $items = $this->soldItems($days);
        $currency = $items->groupBy('currency')->sortByDesc(fn ($rows) => $rows->sum('price_cents'))->keys()->first() ?? 'usd';
        $byDay = $items->where('currency', $currency)->groupBy(fn ($i) => substr((string) $i->paid_at, 0, 10));

        return [
            'currency' => $currency,
            'days' => collect(range($days - 1, 0))->map(function (int $ago) use ($byDay) {
                $day = now()->subDays($ago)->toDateString();
                $rows = $byDay[$day] ?? collect();

                return [
                    'day' => $day,
                    'units' => $rows->count(),
                    'gross_cents' => (int) $rows->sum('price_cents'),
                    'refunded_cents' => (int) $rows->sum('refunded_cents'),
                ];
            })->values()->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function payouts(int $limit = 100): array
    {
        return Payout::with(['user:id,name,email', 'recorder:id,name'])
            ->latest('paid_on')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (Payout $p) => [
                'id' => $p->id,
                'account' => $p->user ? ['id' => $p->user->id, 'name' => $p->user->name, 'email' => $p->user->email] : null,
                'amount_cents' => $p->amount_cents,
                'currency' => $p->currency,
                'method' => Payout::METHODS[$p->method] ?? $p->method,
                'reference' => $p->reference,
                'paid_on' => $p->paid_on->toDateString(),
                'note' => $p->note,
                'by' => $p->recorder?->name,
            ])
            ->all();
    }

    /** What $seller is owed in $currency now. */
    public function owed(User $seller, string $currency): int
    {
        $earned = (int) $this->soldItems(null, $seller->id)
            ->where('currency', $currency)
            ->sum(fn ($item) => self::earned($item));

        return $earned - (int) Payout::where('user_id', $seller->id)->where('currency', $currency)->sum('amount_cents');
    }

    // ── Internals ───────────────────────────────────────────────────────

    /** Items of orders that took money, with their order's currency and date. */
    private function soldItems(?int $days, ?int $sellerId = null): Collection
    {
        return DB::table('order_items as i')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->whereIn('o.status', self::SOLD)
            ->whereNotNull('o.paid_at')
            ->when($days, fn ($q) => $q->where('o.paid_at', '>=', now()->subDays($days - 1)->startOfDay()))
            ->when($sellerId, fn ($q) => $q->where('i.seller_id', $sellerId))
            ->get(['i.id', 'i.order_id', 'i.book_id', 'i.seller_id', 'i.title', 'i.price_cents', 'i.fee_cents', 'i.royalty_cents', 'i.refunded_cents', 'o.currency', 'o.paid_at']);
    }

    /** Revenue::earned on a plain row. */
    private static function earned(object $item): int
    {
        $model = (new OrderItem)->forceFill([
            'price_cents' => (int) $item->price_cents,
            'fee_cents' => (int) $item->fee_cents,
            'royalty_cents' => (int) $item->royalty_cents,
            'refunded_cents' => (int) $item->refunded_cents,
        ]);

        return Revenue::earned($model);
    }

    /** @return array<string, int> */
    private static function sum(Collection $rows): array
    {
        $gross = (int) $rows->sum('price_cents');
        $refunded = (int) $rows->sum('refunded_cents');
        $fee = (int) $rows->sum('fee_cents');
        $royalty = (int) $rows->sum(fn ($item) => self::earned($item));
        $net = $gross - $refunded - $fee;

        return [
            'units' => $rows->count(),
            'orders' => $rows->pluck('order_id')->unique()->count(),
            'gross_cents' => $gross,
            'refunded_cents' => $refunded,
            'fee_cents' => $fee,
            'net_cents' => $net,
            'royalty_cents' => $royalty,
            'platform_cents' => $net - $royalty,
        ];
    }
}
