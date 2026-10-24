<?php

namespace App\Http\Controllers;

use App\Platform\Trust\StatusPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /status: whether Écrivain is up, for people and for monitors.
 *
 * A browser gets the status page; anything else (a monitor, curl, a fetch)
 * gets JSON, as before — `status` and `message` keep their meaning — with
 * the components and incidents added. /status.json is always JSON.
 *
 * Reachable during maintenance (MaintenanceGate), so the answer to "is it
 * down?" is never itself a 503.
 */
class PlatformStatusController extends Controller
{
    public function __invoke(Request $request, StatusPage $status): JsonResponse|Response
    {
        if (! $request->is('status.json') && ! $request->wantsJson() && str_contains((string) $request->header('Accept'), 'text/html')) {
            return Inertia::render('Status', ['snapshot' => $status->snapshot()]);
        }

        return response()->json($status->snapshot(), 200, ['Cache-Control' => 'no-store']);
    }
}
