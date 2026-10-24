<?php

namespace App\Commerce\Partners;

use App\Models\Order;
use App\Models\Partner;
use App\Models\PartnerClick;
use App\Models\PartnerCommission;
use App\Models\UserLogin;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What a partner's page and links are doing: for the partner's dashboard
 * and, with the fraud signals, for the console.
 *
 * Counted from the rows. A buyer is never identified: at most "a reader in
 * {country}", when Stripe gave a country.
 *
 * Fraud signals (the console's, never the partner's):
 *
 *   self_purchase   checkouts through the partner's own link or code that
 *                   were refused because the partner was the buyer
 *   same_ip         attributed orders whose checkout came from an IP the
 *                   partner signs in from (compared as HMACs)
 *   refund_rate     more than 20 % of commissioned orders refunded (from 3)
 *   click_burst     a day with at least 50 visits and 5× the daily average
 */
final class Insights
{
    /** @return array<string, mixed> */
    public static function stats(Partner $partner, int $days = 30): array
    {
        $since = now()->subDays($days - 1)->startOfDay();
        $clicks = PartnerClick::where('partner_id', $partner->id)->count();
        $recentClicks = PartnerClick::where('partner_id', $partner->id)->where('created_at', '>=', $since)->count();
        $orders = PartnerCommission::where('partner_id', $partner->id)->where('kind', 'sale')->distinct('order_id')->count('order_id');
        $recentOrders = PartnerCommission::where('partner_id', $partner->id)->where('kind', 'sale')->where('created_at', '>=', $since)->distinct('order_id')->count('order_id');

        return [
            'clicks' => $clicks,
            'clicks_recent' => $recentClicks,
            'orders' => $orders,
            'orders_recent' => $recentOrders,
            // Orders per visit, as a percentage, once there are visits.
            'conversion' => $clicks > 0 ? round($orders / $clicks * 100, 1) : null,
            'days' => $days,
        ];
    }

    /**
     * Visits and orders per day, zero-filled.
     *
     * @return list<array{day: string, clicks: int, orders: int}>
     */
    public static function timeline(Partner $partner, int $days = 30): array
    {
        $since = now()->subDays($days - 1)->startOfDay();
        $clicks = PartnerClick::where('partner_id', $partner->id)->where('created_at', '>=', $since)
            ->select('day', DB::raw('count(*) as n'))->groupBy('day')->pluck('n', 'day')
            ->mapWithKeys(fn ($n, $day) => [substr((string) $day, 0, 10) => (int) $n]);
        $orders = PartnerCommission::where('partner_id', $partner->id)->where('kind', 'sale')->where('created_at', '>=', $since)
            ->get(['order_id', 'created_at'])
            ->groupBy(fn ($row) => $row->created_at->toDateString())
            ->map(fn (Collection $rows) => $rows->pluck('order_id')->unique()->count());

        return collect(range($days - 1, 0))->map(function (int $ago) use ($clicks, $orders) {
            $day = now()->subDays($ago)->toDateString();

            return ['day' => $day, 'clicks' => $clicks[$day] ?? 0, 'orders' => $orders[$day] ?? 0];
        })->all();
    }

    /**
     * The partner's commissions, newest first, without the buyer.
     *
     * @return list<array<string, mixed>>
     */
    public static function commissions(Partner $partner, int $limit = 200): array
    {
        return PartnerCommission::where('partner_id', $partner->id)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (PartnerCommission $row) => [
                'id' => $row->id,
                'kind' => $row->kind,
                'title' => $row->title ?? ($row->kind === 'forfeit' ? 'Balance forfeited' : '—'),
                'date' => $row->created_at->toDateString(),
                'amount_cents' => $row->amount_cents,
                'reversed_cents' => $row->reversed_cents,
                'net_cents' => $row->net(),
                'currency' => $row->currency,
                'status' => $row->status,
                'rate' => $row->rate_bps / 100,
                'hold_until' => $row->hold_until?->toDateString(),
                'via' => $row->source,
                'reader' => self::reader($row->buyer_country),
                'note' => $row->kind === 'sale' ? null : $row->note,
            ])
            ->all();
    }

    /** "A reader in Canada", or null: never more than a country. */
    public static function reader(?string $country): ?string
    {
        if (! $country) {
            return null;
        }

        $name = class_exists(\Locale::class) ? (\Locale::getDisplayRegion('-'.$country, 'en') ?: $country) : $country;

        return 'A reader in '.$name;
    }

    /** @return array<string, array{count: int, flag: bool, detail: string}> */
    public static function fraud(Partner $partner): array
    {
        $self = Order::where('partner_id', $partner->id)->where('partner_refusal', 'self_purchase')->count();

        $loginHashes = UserLogin::where('user_id', $partner->user_id)->whereNotNull('ip')->distinct()->pluck('ip')
            ->map(fn (string $ip) => Attribution::hash($ip))->all();
        $sameIp = $loginHashes === [] ? 0 : Order::where('partner_id', $partner->id)
            ->whereNotNull('paid_at')
            ->whereIn('partner_ip_hash', $loginHashes)
            ->count();

        $commissioned = PartnerCommission::where('partner_id', $partner->id)->where('kind', 'sale')->pluck('order_id')->unique();
        $refunded = $commissioned->isEmpty() ? 0 : Order::whereIn('id', $commissioned)->whereIn('status', ['refunded', 'partially_refunded'])->count();
        $rate = $commissioned->count() > 0 ? $refunded / $commissioned->count() : 0;

        $daily = PartnerClick::where('partner_id', $partner->id)->where('created_at', '>=', now()->subDays(30))
            ->select('day', DB::raw('count(*) as n'))->groupBy('day')->pluck('n')->map(fn ($n) => (int) $n);
        $average = $daily->isEmpty() ? 0 : $daily->sum() / 30;
        $bursts = $daily->filter(fn (int $n) => $n >= 50 && $n >= 5 * $average)->count();

        return [
            'self_purchase' => ['count' => $self, 'flag' => $self > 0, 'detail' => 'Checkouts refused because the partner was the buyer'],
            'same_ip' => ['count' => $sameIp, 'flag' => $sameIp > 0, 'detail' => 'Paid orders from an IP the partner signs in from'],
            'refund_rate' => ['count' => (int) round($rate * 100), 'flag' => $commissioned->count() >= 3 && $rate > 0.2, 'detail' => 'Percent of commissioned orders refunded'],
            'click_burst' => ['count' => $bursts, 'flag' => $bursts > 0, 'detail' => 'Days in the last 30 with a burst of visits'],
        ];
    }
}
