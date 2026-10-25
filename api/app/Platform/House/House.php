<?php

namespace App\Platform\House;

use App\Models\Book;
use App\Models\User;
use App\Platform\Impersonation;
use App\Platform\PlatformSettings;

/**
 * The rules about house books, in one place (ADR — house catalog and house
 * publisher accounts).
 *
 * A house book is a book owned by a house account. Nobody signs in to that
 * account, so nobody is its "owner" in the BookPolicy sense; instead the
 * platform owner edits it directly through the normal studio, as
 * themselves — a permission, not "View as" — and every version, export and
 * audit row carries the real staff member. Support may open it, read-only.
 *
 * There is no separate "admin" platform role in Écrivain (owner and support
 * only, ADR-0029): the owner role is the one with edit rights here.
 */
final class House
{
    /** The domain every house account's address is on: reserved, never delivered (RFC 2606). */
    public const MAIL_DOMAIN = 'house.ecrivain.invalid';

    /** Is this book owned by a house account? */
    public static function isHouseBook(Book $book): bool
    {
        return (bool) $book->user?->isHouse();
    }

    /** May $user change this house book (manuscript, design, exports, product)? */
    public static function canEdit(User $user, Book $book): bool
    {
        return $user->isPlatformOwner() && self::staffReady($user) && self::isHouseBook($book);
    }

    /** May $user open this house book? All staff can read it. */
    public static function canView(User $user, Book $book): bool
    {
        return $user->isPlatformAdmin() && self::staffReady($user) && self::isHouseBook($book);
    }

    /** May $user run the House catalog's actions at all (owner) — the console's gate. */
    public static function canManage(User $user): bool
    {
        return $user->isPlatformOwner();
    }

    /**
     * Whose plan, ISBN pool and limits an action on $book uses.
     *
     * For an author's book, the person acting, as always. For a house book,
     * the house account: a staff member's own (free) plan must not decide
     * which formats the imprint can export, and their own ISBNs must not end
     * up on its books.
     */
    public static function accountFor(Book $book, User $actor): User
    {
        return self::isHouseBook($book) ? $book->user : $actor;
    }

    /** Is this address a house account's? Checked before any mail leaves. */
    public static function isHouseAddress(?string $address): bool
    {
        if ($address === null || trim($address) === '') {
            return false;
        }

        $address = mb_strtolower(trim($address));

        if (str_ends_with($address, '@'.self::MAIL_DOMAIN)) {
            return true;
        }

        return User::where('account_kind', 'house')->whereRaw('lower(email) = ?', [$address])->exists();
    }

    /**
     * The same conditions the console sets: a real staff session (never a
     * "View as" one, which is the author's) with two-factor on when the
     * platform requires it of staff.
     */
    private static function staffReady(User $user): bool
    {
        if (Impersonation::active()) {
            return false;
        }

        return $user->hasTwoFactor() || ! app(PlatformSettings::class)->get('require_2fa_for_admins');
    }
}
