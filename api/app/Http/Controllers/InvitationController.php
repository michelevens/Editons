<?php

namespace App\Http\Controllers;

use App\Mail\Notify;
use App\Models\BookMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accepting an invitation to a book.
 *
 * Reachable signed out, because the person invited usually has no account —
 * that is the normal case, not an edge one. They see what they were invited
 * to, then sign in or register, and the acceptance completes.
 */
class InvitationController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $member = BookMember::where('invite_token', $token)->with('book')->first();

        // An expired or spent token is told apart from a wrong one, because
        // "this link has expired" and "this link is not valid" lead a person
        // to different next steps.
        $state = match (true) {
            ! $member => 'unknown',
            $member->status === 'active' => 'accepted',
            ! $member->inviteIsLive() => 'expired',
            default => 'open',
        };

        return Inertia::render('Invitation', [
            'state' => $state,
            'token' => $token,
            'invitation' => $state === 'open' ? [
                'book_title' => $member->book->title,
                'role' => $member->role,
                'email' => $member->email,
                'invited_by' => $member->inviter?->name,
            ] : null,
            // Whether the signed-in person is the one invited. Accepting on
            // someone else's behalf would attach the wrong account.
            'email_matches' => $member && $request->user()
                ? mb_strtolower($request->user()->email) === mb_strtolower($member->email)
                : null,
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $member = BookMember::where('invite_token', $token)->firstOrFail();

        abort_unless($member->inviteIsLive(), 410, 'This invitation is no longer valid.');

        // The invitation names an address. Letting a different account accept
        // it would hand access to someone the owner did not invite.
        abort_unless(
            mb_strtolower($request->user()->email) === mb_strtolower($member->email),
            403,
            'This invitation was sent to a different email address.',
        );

        $member->forceFill([
            'user_id' => $request->user()->id,
            'status' => 'active',
            'accepted_at' => now(),
            // Spend the token. An accepted invitation must not be replayable.
            'invite_token' => null,
        ])->save();

        Notify::invitationAccepted($member->fresh(['book.user', 'user']));

        return redirect()->route('books.overview', $member->book_id);
    }
}
