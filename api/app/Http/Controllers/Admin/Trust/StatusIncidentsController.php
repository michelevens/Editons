<?php

namespace App\Http\Controllers\Admin\Trust;

use App\Http\Controllers\Controller;
use App\Models\StatusIncident;
use App\Platform\Trust\StatusPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The status page from the inside: live signals and incident management. */
class StatusIncidentsController extends Controller
{
    public function index(StatusPage $status): Response
    {
        return Inertia::render('Admin/Trust/Status', [
            'snapshot' => $status->snapshot(),
            'incidents' => StatusIncident::with('updates')->latest('started_at')->limit(30)->get()
                ->map(fn (StatusIncident $i) => StatusPage::present($i))->values(),
            'components' => StatusPage::COMPONENTS,
            'severities' => StatusIncident::SEVERITIES,
            'statuses' => collect(StatusIncident::STATUSES)->mapWithKeys(fn ($s) => [$s => StatusPage::statusLabel($s)]),
            'public_url' => route('platform.status'),
        ]);
    }

    public function store(Request $request, StatusPage $status): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'severity' => ['required', 'string'],
            'status' => ['required', 'string'],
            'components' => ['required', 'array', 'min:1'],
            'components.*' => ['string'],
            'body' => ['required', 'string', 'max:5000'],
            'show_in_banner' => ['boolean'],
        ]);

        $status->open($data, $request->user());

        return back()->with('platform_notice', 'Incident posted on the status page.');
    }

    public function update(Request $request, StatusIncident $incident, StatusPage $status): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string'],
            'body' => ['required', 'string', 'max:5000'],
            'severity' => ['nullable', 'string'],
            'components' => ['nullable', 'array'],
            'components.*' => ['string'],
            'show_in_banner' => ['nullable', 'boolean'],
        ]);

        $status->post($incident, $data['status'], $data['body'], $request->user(), array_filter(
            $data,
            fn ($value, $key) => in_array($key, ['severity', 'components', 'show_in_banner'], true) && $value !== null,
            ARRAY_FILTER_USE_BOTH,
        ));

        return back()->with('platform_notice', $data['status'] === 'resolved' ? 'Incident resolved.' : 'Update posted.');
    }
}
