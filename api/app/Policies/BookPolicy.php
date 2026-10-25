<?php

namespace App\Policies;

use App\Models\Book;
use App\Models\BookMember;
use App\Models\User;
use App\Platform\House\House;

/**
 * Authorisation for books.
 *
 * You may act on a book you own; members act by role; and a house book
 * (owned by a platform house account, which nobody signs in to) is edited
 * by the platform owner and read by all staff (ADR — house catalog).
 *
 * Every method routes through owns() rather than repeating the comparison, so
 * when sharing arrives there is exactly one place to widen.
 */
class BookPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // Scoped by query, not by policy — you see your own.
    }

    /**
     * Owners, and anyone with an accepted membership.
     *
     * Every role can read — a reviewer who cannot open the book has nothing
     * to review. What the roles differ on is writing, which update() decides.
     */
    public function view(User $user, Book $book): bool
    {
        return $this->owns($user, $book)
            || $this->membership($user, $book) !== null
            || House::canView($user, $book);
    }

    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Owners and editors.
     *
     * Reviewers and viewers are deliberately excluded: the point of inviting
     * a reviewer is that they comment on the manuscript rather than change
     * it. Book settings and membership are owner-only — see manage().
     */
    public function update(User $user, Book $book): bool
    {
        return $this->owns($user, $book)
            || $this->membership($user, $book)?->role === 'editor'
            || House::canEdit($user, $book);
    }

    /**
     * Settings, archiving and the team itself stay with the owner. For a
     * house book the platform owner stands in for the house account, as
     * themselves.
     */
    public function manage(User $user, Book $book): bool
    {
        return $this->owns($user, $book) || House::canEdit($user, $book);
    }

    /**
     * Never a house book from the studio: an imprint's catalogue is not one
     * click from the bin. Move the book out of the house account first.
     */
    public function delete(User $user, Book $book): bool
    {
        return $this->owns($user, $book);
    }

    private function owns(User $user, Book $book): bool
    {
        return $user->id === $book->user_id;
    }

    /** An accepted membership, or null. Invited-but-not-accepted is not access. */
    private function membership(User $user, Book $book): ?BookMember
    {
        return $book->members()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();
    }
}
