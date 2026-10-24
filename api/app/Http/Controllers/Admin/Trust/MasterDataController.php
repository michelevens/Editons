<?php

namespace App\Http\Controllers\Admin\Trust;

use App\Http\Controllers\Controller;
use App\Platform\Trust\MasterData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Owner-edited lists (App\Platform\Trust\MasterData). Read by all staff. */
class MasterDataController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Admin/Trust/MasterData', MasterData::overview() + [
            'can' => ['owner' => $request->user()->isPlatformOwner()],
        ]);
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array'],
            'items.*' => ['nullable', 'string', 'max:255'],
            'reason' => ['required', 'string', 'min:4', 'max:500'],
        ]);

        MasterData::set($key, $data['items'], $data['reason'], $request->user());

        return back()->with('platform_notice', 'List saved.');
    }

    public function reset(Request $request, string $key): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:4', 'max:500']]);

        MasterData::reset($key, $data['reason'], $request->user());

        return back()->with('platform_notice', 'List back to its original entries.');
    }
}
