<?php

namespace App\Policies;

use App\Models\ManuscriptSection;
use App\Models\User;

/**
 * Authorisation for manuscript sections.
 *
 * A section has no owner of its own — it inherits from the book. Checking the
 * book is what prevents the obvious attack: guessing a section id belonging
 * to someone else's manuscript. Route-model binding resolves the id before
 * the policy runs, so this check is the only thing standing between a
 * stranger's chapter and the editor.
 */
class ManuscriptSectionPolicy
{
    public function view(User $user, ManuscriptSection $section): bool
    {
        return $this->ownsBook($user, $section);
    }

    public function update(User $user, ManuscriptSection $section): bool
    {
        return $this->ownsBook($user, $section);
    }

    public function delete(User $user, ManuscriptSection $section): bool
    {
        return $this->ownsBook($user, $section);
    }

    private function ownsBook(User $user, ManuscriptSection $section): bool
    {
        return $user->id === $section->book->user_id;
    }
}
