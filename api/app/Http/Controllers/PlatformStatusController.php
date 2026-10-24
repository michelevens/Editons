<?php

namespace App\Http\Controllers;

use App\Platform\PlatformSettings;
use Illuminate\Http\JsonResponse;

/**
 * GET /status: whether Écrivain is up, for people and monitors.
 *
 * Reachable during maintenance (MaintenanceGate), so the answer to "is it
 * down?" is never itself a 503.
 */
class PlatformStatusController extends Controller
{
    public function __invoke(PlatformSettings $settings): JsonResponse
    {
        $maintenance = $settings->maintenance();

        return response()->json([
            'status' => $maintenance ? 'maintenance' : 'ok',
            'message' => $maintenance ? (trim((string) $settings->get('maintenance_message')) ?: null) : null,
        ]);
    }
}
