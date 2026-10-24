<?php

namespace App\Http\Controllers\Admin\Trust;

use App\Http\Controllers\Controller;
use App\Models\DataHealthRun;
use App\Platform\Trust\DataHealth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Data health: the latest stored run of the checks (daily, and on demand),
 * with drill-down rows, and the fixes that are safe — dry run first.
 */
class DataHealthController extends Controller
{
    public function index(Request $request): Response
    {
        $latest = DataHealthRun::latest('started_at')->latest('id')->with('runner:id,name')->first();

        return Inertia::render('Admin/Trust/DataHealth', [
            'run' => $latest ? [
                'id' => $latest->id,
                'trigger' => $latest->trigger,
                'by' => $latest->runner?->name,
                'started_at' => $latest->started_at?->toIso8601String(),
                'seconds' => $latest->finished_at ? round($latest->started_at->diffInMilliseconds($latest->finished_at) / 1000, 1) : null,
                'results' => array_values($latest->results ?? []),
            ] : null,
            'history' => DataHealthRun::latest('started_at')->limit(14)->get()->map(fn (DataHealthRun $r) => [
                'id' => $r->id,
                'trigger' => $r->trigger,
                'started_at' => $r->started_at?->toIso8601String(),
                'problems' => collect($r->results ?? [])->filter(fn ($c) => in_array($c['status'] ?? null, ['warning', 'failing'], true))->count(),
                'unknown' => collect($r->results ?? [])->where('status', 'unknown')->count(),
            ]),
            'preview' => $request->session()->get('data_health_preview'),
            'can' => ['owner' => $request->user()->isPlatformOwner()],
        ]);
    }

    public function run(Request $request, DataHealth $health): RedirectResponse
    {
        $health->run('manual', $request->user());

        return back()->with('platform_notice', 'Checks run.');
    }

    public function fix(Request $request, string $check, DataHealth $health): RedirectResponse
    {
        $data = $request->validate([
            'dry_run' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $health->fix($check, $request->user(), (bool) $data['dry_run'], $data['reason'] ?? null);

        if ($result['dry_run']) {
            return back()->with('data_health_preview', ['check' => $check, 'changed' => $result['changed'], 'ids' => array_slice($result['ids'], 0, 50)]);
        }

        // The stored run is now out of date for this check: refresh it.
        $health->run('manual', $request->user());

        return back()->with('platform_notice', "Fixed: {$result['changed']} changed.");
    }
}
