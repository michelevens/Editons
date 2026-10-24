<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Ai\AiControls;
use App\Platform\Ai\AiUsageReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * AI usage, cost and the kill switches.
 *
 * Read by all staff; switching AI off or on, and setting an account's
 * monthly cap, is the owner's (can:platform.owner on the routes).
 */
class AiUsageController extends Controller
{
    public function index(Request $request, AiUsageReport $report, AiControls $controls): Response
    {
        $switches = collect($controls->all())->map(fn (array $row, string $key) => $row + [
            'key' => $key,
            'label' => AiControls::label($key),
            'updated_by_name' => $row['updated_by'] ? User::whereKey($row['updated_by'])->value('name') : null,
        ])->values();

        return Inertia::render('Admin/Ai', [
            'windows' => $report->windows(),
            'by_feature' => $report->by('feature'),
            'by_model' => $report->by('model'),
            'top_accounts' => $report->topAccounts(),
            'failures' => $report->recentFailures(),
            'switches' => $switches,
            'default_cap' => config('platform.ai.default_monthly_cap_usd'),
            'priced_models' => collect(config('platform.ai.pricing'))->map(fn ($models) => array_keys($models))->all(),
            'can_edit' => $request->user()->isPlatformOwner(),
        ]);
    }

    public function update(Request $request, AiControls $controls): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string'],
            'paused' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:4', 'max:500'],
        ]);

        $controls->set($data['key'], (bool) $data['paused'], $data['reason'], $request->user());

        return back()->with('platform_notice', AiControls::label($data['key']).($data['paused'] ? ' paused.' : ' back on.'));
    }

    public function cap(Request $request, User $user, AiControls $controls): RedirectResponse
    {
        $data = $request->validate([
            'cap' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'reason' => ['required', 'string', 'min:4', 'max:500'],
        ]);

        $cap = $data['cap'] === null || $data['cap'] === '' ? null : round((float) $data['cap'], 2);
        $controls->setCap($user, $cap, $data['reason'], $request->user());

        return back()->with('platform_notice', $cap === null ? 'Monthly AI cap removed.' : 'Monthly AI cap set to $'.number_format($cap, 2).'.');
    }
}
