<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BookExport;
use App\Platform\Ops\FailedJobs;
use App\Platform\Ops\Health;
use App\Platform\Ops\ScheduledJobs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Jobs, queue and health: the scheduled commands and their heartbeats, the
 * queue backlog, failed jobs, exports a dead worker left generating, and
 * health checks that say "unknown" when they could not measure.
 */
class OperationsController extends Controller
{
    public function index(Request $request, ScheduledJobs $jobs, FailedJobs $failed, Health $health): Response
    {
        return Inertia::render('Admin/Operations', [
            'health' => $health->checks(),
            'jobs' => $jobs->all(),
            'backlog' => $failed->backlog(),
            'failed' => $failed->recent(),
            'failed_total' => $failed->count(),
            'stuck_exports' => $failed->stuckExports(),
            'can' => ['owner' => $request->user()->isPlatformOwner()],
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    public function retry(Request $request, string $uuid, FailedJobs $failed): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:4', 'max:500']]);
        $failed->retry($uuid, $request->user(), $data['reason']);

        return back()->with('platform_notice', 'Put back on the queue.');
    }

    public function forget(Request $request, string $uuid, FailedJobs $failed): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:4', 'max:500']]);
        $failed->forget($uuid, $request->user(), $data['reason']);

        return back()->with('platform_notice', 'Failed job forgotten.');
    }

    public function failExport(Request $request, BookExport $export, FailedJobs $failed): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:4', 'max:500']]);
        $failed->markExportFailed($export, $request->user(), $data['reason']);

        return back()->with('platform_notice', 'Export marked failed; its author can generate it again.');
    }
}
