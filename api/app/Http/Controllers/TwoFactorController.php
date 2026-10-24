<?php

namespace App\Http\Controllers;

use App\Platform\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Two-factor sign-in, from the profile (and the admin console's set-up
 * page, which posts here too).
 *
 * Turning it on is two steps — a secret and its QR code, then a code from
 * the app to prove it was scanned — so a half-finished set-up never locks
 * anybody out. Turning it off, or making new recovery codes, needs the
 * password and a current code: somebody who has walked up to an unlocked
 * laptop has neither.
 */
class TwoFactorController extends Controller
{
    public function enable(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);

        $user = $request->user();

        if ($user->hasTwoFactor()) {
            throw ValidationException::withMessages(['password' => 'Two-factor sign-in is already on.']);
        }

        $twoFactor->begin($user);

        return back()->with('status', 'two-factor-pending');
    }

    public function confirm(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:16']]);

        $codes = $twoFactor->confirm($request->user(), $data['code']);

        if ($codes === null) {
            throw ValidationException::withMessages(['code' => 'That code is not right. Check the time on your phone, then try the newest code.']);
        }

        // Shown once, from the flash; only their HMACs are kept.
        return back()
            ->with('status', 'two-factor-enabled')
            ->with('two_factor_recovery_codes', $codes);
    }

    public function recoveryCodes(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $this->confirmIdentity($request, $twoFactor);

        return back()
            ->with('status', 'two-factor-recovery-codes')
            ->with('two_factor_recovery_codes', $twoFactor->freshRecoveryCodes($request->user()));
    }

    public function disable(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $user = $request->user();

        // An unconfirmed set-up can be abandoned with the password alone.
        if ($user->hasTwoFactor()) {
            $this->confirmIdentity($request, $twoFactor);
        } else {
            $request->validate(['password' => ['required', 'current_password']]);
        }

        $twoFactor->disable($user);

        return back()->with('status', 'two-factor-disabled');
    }

    /** @throws ValidationException */
    private function confirmIdentity(Request $request, TwoFactor $twoFactor): void
    {
        $data = $request->validate([
            'password' => ['required', 'current_password'],
            'code' => ['required', 'string', 'max:32'],
        ]);

        $user = $request->user();

        if (! $twoFactor->verify($user, $data['code']) && ! $twoFactor->useRecoveryCode($user, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code is not right.']);
        }
    }
}
