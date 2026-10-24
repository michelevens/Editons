<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Platform\PlatformSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Platform settings: maintenance, the announcement, sign-ups, staff 2FA. */
class SettingsController extends Controller
{
    public function show(Request $request, PlatformSettings $settings): Response
    {
        return Inertia::render('Admin/Settings', [
            'settings' => $settings->all(),
            'can_edit' => $request->user()->isPlatformOwner(),
            'has_two_factor' => $request->user()->hasTwoFactor(),
        ]);
    }

    public function update(Request $request, PlatformSettings $settings): RedirectResponse
    {
        $input = $request->validate([
            'settings' => ['required', 'array'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $changed = $settings->update($input['settings'], $request->user(), $input['reason'] ?? null);

        return back()->with('platform_notice', $changed['after'] === []
            ? 'Nothing changed.'
            : 'Saved: '.implode(', ', array_map(fn ($key) => str_replace('_', ' ', $key), array_keys($changed['after']))).'.');
    }
}
