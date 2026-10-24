<?php

namespace App\Platform;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Two-factor sign-in with an authenticator app (TOTP, RFC 6238).
 *
 * pragmarx/google2fa does the arithmetic and bacon/bacon-qr-code draws the
 * enrolment QR as SVG — the pair Laravel Fortify itself is built on, both
 * maintained, neither with a network call. Fortify was not adopted: it
 * replaces the auth routes Breeze already provides here, which would be a
 * second sign-in implementation to keep in step.
 *
 * Storage: the secret is encrypted with APP_KEY (the model's `encrypted`
 * cast). Recovery codes are kept as HMACs inside an encrypted array, so a
 * leaked database plus a leaked key still does not yield a usable code.
 * Each accepted code's 30-second step is remembered and a code for that step
 * or earlier is refused, so a code read over a shoulder cannot be replayed.
 */
final class TwoFactor
{
    public const RECOVERY_CODES = 8;

    private Google2FA $engine;

    public function __construct()
    {
        $this->engine = new Google2FA;
    }

    /** Begin enrolment: a new, unconfirmed secret. */
    public function begin(User $user): string
    {
        $secret = $this->engine->generateSecretKey(32);

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_last_step' => null,
        ])->save();

        return $secret;
    }

    /** The otpauth:// address an authenticator app reads from the QR code. */
    public function otpauthUrl(User $user): string
    {
        return $this->engine->getQRCodeUrl(
            (string) config('app.name', 'Ecrivain'),
            $user->email,
            (string) $user->two_factor_secret,
        );
    }

    /** The enrolment QR code as an inline SVG. */
    public function qrSvg(User $user): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd));

        return $writer->writeString($this->otpauthUrl($user));
    }

    /**
     * Check a six-digit code against the account's secret.
     *
     * One step either side (±30 s) for clock drift. On success the step is
     * recorded so the same code cannot be used again.
     */
    public function verify(User $user, ?string $code): bool
    {
        $code = preg_replace('/\s+/', '', (string) $code);

        if (! $user->two_factor_secret || ! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $step = $this->engine->verifyKeyNewer(
            $user->two_factor_secret,
            $code,
            // 0 rather than null: with null the library answers true/false
            // instead of the step that matched.
            $user->two_factor_last_step ?? 0,
            1,
        );

        if (! is_int($step)) {
            return false;
        }

        $user->forceFill(['two_factor_last_step' => $step])->save();

        return true;
    }

    /**
     * Finish enrolment with a code from the app. Returns the recovery codes,
     * in plain text, for showing once; only their HMACs are kept.
     *
     * @return list<string>|null null when the code is wrong
     */
    public function confirm(User $user, ?string $code): ?array
    {
        if ($user->two_factor_confirmed_at !== null || ! $this->verify($user, $code)) {
            return null;
        }

        $codes = $this->freshRecoveryCodes($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $codes;
    }

    /**
     * Replace the recovery codes.
     *
     * @return list<string>
     */
    public function freshRecoveryCodes(User $user): array
    {
        $codes = [];

        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $codes[] = Str::lower(Str::random(5).'-'.Str::random(5));
        }

        $user->forceFill([
            'two_factor_recovery_codes' => array_map(fn ($code) => self::digest($code), $codes),
        ])->save();

        return $codes;
    }

    /** Use one recovery code. Each works once. */
    public function useRecoveryCode(User $user, ?string $code): bool
    {
        $code = Str::lower(trim((string) $code));
        $stored = $user->two_factor_recovery_codes ?? [];

        if ($code === '' || $stored === []) {
            return false;
        }

        $digest = self::digest($code);

        foreach ($stored as $index => $candidate) {
            if (hash_equals($candidate, $digest)) {
                unset($stored[$index]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($stored)])->save();

                return true;
            }
        }

        return false;
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return count($user->two_factor_recovery_codes ?? []);
    }

    /** Switch it off and forget everything about it. */
    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();
    }

    /**
     * What the profile (and the console's set-up page) shows: on, half set
     * up with its QR code, or off; and the recovery codes once, right after
     * they were made.
     *
     * @return array<string, mixed>
     */
    public function state(User $user): array
    {
        $pending = $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null;

        return [
            'enabled' => $user->hasTwoFactor(),
            'pending' => $pending,
            'qr_svg' => $pending ? $this->qrSvg($user) : null,
            // The secret typed by hand, for an app that cannot scan.
            'secret' => $pending ? $user->two_factor_secret : null,
            'confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
            'recovery_remaining' => $this->remainingRecoveryCodes($user),
            'recovery_codes' => session('two_factor_recovery_codes'),
            'required' => $user->isPlatformAdmin(),
        ];
    }

    /** A code for the current step. For tests and the local console only. */
    public function currentCode(User $user): string
    {
        return $this->engine->getCurrentOtp((string) $user->two_factor_secret);
    }

    private static function digest(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
