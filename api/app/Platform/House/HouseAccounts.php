<?php

namespace App\Platform\House;

use App\Mail\EmailPreferences;
use App\Models\User;
use App\Platform\AdminAudit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * House publisher accounts: the platform's own imprints (ADR — house catalog).
 *
 * A house account is an ordinary users row with account_kind = 'house', so
 * every book, product, ISBN and sale works exactly as for an author. What
 * makes it different is enforced elsewhere: no sign-in (LoginRequest,
 * EnsureAccountActive), no email (Notify, HouseMailGuard), no "View as", no
 * password reset, no staff role, no growth or lifecycle mail, no royalty
 * statement or payout. Its address is on a reserved domain that cannot
 * deliver, and its password is random and never shown to anybody.
 *
 * The name is the imprint's ("Écrivain Prépa"); never a person's.
 */
final class HouseAccounts
{
    /** a slug: lower-case letters, digits and hyphens, as in the shell argument. */
    public const SLUG = '/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/';

    /** The house account named by id or slug, or null. */
    public function find(string|int $idOrSlug): ?User
    {
        $query = User::where('account_kind', 'house');

        return is_int($idOrSlug) || ctype_digit((string) $idOrSlug)
            ? $query->whereKey((int) $idOrSlug)->first()
            : $query->where('house_slug', (string) $idOrSlug)->first();
    }

    /** @return Collection<int, User> */
    public function all()
    {
        return User::where('account_kind', 'house')->orderBy('name')->get();
    }

    /**
     * Create a house account. Idempotent on the slug: asking again for one
     * that exists returns it and changes nothing.
     *
     * $actor is null from the shell, where the audit row is the system's.
     */
    public function create(string $slug, string $name, string $reason, ?User $actor = null): User
    {
        $slug = Str::lower(trim($slug));
        $name = trim($name);

        if (! preg_match(self::SLUG, $slug)) {
            throw ValidationException::withMessages(['slug' => 'Use lower-case letters, digits and hyphens, e.g. ecrivain-prepa.']);
        }

        if ($name === '' || mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['name' => 'Give the imprint a name of up to 120 characters.']);
        }

        if ($existing = $this->find($slug)) {
            return $existing;
        }

        if (User::where('house_slug', $slug)->exists()) {
            throw ValidationException::withMessages(['slug' => 'That slug is taken.']);
        }

        return AdminAudit::transaction(function () use ($slug, $name, $reason, $actor) {
            $user = new User;
            $user->forceFill([
                'name' => $name,
                // The imprint is the byline on everything it publishes.
                'author_name' => $name,
                'email' => $slug.'@'.House::MAIL_DOMAIN,
                // Never used: LoginRequest refuses a house account before it
                // looks at a password. Random so that even a bug there finds
                // nothing to match.
                'password' => Hash::make(Str::random(64)),
                'account_kind' => 'house',
                'house_slug' => $slug,
                // Every format and seat: the imprint's limits are not a
                // staff member's free plan.
                'plan' => 'studio',
                'email_verified_at' => now(),
                // Belt and braces with HouseMailGuard: every optional
                // category off.
                'email_preferences' => array_fill_keys(array_keys(EmailPreferences::CATEGORIES), false),
            ])->save();

            AdminAudit::record('house.account_created', $user, null, [
                'house_slug' => $slug,
                'name' => $name,
            ], $reason, $actor, system: $actor === null);

            return $user;
        });
    }
}
