<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    public const SENT = 'If an account exists for that address, we have emailed a link to reset its password.';

    /**
     * Display the password reset link request view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming password reset link request.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        Password::sendResetLink(
            $request->only('email')
        );

        // The same answer whether or not the address has an account, and
        // whether or not a link was sent a minute ago. Telling them apart
        // ("We can't find a user with that email address") turned this form
        // into a way to check who has an account here.
        return back()->with('status', self::SENT);
    }
}
