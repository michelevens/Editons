<?php

namespace App\Http\Middleware;

use App\Growth\Attribution;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * The first-touch cookie (App\Growth\Attribution): set once, on a guest's
 * first page view, never overwritten. Inside the web group, so the cookie is
 * encrypted like every other.
 */
class CaptureAttribution
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $name = (string) config('growth.attribution.cookie');

        if (! $request->isMethod('GET')
            || $request->user()
            || $request->cookies->has($name)
            || $request->expectsJson()
            || $request->header('X-Inertia')
            || $request->is('admin', 'admin/*', 'build/*', 'stripe/*', 'email/*', 'status', 'up')
            || $response->getStatusCode() >= 400) {
            return $response;
        }

        $response->headers->setCookie(new Cookie(
            $name,
            json_encode(Attribution::fromRequest($request) + ['at' => now()->toIso8601String()]),
            now()->addDays((int) config('growth.attribution.days')),
            '/',
            config('session.domain'),
            (bool) config('session.secure'),
            true,
            false,
            Cookie::SAMESITE_LAX,
        ));

        return $response;
    }
}
