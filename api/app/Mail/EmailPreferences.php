<?php

namespace App\Mail;

use App\Models\User;

/**
 * Which emails somebody has asked for.
 *
 * Two kinds of category. Mandatory ones — account security, billing, receipts
 * — are always sent: a person cannot opt out of being told their password
 * changed or their card was declined, because the email is the control.
 * Everything else defaults to on and can be switched off from the profile
 * page, or from the unsubscribe link in the footer of the email itself.
 *
 * Stored as one JSON column of overrides rather than a column per category,
 * so adding a category is a constant here, not a migration; a key that is
 * absent means "the default".
 */
final class EmailPreferences
{
    /**
     * category => [label, description, mandatory, marketing].
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
        ],
        'writing' => [
            'label' => 'Editorial reviews',
            'description' => 'When an editorial review or a Book Director report is ready.',
            'mandatory' => false,
            'marketing' => false,
        ],
        'publishing' => [
            'label' => 'Exports and publishing',
            'description' => 'Finished or failed exports, store submissions, and books going live.',
            'mandatory' => false,
            'marketing' => false,
        ],
        'sales' => [
            'label' => 'Sales',
            'description' => 'A note each time somebody buys one of your books.',
            'mandatory' => false,
            'marketing' => false,
        ],
        'digest' => [
            'label' => 'Weekly progress',
            'description' => 'A Monday summary of what you wrote last week.',
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

        return (bool) (($user->email_preferences ?? [])[$category] ?? true);
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
                'enabled' => $resolved[$key],
            ],
            array_keys(self::CATEGORIES),
            self::CATEGORIES,
        ));
    }
}
