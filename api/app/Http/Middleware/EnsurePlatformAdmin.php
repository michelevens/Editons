<?php

namespace App\Http\Middleware;

use App\Platform\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `platform.admin`: the admin console's route group.
 *
 * 404 rather than 403 for everybody else, so the console's existence and
 * its paths are not confirmed to somebody probing for them. A "View as"
 * session is never staff, even though a member of staff is behind it.
 */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isPlatformAdmin() || $user->isSuspended() || Impersonation::active($request)) {
            abort(404);
        }

        return $next($request);
    }
}
