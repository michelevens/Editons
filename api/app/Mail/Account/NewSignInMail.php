<?php

namespace App\Mail\Account;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A sign-in from a device or network not seen before.
 *
 * Template only for now: the application does not remember known devices
 * (database sessions are deleted at sign-out, so "new" cannot be told apart
 * from "signed out last time"). Wire it through Notify::newSignIn() once a
 * known-devices table exists — see docs/EMAILS.md.
 */
class NewSignInMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'account';

    public function __construct(
        public User $user,
        public string $ipAddress,
        public ?string $userAgent,
        public CarbonInterface $signedInAt,
        public ?string $location = null,
    ) {}

    public function account(): ?User
    {
        return $this->user;
    }

    public function letter(): Letter
    {
        $brand = config('mail.ecrivain.brand');

        return Letter::make(__('New sign-in to your :brand account', ['brand' => $brand]))
            ->preheader(__(':device signed in from :ip. If that was you, you can ignore this.', [
                'device' => self::describeDevice($this->userAgent),
                'ip' => $this->ipAddress,
            ]))
            ->subtitle(__('New sign-in'))
            ->eyebrow(__('Security'))
            ->severity('heads')
            ->headline(__('Was this you?'))
            ->lede(self::greet($this->user->name, __('your account was just signed in to from a device we have not seen before.')))
            ->facts([
                __('Device') => self::describeDevice($this->userAgent),
                __('IP address') => $this->ipAddress,
                __('Approximate location') => $this->location,
                __('When') => $this->signedInAt->translatedFormat('F j, Y \a\t g:i A T'),
            ])
            ->cta(__('Review your account'), route('profile.edit'))
            ->secondary(__('Not you? Reset your password'), route('password.request'))
            ->callout('danger', __('Reset your password now. Changing it signs out every other session.'), __('Did not sign in?'))
            ->reason(__('Security emails cannot be switched off: they are how you find out somebody else is in your account.'));
    }

    /** "Chrome on Windows", from a user-agent string. Coarse on purpose. */
    public static function describeDevice(?string $userAgent): string
    {
        $ua = (string) $userAgent;

        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => null,
        };

        $os = match (true) {
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        return match (true) {
            $browser && $os => __(':browser on :os', ['browser' => $browser, 'os' => $os]),
            (bool) $browser => $browser,
            (bool) $os => $os,
            default => __('An unrecognised device'),
        };
    }
}
