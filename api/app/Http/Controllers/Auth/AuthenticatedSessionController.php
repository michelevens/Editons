<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Platform\Impersonation;
use App\Platform\LoginHistory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $user = $request->authenticate();

        // Two-factor on: the password was right, but nobody is signed in
        // until the code is. The pending sign-in lives in the session for
        // five minutes (TwoFactorChallengeController).
        if ($user->hasTwoFactor()) {
            $request->session()->regenerate();
            $request->session()->put('login.id', $user->id);
            $request->session()->put('login.remember', $request->boolean('remember'));
            $request->session()->put('login.at', now()->getTimestamp());

            return redirect()->route('two-factor.challenge');
        }

        Auth::guard('web')->login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        LoginHistory::record($user, 'password', $request);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        // Signing out of a "View as" session ends the viewing and puts the
        // admin back, rather than signing the admin out as well.
        if ($request->session()->has(Impersonation::SESSION_KEY)) {
            $target = $request->user();
            $admin = Impersonation::stop($request, 'signed_out');

            return $admin && $target
                ? redirect()->route('admin.accounts.show', $target)
                : redirect('/');
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
