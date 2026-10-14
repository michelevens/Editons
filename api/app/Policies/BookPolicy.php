<?php

namespace App\Policies;

use App\Models\Book;
use App\Models\User;

/**
 * Authorisation for books.
 *
 * One rule today: you may act on a book you own. There are no collaborators,
 * no roles and no organisations yet (ADR-0006), so anything more elaborate
 * would be guessing at a shape Milestone 4 will define.
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
        return $this->owns($user, $book) || $this->membership($user, $book) !== null;
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
            || $this->membership($user, $book)?->role === 'editor';
    }

    /** Settings, archiving and the team itself stay with the owner. */
    public function manage(User $user, Book $book): bool
    {
        return $this->owns($user, $book);
    }

    public function delete(User $user, Book $book): bool
    {
        return $this->owns($user, $book);
    }

    private function owns(User $user, Book $book): bool
    {
        return $user->id === $book->user_id;
    }

    /** An accepted membership, or null. Invited-but-not-accepted is not access. */
    private function membership(User $user, Book $book): ?\App\Models\BookMember
    {
        return $book->members()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();
    }
}
