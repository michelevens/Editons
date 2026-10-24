<?php

namespace App\Http\Middleware;

use App\Platform\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Default-deny for /admin, on every web request (PlanDraft's EnforceRbac).
 *
 * The second layer behind the route group's `platform.admin`: a route added
 * under /admin without the group's middleware — a typo, a copied line —
 * still answers 404 to anybody who is not staff, instead of being open.
 */
class DenyAdminPaths
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('admin', 'admin/*')) {
            $user = $request->user();

            if (! $user || ! $user->isPlatformAdmin() || $user->isSuspended() || Impersonation::active($request)) {
                abort(404);
            }
        }

        return $next($request);
    }
}
