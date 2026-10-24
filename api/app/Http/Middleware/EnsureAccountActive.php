<?php

namespace App\Http\Middleware;

use App\Platform\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Suspended accounts and revoked sessions are signed out on their next
 * request, whatever the session driver.
 *
 * A session remembers when it signed in (`platform.signed_in_at`, stamped
 * on every Login event); "sign out everywhere" and suspension set
 * users.sessions_revoked_at, and any session that began before it ends here.
 * A "View as" session is checked by GuardImpersonation instead.
 */
class EnsureAccountActive
{
    public const SIGNED_IN_AT = 'platform.signed_in_at';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $request->hasSession() || Impersonation::active($request)) {
            return $next($request);
        }

        $message = null;

        if ($user->isSuspended()) {
            $message = 'This account is suspended. If you think this is a mistake, write to support.';
        } elseif ($user->sessions_revoked_at !== null) {
            $signedInAt = (int) $request->session()->get(self::SIGNED_IN_AT, 0);

            if ($signedInAt < $user->sessions_revoked_at->getTimestamp()) {
                $message = 'You were signed out. Please sign in again.';
            }
        }

        if ($message === null) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 401);
        }

        return redirect()->route('login')->with('status', $message);
    }
}
