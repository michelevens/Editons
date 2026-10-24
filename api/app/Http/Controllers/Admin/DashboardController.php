<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Platform\PlatformMetrics;
use App\Platform\PlatformSettings;
use Inertia\Inertia;
use Inertia\Response;

/** The console's first page: the platform at a glance. */
class DashboardController extends Controller
{
    public function show(PlatformMetrics $metrics, PlatformSettings $settings): Response
    {
        return Inertia::render('Admin/Dashboard', [
            'live' => $metrics->live(),
            'signups' => $metrics->signups(30),
            'trend' => $metrics->trend(30),
            'maintenance' => $settings->maintenance(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
