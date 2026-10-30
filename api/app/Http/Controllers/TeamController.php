<?php

namespace App\Http\Controllers;

use App\Mail\Notify;
use App\Models\Book;
use App\Models\BookMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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

    /**
     * Invite someone to this book, and to any of the owner's other books
     * chosen alongside it (`also`).
     *
     * Each book is its own membership with its own role, seat count and
     * accept link: an invitation never reaches a book that was not chosen.
     * A book that cannot take the invitation (already on it, no seat) is
     * skipped and reported; only when none can is the request refused.
     */
    public function invite(Request $request, Book $book): JsonResponse
    {
        $this->authorize('manage', $book);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'in:'.implode(',', BookMember::ROLES)],
            'also' => ['sometimes', 'array', 'max:200'],
            'also.*' => ['integer', 'distinct'],
        ]);

        $email = mb_strtolower(trim($data['email']));

        $also = array_values(array_diff($data['also'] ?? [], [$book->id]));
        $others = Book::whereIn('id', $also)->orderBy('title')->get();

        abort_if($others->count() !== count($also), 404);

        foreach ($others as $other) {
            $this->authorize('manage', $other);
        }

        $targets = $others->prepend($book);
        $invited = [];
        $skipped = [];

        foreach ($targets as $target) {
            try {
                $this->inviteTo($request, $target, $email, $data['role']);
                $invited[] = ['id' => $target->id, 'title' => $target->title];
            } catch (ValidationException $refused) {
                // One book alone keeps the old behaviour: the reason, as an error.
                if ($targets->count() === 1) {
                    throw $refused;
                }

                $skipped[] = ['id' => $target->id, 'title' => $target->title, 'reason' => $refused->errors()['email'][0] ?? $refused->getMessage()];
            }
        }

        if ($invited === []) {
            throw ValidationException::withMessages(['email' => $skipped[0]['reason']]);
        }

        return response()->json($this->payload($book) + ['invited' => $invited, 'skipped' => $skipped], 201);
    }

    private function inviteTo(Request $request, Book $book, string $email, string $role): void
    {
        // Inviting the owner is a no-op dressed as an action, and it would
        // create a membership row competing with books.user_id.
        if ($email === mb_strtolower($book->user->email)) {
            throw ValidationException::withMessages([
                'email' => __('That is the account owner, who already has full access.'),
            ]);
        }

        $existing = $book->members()->where('email', $email)->first();

        if ($existing && $existing->status !== 'revoked') {
            throw ValidationException::withMessages([
                'email' => $existing->status === 'active'
                    ? __('That person is already on this book.')
                    : __('They have already been invited. Resend the invitation instead.'),
            ]);
        }

        $this->assertSeatAvailable($book, ignoring: $existing?->id);

        $member = $existing ?? $book->members()->make(['email' => $email]);

        $member->fill([
            'email' => $email,
            'role' => $role,
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
        $limit = $book->user->seatLimit();

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
                'email' => __('The :plan plan includes :limit seats, and they are all taken.', ['plan' => $book->user->planLimits()['name'], 'limit' => $limit]),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function payload(Book $book): array
    {
        $limit = $book->user->seatLimit();

        $members = $book->members()
            ->with('user:id,name')
            ->orderBy('email')
            ->get()
            ->map(fn (BookMember $member) => [
                'id' => $member->id,
                'email' => $member->email,
                // Only once they have accepted. Before that the name would
                // tell whoever typed the address that it has an account here,
                // and whose it is.
                'name' => $member->status === 'active' ? $member->user?->name : null,
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
            // The owner's other books, to invite the same person to several
            // at once. Only for whoever manages the team: a member must not
            // learn what else the owner is writing.
            'other_books' => Gate::allows('manage', $book)
                ? $book->user->books()
                    ->whereKeyNot($book->id)
                    ->where('status', '!=', 'archived')
                    ->orderBy('title')
                    ->get(['id', 'title', 'user_id'])
                    ->filter(fn (Book $other) => Gate::allows('manage', $other))
                    ->map(fn (Book $other) => ['id' => $other->id, 'title' => $other->title])
                    ->values()
                    ->all()
                : [],
        ];
    }
}
