<?php

namespace App\Platform;

use App\Models\User;
use App\Models\UserLogin;
use Illuminate\Http\Request;

/**
 * Sign-ins, recorded where they happen: the password form, the two-factor
 * challenge, registration and "View as". A session restored from the
 * remember-me cookie is not a sign-in and is not recorded.
 *
 * Shown to platform staff on the account page; a "View as" session appears
 * there as "Viewed by Ecrivain support", so the history never pretends the
 * author signed in when somebody else did.
 */
final class LoginHistory
{
    public const METHODS = ['password', 'two_factor', 'recovery_code', 'registration', 'impersonation'];

    public static function record(User $user, string $method, Request $request, ?User $impersonator = null): UserLogin
    {
        return UserLogin::create([
            'user_id' => $user->id,
            'method' => $method,
            'impersonated' => $impersonator !== null,
            'impersonator_id' => $impersonator?->id,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
