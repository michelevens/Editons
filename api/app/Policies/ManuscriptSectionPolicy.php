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
    /**
     * Delegated to the book, so access rules live in exactly one place.
     *
     * This policy used to answer owner-only on its own, which meant an
     * invited reviewer could be granted a book and still be refused every
     * chapter in it — the membership feature failing one layer down.
     */
    public function view(User $user, ManuscriptSection $section): bool
    {
        return $user->can('view', $section->book);
    }

    public function update(User $user, ManuscriptSection $section): bool
    {
        return $user->can('update', $section->book);
    }

    public function delete(User $user, ManuscriptSection $section): bool
    {
        return $user->can('update', $section->book);
    }
}
