<?php

namespace App\Http\Middleware;

use App\Models\ImpersonationSession;
use App\Models\User;
use App\Platform\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every request of a "View as" session (App\Platform\Impersonation).
 *
 *  - The session must still be live, its admin still staff and not
 *    suspended, and the browser still signed in as its target; otherwise it
 *    ends here and the admin is put back (fail closed).
 *  - Anything on the BLOCKED list is refused with 403 and a sentence saying
 *    why, for the whole session — one list, not a check per controller.
 */
class GuardImpersonation
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        $state = $request->session()->get(Impersonation::SESSION_KEY);

        if (! is_array($state)) {
            return $next($request);
        }

        // Stopping is always allowed, and does its own checks.
        if ($request->is('impersonation/stop')) {
            return $next($request);
        }

        $row = ImpersonationSession::find($state['session_id'] ?? 0);
        $admin = $row ? User::find($row->impersonator_id) : null;

        $live = $row
            && $row->isLive()
            && $admin
            && $admin->isPlatformAdmin()
            && ! $admin->isSuspended()
            && (int) $request->user()?->id === (int) $row->target_id
            && ! $request->user()->isSuspended();

        if (! $live) {
            $expired = $row && $row->ended_at === null && $row->expires_at->isPast();
            $target = $row?->target;
            $restored = Impersonation::stop($request, $expired ? 'expired' : 'revoked');

            $message = $expired
                ? 'Viewing as '.($target?->name ?? 'that account').' ended: the '.Impersonation::minutes().'-minute limit was reached.'
                : 'Viewing as '.($target?->name ?? 'that account').' ended.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message, 'code' => 'impersonation_ended'], 409);
            }

            return ($restored && $target
                ? redirect()->route('admin.accounts.show', $target)
                : redirect()->route('login'))
                ->with('platform_notice', $message);
        }

        if ($label = Impersonation::blockedAction($request)) {
            $message = "Not available while viewing as somebody ({$label}). Stop viewing, and ask the author to do this from their own account.";

            if ($request->expectsJson()) {
                return response()->json(['message' => $message, 'code' => 'not_while_impersonating'], 403);
            }

            abort(403, $message);
        }

        return $next($request);
    }
}
