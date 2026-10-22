<?php

namespace App\Http;

/**
 * The hops between a browser and this app, and which of them may be believed.
 *
 * A request reaches Laravel through Cloudflare (the edge Worker in edge/),
 * then Railway's edge, then a private hop inside Railway. Each appends the
 * address it received the request from to X-Forwarded-For, so the header
 * reads "client, cloudflare, …" — but only the right-hand end of it was
 * written by somebody we trust. Anything to the left of the client is
 * whatever the client chose to send.
 *
 * Trusting '*' made Symfony skip every entry and return the left-most one,
 * so request()->ip() was a value the caller wrote. That is harmless for a
 * log line and useless for a rate limit: a fresh X-Forwarded-For per request
 * was a fresh IP per request.
 *
 * Trusting exactly the hops below makes ip() the first address, from the
 * right, that is not one of ours: the real client when the request came
 * through Cloudflare, and the real caller when somebody hits the Railway
 * hostname directly. REMOTE_ADDR stays trusted, so the forwarded scheme and
 * host are honoured exactly as before.
 */
final class TrustedProxies
{
    /**
     * Cloudflare's published ranges (https://www.cloudflare.com/ips/).
     *
     * They change rarely and with notice; a range missing from here only
     * makes ip() report a Cloudflare address for that traffic, which is what
     * it did for all traffic before.
     */
    public const CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /** @return array<int, string> */
    public static function all(): array
    {
        // REMOTE_ADDR and PRIVATE_SUBNETS are placeholders Laravel and
        // Symfony expand per request: the peer that actually connected, and
        // Railway's internal network (Symfony's list includes the 100.64/10
        // shared space as well as RFC 1918).
        return ['REMOTE_ADDR', 'PRIVATE_SUBNETS', ...self::CLOUDFLARE];
    }
}
