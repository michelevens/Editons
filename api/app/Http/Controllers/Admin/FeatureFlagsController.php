<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeatureOverride;
use App\Models\User;
use App\Platform\Features;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Feature flags: each module's platform-wide switch and the accounts it is
 * forced on or off for. Changing either is the owner's.
 */
class FeatureFlagsController extends Controller
{
    public function index(Request $request, Features $features): Response
    {
        $globals = $features->globals();
        $setters = User::whereIn('id', array_filter(array_column($globals, 'updated_by')))->pluck('name', 'id');

        $overrides = FeatureOverride::with(['user:id,name,email', 'setter:id,name'])
            ->latest('updated_at')
            ->limit(200)
            ->get()
            ->groupBy('feature');

        return Inertia::render('Admin/Features', [
            'features' => collect(Features::catalog())->map(fn (array $f) => $f + [
                'global' => isset($globals[$f['key']]) ? $globals[$f['key']]['enabled'] : null,
                'reason' => $globals[$f['key']]['reason'] ?? null,
                'updated_at' => $globals[$f['key']]['updated_at'] ?? null,
                'updated_by' => isset($globals[$f['key']]['updated_by']) ? ($setters[$globals[$f['key']]['updated_by']] ?? null) : null,
                'effective' => $features->explain($f['key'])['enabled'],
                'overrides' => ($overrides[$f['key']] ?? collect())->map(fn (FeatureOverride $o) => [
                    'user' => ['id' => $o->user_id, 'name' => $o->user?->name, 'email' => $o->user?->email],
                    'enabled' => $o->enabled,
                    'reason' => $o->reason,
                    'by' => $o->setter?->name,
                    'at' => $o->updated_at?->toIso8601String(),
                ])->values(),
            ]),
            'can_edit' => $request->user()->isPlatformOwner(),
        ]);
    }

    public function update(Request $request, Features $features): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', Rule::in(array_keys(Features::CATALOG))],
            // on | off | default
            'state' => ['required', Rule::in(['on', 'off', 'default'])],
            'reason' => ['required', 'string', 'min:4', 'max:500'],
        ]);

        $features->setGlobal($data['key'], self::state($data['state']), $data['reason'], $request->user());

        return back()->with('platform_notice', Features::CATALOG[$data['key']]['label'].': '.$data['state'].'.');
    }

    public function override(Request $request, User $user, Features $features): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', Rule::in(array_keys(Features::CATALOG))],
            'state' => ['required', Rule::in(['on', 'off', 'default'])],
            'reason' => ['required', 'string', 'min:4', 'max:500'],
        ]);

        $features->setOverride($user, $data['key'], self::state($data['state']), $data['reason'], $request->user());

        return back()->with('platform_notice', Features::CATALOG[$data['key']]['label'].' for '.$user->email.': '.($data['state'] === 'default' ? 'override removed' : $data['state']).'.');
    }

    private static function state(string $state): ?bool
    {
        return match ($state) {
            'on' => true,
            'off' => false,
            default => null,
        };
    }
}
