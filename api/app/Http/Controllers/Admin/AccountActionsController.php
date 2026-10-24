<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminNote;
use App\Models\User;
use App\Platform\AccountAdmin;
use App\Platform\AdminAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Changes to one account from the console.
 *
 * Every one needs a reason, which goes into the audit log with the change
 * (App\Platform\AccountAdmin writes both in one transaction). Owner-only
 * routes are gated in routes/admin.php; amounts are checked in AccountAdmin.
 */
class AccountActionsController extends Controller
{
    private const REASON = ['required', 'string', 'min:4', 'max:500'];

    public function __construct(private readonly AccountAdmin $admin) {}

    public function suspend(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['reason' => self::REASON]);
        $this->admin->suspend($user, $data['reason'], $request->user());

        return $this->done('Suspended. Every session of this account has ended.');
    }

    public function reactivate(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['reason' => self::REASON]);
        $this->admin->reactivate($user, $data['reason'], $request->user());

        return $this->done('Reactivated. They can sign in again.');
    }

    public function signOut(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['reason' => self::REASON]);
        $this->admin->signOutEverywhere($user, $data['reason'], $request->user());

        return $this->done('Signed out on every device.');
    }

    public function resetTwoFactor(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['reason' => self::REASON]);
        $this->admin->resetTwoFactor($user, $data['reason'], $request->user());

        return $this->done('Two-factor sign-in is off for this account, and its sessions have ended.');
    }

    public function passwordReset(Request $request, User $user): RedirectResponse
    {
        $this->admin->sendPasswordReset($user, $request->user());

        return $this->done("A reset link was sent to {$user->email}.");
    }

    public function plan(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:set,clear'],
            'plan' => ['required_if:action,set', 'nullable', 'in:author,studio'],
            'until' => ['nullable', 'date'],
            'reason' => self::REASON,
        ]);

        if ($data['action'] === 'clear') {
            $this->admin->clearComplimentary($user, $data['reason'], $request->user());

            return $this->done('Complimentary plan ended. The account is on Free.');
        }

        $until = filled($data['until'] ?? null) ? Carbon::parse($data['until'])->endOfDay() : null;
        $this->admin->setComplimentary($user, $data['plan'], $until, $data['reason'], $request->user());

        return $this->done('Complimentary '.ucfirst($data['plan']).' plan '.($until ? 'until '.$until->toFormattedDateString() : 'with no end date').'.');
    }

    public function credits(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'between:-1000000,1000000'],
            'reason' => self::REASON,
        ]);

        $row = $this->admin->adjustCredits($user, (int) $data['amount'], $data['reason'], $request->user());

        return $this->done(($row->amount > 0 ? 'Added ' : 'Took away ').number_format(abs($row->amount)).' credits.');
    }

    public function role(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['nullable', 'in:support'],
            'reason' => self::REASON,
        ]);

        $this->admin->setRole($user, $data['role'] ?? null, $data['reason'], $request->user());

        return $this->done(($data['role'] ?? null) ? 'Now support staff. They must sign in again and set up two-factor.' : 'No longer staff.');
    }

    public function storeNote(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'pinned' => ['boolean'],
        ]);

        AdminAudit::transaction(function () use ($request, $user, $data) {
            $note = new AdminNote(['body' => trim($data['body']), 'pinned' => (bool) ($data['pinned'] ?? false)]);
            $note->user_id = $user->id;
            $note->author_id = $request->user()->id;
            $note->save();

            AdminAudit::record('note.added', $user, null, ['note_id' => $note->id, 'pinned' => $note->pinned]);
        });

        return $this->done('Note added.');
    }

    public function updateNote(Request $request, User $user, AdminNote $note): RedirectResponse
    {
        abort_unless($note->user_id === $user->id, 404);
        $this->authorizeNote($request, $note);

        $data = $request->validate(['pinned' => ['required', 'boolean']]);

        AdminAudit::transaction(function () use ($user, $note, $data) {
            $before = $note->pinned;
            $note->update(['pinned' => $data['pinned']]);

            AdminAudit::record('note.'.($data['pinned'] ? 'pinned' : 'unpinned'), $user, ['pinned' => $before], ['note_id' => $note->id, 'pinned' => $note->pinned]);
        });

        return back();
    }

    public function destroyNote(Request $request, User $user, AdminNote $note): RedirectResponse
    {
        abort_unless($note->user_id === $user->id, 404);
        $this->authorizeNote($request, $note);

        AdminAudit::transaction(function () use ($user, $note) {
            // The text goes into the audit row: removing a note must not
            // remove the record of what it said.
            AdminAudit::record('note.deleted', $user, ['note_id' => $note->id, 'body' => $note->body], null);
            $note->delete();
        });

        return $this->done('Note deleted.');
    }

    /** Your own notes, or any note if you are the owner. */
    private function authorizeNote(Request $request, AdminNote $note): void
    {
        abort_unless($note->author_id === $request->user()->id || $request->user()->isPlatformOwner(), 403, 'Only the note\'s author or the owner can change it.');
    }

    private function done(string $message): RedirectResponse
    {
        return back()->with('platform_notice', $message);
    }
}
