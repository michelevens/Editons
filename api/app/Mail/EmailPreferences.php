<?php

namespace App\Mail;

use App\Models\User;

/**
 * Which emails somebody has asked for.
 *
 * Two kinds of category. Mandatory ones — account security, billing, receipts
 * — are always sent: a person cannot opt out of being told their password
 * changed or their card was declined, because the email is the control.
 * Everything else defaults to on, except a category marked 'default' =>
 * false (news, and the weekly progress digest: opt-in), and can be
 * switched from the profile page, or off from the unsubscribe link in the
 * footer of the email itself.
 *
 * Stored as one JSON column of overrides rather than a column per category,
 * so adding a category is a constant here, not a migration; a key that is
 * absent means "the default".
 */
final class EmailPreferences
{
    /**
     * category => [label, description, mandatory, marketing, digestible].
     *
     * `digestible` marks activity about a book: those can be sent instantly,
     * gathered into a daily or weekly digest, kept for the inbox only, and
     * muted per book (App\Mail\NotificationSettings).
     *
     * `marketing` marks mail that is not a direct consequence of something
     * the reader or a collaborator did. It is the only kind that carries an
     * unsubscribe line and a List-Unsubscribe header.
     */
    public const CATEGORIES = [
        'account' => [
            'label' => 'Account and security',
            'description' => 'Sign-ins, password and email changes, account deletion.',
            'mandatory' => true,
            'marketing' => false,
        ],
        'billing' => [
            'label' => 'Billing',
            'description' => 'Receipts, failed payments, plan changes, statements and credit warnings.',
            'mandatory' => true,
            'marketing' => false,
        ],
        'receipts' => [
            'label' => 'Purchases',
            'description' => 'Receipts and download links for books you buy.',
            'mandatory' => true,
            'marketing' => false,
        ],
        'collaboration' => [
            'label' => 'Collaboration',
            'description' => 'Invitations, replies and mentions in comments, and chapters assigned to you.',
            'mandatory' => false,
            'marketing' => false,
            'digestible' => true,
        ],
        'writing' => [
            'label' => 'Editorial reviews',
            'description' => 'When an editorial review or a Book Director report is ready.',
            'mandatory' => false,
            'marketing' => false,
            'digestible' => true,
        ],
        'publishing' => [
            'label' => 'Exports and publishing',
            'description' => 'Finished or failed exports, store submissions, and books going live.',
            'mandatory' => false,
            'marketing' => false,
            'digestible' => true,
        ],
        'sales' => [
            'label' => 'Sales',
            'description' => 'A note each time somebody buys one of your books.',
            'mandatory' => false,
            'marketing' => false,
            'digestible' => true,
        ],
        // Écrivain Partners: a first sale and the monthly statement. A
        // partner's approval, suspension and payouts go as account and
        // billing mail, which are always sent.
        'partners' => [
            'label' => 'Partner program',
            'description' => 'Your first partner sale and a monthly statement of your commissions.',
            'mandatory' => false,
            'marketing' => false,
            'digestible' => true,
        ],
        // Opt-in since notification frequency (ADR — Notification
        // frequency): sent on the day and at the hour the author picks
        // (App\Mail\NotificationSettings), by `notifications:deliver`.
        'digest' => [
            'label' => 'Weekly progress',
            'description' => 'A summary of what you wrote last week, on the day and at the time you choose.',
            'mandatory' => false,
            'marketing' => true,
            'default' => false,
        ],
        // The console's broadcasts (App\Growth). Off until the author turns
        // it on: announcements and offers are marketing in the strict sense,
        // and authors in the EU and Canada must opt in to those. Tips are
        // about the author's own work and stay on, with the one-click
        // unsubscribe; the console only sends to people for whom this
        // resolves on.
        'news' => [
            'label' => 'News from Écrivain',
            'description' => 'Occasional announcements: new features, events and offers.',
            'mandatory' => false,
            'marketing' => true,
            'default' => false,
        ],
        'tips' => [
            'label' => 'Getting-started tips',
            'description' => 'A few short emails while you are new, or when a book has been waiting a while.',
            'mandatory' => false,
            'marketing' => true,
        ],
    ];

    /** @return list<string> */
    public static function toggleable(): array
    {
        return array_keys(array_filter(self::CATEGORIES, fn (array $c) => ! $c['mandatory']));
    }

    public static function isMandatory(string $category): bool
    {
        return self::CATEGORIES[$category]['mandatory']
            ?? throw new \InvalidArgumentException("Unknown email category [{$category}].");
    }

    public static function isMarketing(string $category): bool
    {
        return (bool) (self::CATEGORIES[$category]['marketing'] ?? false);
    }

    public static function isDigestible(string $category): bool
    {
        return (bool) (self::CATEGORIES[$category]['digestible'] ?? false);
    }

    /** @return list<string> */
    public static function digestible(): array
    {
        return array_keys(array_filter(self::CATEGORIES, fn (array $c) => $c['digestible'] ?? false));
    }

    /**
     * May this category of mail go to this person?
     *
     * Null means the recipient has no account (a buyer, an invitee who has
     * not signed up): there is nothing to consult, and the mail they get is
     * the transactional consequence of their own action.
     */
    public static function allows(?User $user, string $category): bool
    {
        if (self::isMandatory($category) || $user === null) {
            return true;
        }

        return (bool) (($user->email_preferences ?? [])[$category] ?? (self::CATEGORIES[$category]['default'] ?? true));
    }

    /** @return array<string, bool> every category, resolved */
    public static function for(User $user): array
    {
        $resolved = [];

        foreach (array_keys(self::CATEGORIES) as $category) {
            $resolved[$category] = self::allows($user, $category);
        }

        return $resolved;
    }

    /**
     * Change some toggles. Mandatory categories and unknown keys are ignored
     * rather than stored, so a crafted request cannot switch off security
     * mail or plant arbitrary keys in the column.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function update(User $user, array $changes): void
    {
        $stored = $user->email_preferences ?? [];

        foreach (self::toggleable() as $category) {
            if (array_key_exists($category, $changes)) {
                $stored[$category] = (bool) $changes[$category];
            }
        }

        $user->forceFill(['email_preferences' => $stored])->save();
    }

    /** The settings page's view of the categories. @return list<array<string, mixed>> */
    public static function describe(User $user): array
    {
        $resolved = self::for($user);

        return array_values(array_map(
            fn (string $key, array $category) => [
                'key' => $key,
                'label' => __($category['label']),
                'description' => __($category['description']),
                'mandatory' => $category['mandatory'],
                'digestible' => $category['digestible'] ?? false,
                'enabled' => $resolved[$key],
            ],
            array_keys(self::CATEGORIES),
            self::CATEGORIES,
        ));
    }
}
