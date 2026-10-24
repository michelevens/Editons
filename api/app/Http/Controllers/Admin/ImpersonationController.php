<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Platform\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** "View as": start (from the console), stop (from the banner), and the log. */
class ImpersonationController extends Controller
{
    public function index(Request $request): Response
    {
        $sessions = ImpersonationSession::with(['impersonator:id,name', 'target:id,name,email'])
            ->when($request->query('admin'), fn ($q, $id) => $q->where('impersonator_id', (int) $id))
            ->when($request->query('target'), fn ($q, $id) => $q->where('target_id', (int) $id))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Admin/Impersonations', [
            'sessions' => $sessions->through(fn (ImpersonationSession $s) => AccountsController::sessionRow($s)),
            'filters' => ['admin' => $request->query('admin'), 'target' => $request->query('target')],
        ]);
    }

    public function start(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:8', 'max:500']]);

        if ($refusal = Impersonation::refusal($request->user(), $user)) {
            throw ValidationException::withMessages(['reason' => $refusal]);
        }

        Impersonation::start($request, $request->user(), $user, $data['reason']);

        return redirect()->route('books.index');
    }

    /** Outside /admin: the browser is signed in as the target while this runs. */
    public function stop(Request $request): RedirectResponse
    {
        if (! $request->session()->has(Impersonation::SESSION_KEY)) {
            return redirect('/');
        }

        $target = $request->user();
        $admin = Impersonation::stop($request, 'stopped');

        if (! $admin) {
            return redirect()->route('login');
        }

        return ($target ? redirect()->route('admin.accounts.show', $target) : redirect()->route('admin.dashboard'))
            ->with('platform_notice', 'Stopped viewing. You are signed in as yourself again.');
    }
}
