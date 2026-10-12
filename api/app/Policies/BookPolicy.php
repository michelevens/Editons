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

    public function view(User $user, Book $book): bool
    {
        return $this->owns($user, $book);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Book $book): bool
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
}
