<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\LoginHistory;
use App\Platform\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The second step of signing in, when two-factor is on.
 *
 * The password step left `login.id` in the session; nobody is signed in
 * until a code from the app (or an unused recovery code) matches. The
 * pending sign-in lasts five minutes, after which the password is asked for
 * again.
 */
class TwoFactorChallengeController extends Controller
{
    private const LIFETIME = 300;

    public function create(Request $request): Response|RedirectResponse
    {
        if (! $this->pending($request)) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    public function store(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $user = $this->pending($request);

        if (! $user) {
            return redirect()->route('login')->with('status', 'That sign-in took too long. Please enter your password again.');
        }

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:16'],
            'recovery_code' => ['nullable', 'string', 'max:32'],
        ]);

        $method = null;

        if (filled($data['code'] ?? null) && $twoFactor->verify($user, $data['code'])) {
            $method = 'two_factor';
        } elseif (filled($data['recovery_code'] ?? null) && $twoFactor->useRecoveryCode($user, $data['recovery_code'])) {
            $method = 'recovery_code';
        }

        if (! $method) {
            throw ValidationException::withMessages([
                filled($data['recovery_code'] ?? null) ? 'recovery_code' : 'code' => 'That code is not right. Codes change every 30 seconds.',
            ]);
        }

        $remember = (bool) $request->session()->pull('login.remember', false);
        $request->session()->forget(['login.id', 'login.at']);

        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();
        LoginHistory::record($user, $method, $request);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function pending(Request $request): ?User
    {
        $id = $request->session()->get('login.id');
        $at = (int) $request->session()->get('login.at', 0);

        if (! $id || now()->getTimestamp() - $at > self::LIFETIME) {
            $request->session()->forget(['login.id', 'login.at', 'login.remember']);

            return null;
        }

        $user = User::find($id);

        return $user && $user->hasTwoFactor() && ! $user->isSuspended() ? $user : null;
    }
}
