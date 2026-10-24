<?php

namespace App\Http\Middleware;

use App\Platform\Features;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `feature:inbox` on a route: 404 when the module is off for this person.
 *
 * Not found rather than forbidden, the way a module that does not exist
 * answers: the navigation hides it too, so nobody should reach it except
 * by an old link.
 */
class EnsureFeature
{
    public function handle(Request $request, Closure $next, string $key): Response
    {
        abort_unless(Features::enabled($key, $request->user()), 404);

        return $next($request);
    }
}
