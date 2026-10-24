<?php

namespace App\Http\Middleware;

use App\Platform\Impersonation;
use App\Platform\PlatformSettings;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Maintenance mode from the console (MemberMD's MaintenanceGate).
 *
 * Not Laravel's `artisan down`, which needs a shell and takes the admin
 * console down with everything else. Here staff carry on (to check the fix,
 * or to switch it off again), everybody else gets a 503 page that says why,
 * and the doors that must stay open stay open: sign-in, so staff can get in;
 * the status endpoint; the health check; Stripe's webhook, so a payment
 * taken during maintenance is not lost.
 */
class MaintenanceGate
{
    private const OPEN = [
        '/', 'login', 'logout', 'two-factor-challenge', 'status', 'up',
        'stripe/*', 'admin', 'admin/*', 'impersonation/stop',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $settings = app(PlatformSettings::class);

        if (! $settings->maintenance()) {
            return $next($request);
        }

        $user = $request->user();

        if (($user && $user->isPlatformAdmin()) || Impersonation::active($request) || $request->is(...self::OPEN)) {
            return $next($request);
        }

        $message = trim((string) $settings->get('maintenance_message'))
            ?: 'Écrivain is down for maintenance. Your books are safe; please try again shortly.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'code' => 'maintenance'], 503, ['Retry-After' => '300']);
        }

        return Inertia::render('Maintenance', [
            'message' => $message,
            'signed_in' => $user !== null,
        ])->toResponse($request)->setStatusCode(503)->withHeaders(['Retry-After' => '300']);
    }
}
