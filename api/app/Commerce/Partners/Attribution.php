<?php

namespace App\Commerce\Partners;

use App\Models\DiscountCode;
use App\Models\Order;
use App\Models\Partner;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;

/**
 * Which partner a sale belongs to, and whether a commission is due.
 *
 * A visit through a partner's page (/p/{handle}), a book link
 * (/p/{handle}/{product}) or any store URL with ?partner={code} sets a
 * first-party cookie for `cookie_days` (30). Last click wins: a later visit
 * through another partner's link takes the credit. A discount code staff
 * attached to a partner attributes the sale without any cookie — a flyer in
 * Port-au-Prince or a WhatsApp message is not a click — and beats one.
 *
 * The attribution is written on the order when checkout starts, with the
 * reason when no commission is due:
 *
 *   self_purchase   the buyer is the partner (account or email)
 *   seller          the partner is the book's own seller
 *   suspended       the partner is suspended (or no longer approved)
 *   not_opted_in    the seller has not let partners sell this book
 *   blocked         the seller blocked this partner
 *   not_permitted   an invited partner, and not this seller's book
 *
 * Clicks are counted once per visitor per partner per day, from an HMAC of
 * the IP and user agent (never the raw values), and not at all for user
 * agents that say they are robots or link previews.
 */
final class Attribution
{
    public const REFUSALS = ['self_purchase', 'seller', 'suspended', 'not_opted_in', 'blocked', 'not_permitted'];

    private const BOTS = '/bot|crawl|spider|slurp|preview|facebookexternalhit|whatsapp|telegram|discord|embedly|curl|wget|python-requests|httpclient|scrapy|headlesschrome|lighthouse|monitor/i';

    public function __construct(private readonly Program $program) {}

    /**
     * Somebody followed a partner's link: count the visit (once a day) and
     * give the partner the credit for the next 30 days.
     */
    public function click(Request $request, Partner $partner, string $source, ?Product $product = null): void
    {
        // Never on staff previews or the partner looking at their own page.
        if (! $partner->isApproved() || $request->user()?->id === $partner->user_id) {
            return;
        }

        Cookie::queue(cookie(
            (string) config('partners.cookie'),
            json_encode(['p' => $partner->id, 'at' => now()->toIso8601String(), 'via' => $source]),
            $this->program->get('cookie_days') * 24 * 60,
            '/',
            config('session.domain'),
            (bool) config('session.secure'),
            true,
            false,
            'lax',
        ));

        $agent = (string) $request->userAgent();

        if ($agent === '' || preg_match(self::BOTS, $agent)) {
            return;
        }

        DB::table('partner_clicks')->insertOrIgnore([
            'partner_id' => $partner->id,
            'product_id' => $product?->id,
            'visitor_hash' => self::hash($request->ip().'|'.$agent),
            'ip_hash' => self::hash((string) $request->ip()),
            'ua_hash' => hash('sha256', $agent),
            'day' => now()->toDateString(),
            'source' => $source,
            'created_at' => now(),
        ]);
    }

    /** The partner in this browser's cookie, if it still exists. */
    public function fromCookie(Request $request): ?Partner
    {
        $value = json_decode((string) $request->cookie((string) config('partners.cookie')), true);

        if (! is_array($value) || ! is_int($value['p'] ?? null)) {
            return null;
        }

        // The cookie's own date, so a shorter setting applies to old cookies.
        if (isset($value['at']) && strtotime((string) $value['at']) < now()->subDays($this->program->get('cookie_days'))->getTimestamp()) {
            return null;
        }

        return Partner::with('user')->find($value['p']);
    }

    /**
     * Who gets the credit for a checkout that is starting, and whether a
     * commission is due. A code beats the cookie.
     *
     * @return array{partner: ?Partner, source: ?string, refusal: ?string, rate_bps: ?int}
     */
    public function resolve(Request $request, Product $product, ?DiscountCode $code = null, ?User $buyer = null, ?string $email = null): array
    {
        $none = ['partner' => null, 'source' => null, 'refusal' => null, 'rate_bps' => null];

        if (! Program::enabled()) {
            return $none;
        }

        $partner = null;
        $source = null;

        if ($code?->partner_id && ($partner = Partner::with('user')->find($code->partner_id))) {
            $source = 'code';
        } elseif ($partner = $this->fromCookie($request)) {
            $source = 'cookie';
        }

        if (! $partner) {
            return $none;
        }

        $refusal = self::refusal($partner, $product, $buyer, $email);

        return [
            'partner' => $partner,
            'source' => $source,
            'refusal' => $refusal,
            'rate_bps' => $refusal ? null : (int) $product->partner_rate_bps,
        ];
    }

    /** Why no commission is due on this book for this buyer, or null. */
    public static function refusal(Partner $partner, Product $product, ?User $buyer, ?string $email): ?string
    {
        $partner->loadMissing('user');

        if (! $partner->isApproved()) {
            return 'suspended';
        }

        if ($partner->user_id === $product->user_id) {
            return 'seller';
        }

        if (($buyer && $buyer->id === $partner->user_id) || self::sameEmail($email ?? $buyer?->email, $partner)) {
            return 'self_purchase';
        }

        if (! $product->partner_enabled || ! $product->partner_rate_bps) {
            return 'not_opted_in';
        }

        if (DB::table('partner_blocks')->where('seller_id', $product->user_id)->where('partner_id', $partner->id)->exists()) {
            return 'blocked';
        }

        if ($partner->restricted && ! $partner->sellers()->whereKey($product->user_id)->exists()) {
            return 'not_permitted';
        }

        return null;
    }

    /** The order is being paid: still a commission? (The buyer's real email is known now.) */
    public static function refusalAtPayment(?Partner $partner, Order $order): ?string
    {
        if (! $partner || ! $partner->isApproved()) {
            return 'suspended';
        }

        if (($order->buyer_user_id && $order->buyer_user_id === $partner->user_id) || self::sameEmail($order->buyer_email, $partner)) {
            return 'self_purchase';
        }

        return null;
    }

    /** An HMAC with the app key: comparable, not reversible. */
    public static function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private static function sameEmail(?string $email, Partner $partner): bool
    {
        return $email !== null && $partner->user && strcasecmp(trim($email), trim($partner->user->email)) === 0;
    }
}
