<?php

namespace App\Platform\Trust;

use App\Models\LoginFailure;
use App\Models\User;
use App\Models\UserLogin;
use Illuminate\Http\Request;
use Throwable;

/**
 * Sign-in security: failed attempts, lockouts, and where a sign-in came from.
 *
 * Successes are user_logins (App\Platform\LoginHistory). Failures are kept
 * here with an HMAC of the address typed, never the address: the table must
 * not become a list of everybody's typos, or of the addresses an attacker
 * tried. The account is linked when the address has one, for staff only.
 *
 * "New location" is decided per account against its earlier sign-ins (View
 * as excluded): by country when the edge says one (Cloudflare's
 * CF-IPCountry, which our Worker passes through), otherwise by network —
 * the IPv4 /16 or IPv6 /32. The account's first sign-in is never "new":
 * there is nothing to compare it with. The console says which basis it used.
 *
 * Recording never stops a sign-in: a failure here is reported and dropped.
 */
final class SignInEvents
{
    public const KINDS = ['password', 'two_factor', 'lockout'];

    public static function emailHash(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), (string) config('app.key'));
    }

    public static function failed(string $kind, ?string $email, ?User $user, Request $request): void
    {
        try {
            $hash = filled($email) ? self::emailHash((string) $email) : null;

            // A locked-out form is refused on every try; one row a minute
            // per address and IP says the same thing.
            if ($kind === 'lockout' && LoginFailure::where('kind', 'lockout')
                ->where('email_hash', $hash)
                ->where('ip', $request->ip())
                ->where('created_at', '>=', now()->subMinute())
                ->exists()) {
                return;
            }

            LoginFailure::create([
                'email_hash' => $hash,
                'user_id' => $user?->id,
                'kind' => $kind,
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            ]);
        } catch (Throwable $e) {
            if (app()->runningUnitTests()) {
                throw $e;
            }

            report($e);
        }
    }

    /** "203.0.113.0/16"-style: the network a sign-in came from. */
    public static function network(?string $ip): ?string
    {
        if (! $ip || ($packed = @inet_pton($ip)) === false) {
            return null;
        }

        if (strlen($packed) === 4) {
            $parts = explode('.', $ip);

            return "{$parts[0]}.{$parts[1]}.0.0/16";
        }

        $hex = bin2hex(substr($packed, 0, 4));

        return substr($hex, 0, 4).':'.substr($hex, 4, 4).'::/32';
    }

    /** The two-letter country the edge reported, or null. */
    public static function country(Request $request): ?string
    {
        $code = strtoupper(trim((string) $request->header('CF-IPCountry')));

        // XX: unknown; T1: Tor. Neither is a place.
        return preg_match('/^[A-Z]{2}$/', $code) && ! in_array($code, ['XX', 'T1'], true) ? $code : null;
    }

    /**
     * Where this sign-in is from, and whether that is new for the account.
     *
     * @return array{network: ?string, country: ?string, new_location: bool}
     */
    public static function locate(User $user, Request $request, bool $impersonated): array
    {
        $network = self::network($request->ip());
        $country = self::country($request);

        if ($impersonated) {
            return ['network' => $network, 'country' => $country, 'new_location' => false];
        }

        try {
            $earlier = UserLogin::where('user_id', $user->id)
                ->where('impersonated', false)
                ->latest('id')
                ->limit(500)
                ->get(['ip', 'network', 'country']);

            if ($earlier->isEmpty()) {
                return ['network' => $network, 'country' => $country, 'new_location' => false];
            }

            $countries = $earlier->pluck('country')->filter()->unique();

            if ($country && $countries->isNotEmpty()) {
                $new = ! $countries->contains($country);
            } else {
                // Rows from before networks were stored are worked out here.
                $new = $network !== null && ! $earlier
                    ->map(fn ($row) => $row->network ?? self::network($row->ip))
                    ->filter()
                    ->contains($network);
            }

            return ['network' => $network, 'country' => $country, 'new_location' => $new];
        } catch (Throwable $e) {
            if (app()->runningUnitTests()) {
                throw $e;
            }

            report($e);

            return ['network' => $network, 'country' => $country, 'new_location' => false];
        }
    }
}
