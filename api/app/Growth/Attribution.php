<?php

namespace App\Growth;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Where an account came from: first touch.
 *
 * On a visitor's first page view (a guest, a GET, no cookie yet), the
 * CaptureAttribution middleware writes a cookie with the UTM parameters,
 * the referring site and the landing path. It is never overwritten, so a
 * later visit from somewhere else does not take the credit. Registration
 * copies it onto the account (users.signup_*).
 *
 * Only the referrer's host is kept, never its path or query (a search
 * engine's query string is the searcher's business), and only when it is
 * another site. The landing path is kept without its query string, for the
 * same reason. Everything is clipped to its column.
 *
 * The marketing site is a different domain, so this cookie cannot see a
 * visit there: links from it to /register should carry utm_* parameters.
 */
final class Attribution
{
    /** @return array{source: ?string, medium: ?string, campaign: ?string, referrer: ?string, landing: string} */
    public static function fromRequest(Request $request): array
    {
        $referrer = self::externalHost($request->headers->get('referer'), $request->getHost());
        $clean = fn (?string $v, int $len) => ($v = trim((string) $v)) === '' ? null : mb_substr(strip_tags($v), 0, $len);

        return [
            'source' => $clean($request->query('utm_source'), 100) ?? $referrer,
            'medium' => $clean($request->query('utm_medium'), 100) ?? ($referrer ? 'referral' : null),
            'campaign' => $clean($request->query('utm_campaign'), 150),
            'referrer' => $referrer,
            'landing' => mb_substr('/'.ltrim($request->path(), '/'), 0, 255),
        ];
    }

    /** Copy the cookie's first touch onto a new account. */
    public static function apply(User $user, Request $request): void
    {
        $touch = json_decode((string) $request->cookie(config('growth.attribution.cookie')), true);

        if (! is_array($touch)) {
            return;
        }

        $value = fn (string $key, int $len) => is_string($touch[$key] ?? null) && $touch[$key] !== '' ? mb_substr($touch[$key], 0, $len) : null;

        $user->forceFill([
            'signup_source' => $value('source', 100),
            'signup_medium' => $value('medium', 100),
            'signup_campaign' => $value('campaign', 150),
            'signup_referrer' => $value('referrer', 255),
            'signup_landing' => $value('landing', 255),
        ])->save();
    }

    /** The source a report groups by: the UTM source, else "Direct". */
    public static function label(?string $source): string
    {
        return $source === null || $source === '' ? 'Direct or unknown' : $source;
    }

    private static function externalHost(?string $referer, string $ownHost): ?string
    {
        $host = $referer ? parse_url($referer, PHP_URL_HOST) : null;

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower(preg_replace('/^www\./', '', $host));
        $own = strtolower(preg_replace('/^www\./', '', $ownHost));
        $app = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return $host === $own || $host === $app ? null : mb_substr($host, 0, 255);
    }
}
