<?php

namespace App\Http\Controllers;

use App\Mail\Notify;
use App\Models\Book;
use App\Models\BookMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Who else is on a book.
 *
 * Invitations are by email address, because the person being invited usually
 * does not have an account yet. The membership is real from the moment it is
 * created; the user_id arrives when they accept.
 */
class TeamController extends Controller
{
    public function index(Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        return response()->json($this->payload($book));
    }

    public function invite(Request $request, Book $book): JsonResponse
    {
        $this->authorize('manage', $book);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'in:'.implode(',', BookMember::ROLES)],
        ]);

        $email = mb_strtolower(trim($data['email']));

        // Inviting the owner is a no-op dressed as an action, and it would
        // create a membership row competing with books.user_id.
        if ($email === mb_strtolower($book->user->email)) {
            throw ValidationException::withMessages([
                'email' => 'That is the account owner, who already has full access.',
            ]);
        }

        $existing = $book->members()->where('email', $email)->first();

        if ($existing && $existing->status !== 'revoked') {
            throw ValidationException::withMessages([
                'email' => $existing->status === 'active'
                    ? 'That person is already on this book.'
                    : 'They have already been invited. Resend the invitation instead.',
            ]);
        }

        $this->assertSeatAvailable($book, ignoring: $existing?->id);

        $member = $existing ?? $book->members()->make(['email' => $email]);

        $member->fill([
            'email' => $email,
            'role' => $data['role'],
            'invited_by' => $request->user()->id,
            // A revoked member being re-invited starts clean rather than
            // inheriting whatever they had before.
            'user_id' => User::where('email', $email)->value('id'),
        ]);

        $book->members()->save($member);
        $member->issueInvite();

        // Emailed, and the link is still shown for copying: an invitation
        // that lands in spam should not leave the author with no way to
        // send it themselves.
        Notify::bookInvitation($member->fresh(['book.user', 'inviter', 'user']));

        return response()->json($this->payload($book), 201);
    }

    public function update(Request $request, BookMember $member): JsonResponse
    {
        $this->authorize('manage', $member->book);

        $member->update($request->validate([
            'role' => ['required', 'in:'.implode(',', BookMember::ROLES)],
        ]));

        return response()->json($this->payload($member->book));
    }

    public function resend(BookMember $member): JsonResponse
    {
        $this->authorize('manage', $member->book);

        abort_if($member->status === 'active', 422, 'They have already accepted.');

        $member->issueInvite();

        Notify::bookInvitation($member->fresh(['book.user', 'inviter', 'user']));

        return response()->json($this->payload($member->book));
    }

    public function destroy(BookMember $member): JsonResponse
    {
        $this->authorize('manage', $member->book);

        $book = $member->book;

        // Deleted rather than revoked: removing someone should free their
        // seat and leave no trace suggesting they still have standing. The
        // audit trail for who had access belongs in an audit log, not in a
        // row that looks like a member.
        $member->delete();

        return response()->json($this->payload($book));
    }

    /**
     * Refuse an invitation the plan has no seat for.
     *
     * Counted here rather than trusted from the client, and counted against
     * active plus pending: a seat held by an unaccepted invitation is still
     * spoken for, or an owner could invite past the limit and simply wait.
     */
    private function assertSeatAvailable(Book $book, ?int $ignoring = null): void
    {
        $limit = $book->user->planLimits()['seats'];

        if ($limit === null) {
            return;
        }

        $taken = $book->members()
            ->whereIn('status', ['invited', 'active'])
            ->when($ignoring, fn ($query) => $query->whereKeyNot($ignoring))
            ->count();

        // +1 for the owner, who holds a seat without a membership row.
        if ($taken + 1 >= $limit) {
            throw ValidationException::withMessages([
                'email' => "The {$book->user->planLimits()['name']} plan includes {$limit} seats, and they are all taken.",
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function payload(Book $book): array
    {
        $limit = $book->user->planLimits()['seats'];

        $members = $book->members()
            ->with('user:id,name')
            ->orderBy('email')
            ->get()
            ->map(fn (BookMember $member) => [
                'id' => $member->id,
                'email' => $member->email,
                'name' => $member->user?->name,
                'role' => $member->role,
                'status' => $member->status,
                'invite_live' => $member->inviteIsLive(),
                'invited_at' => $member->invited_at?->diffForHumans(),
                // Emailed too; shown for copying in case it lands in spam.
                'invite_url' => $member->inviteIsLive()
                    ? url("/invitations/{$member->invite_token}")
                    : null,
            ])
            ->all();

        return [
            'owner' => [
                'name' => $book->user->name,
                'email' => $book->user->email,
            ],
            'members' => $members,
            'roles' => BookMember::ROLES,
            'seats' => [
                'limit' => $limit,
                // The owner counts, which is why a 2-seat plan allows one
                // collaborator rather than two.
                'used' => collect($members)->whereIn('status', ['invited', 'active'])->count() + 1,
            ],
            // Invitations are emailed (App\Mail\Notify::bookInvitation) unless
            // the mailer goes nowhere, in which case the screen must not
            // claim an email went out. The link is returned either way.
            'delivery' => in_array(config('mail.default'), ['log', 'array'], true) ? 'manual' : 'email',
        ];
    }
}
