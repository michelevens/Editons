<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ErrorGroup;
use App\Platform\Ops\ErrorGroups;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Error groups: server exceptions and browser errors, grouped, counted, resolved. */
class ErrorsController extends Controller
{
    public function index(Request $request): Response
    {
        $status = (string) $request->query('status', 'open');
        $source = (string) $request->query('source', '');

        $groups = ErrorGroup::query()
            ->when(in_array($status, ['open', 'resolved', 'ignored'], true), fn ($q) => $q->where('status', $status))
            ->when(in_array($source, ['server', 'client'], true), fn ($q) => $q->where('source', $source))
            ->orderByDesc('last_seen_at')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Admin/Errors', [
            'status' => $status,
            'source' => $source,
            'groups' => $groups->through(fn (ErrorGroup $g) => [
                'id' => $g->id,
                'source' => $g->source,
                'type' => $g->type,
                'message' => $g->message,
                'location' => $g->location,
                'trace' => $g->example_trace,
                'url' => $g->example_url,
                'user_id' => $g->example_user_id,
                'count' => $g->count,
                'status' => $g->status,
                'first_seen_at' => $g->first_seen_at?->toIso8601String(),
                'last_seen_at' => $g->last_seen_at?->toIso8601String(),
            ]),
        ]);
    }

    public function update(Request $request, ErrorGroup $group): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        ErrorGroups::setStatus($group, $data['status'], $request->user(), $data['reason'] ?? null);

        return back()->with('platform_notice', 'Marked '.$data['status'].'.');
    }
}
