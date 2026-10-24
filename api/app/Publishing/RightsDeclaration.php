<?php

namespace App\Publishing;

use App\Models\Book;
use App\Models\User;

/**
 * The author's declaration of rights, before the book goes to the stores.
 *
 * Every store makes the author warrant that they hold the rights to what
 * they upload. The quotations, pictures and official papers a book reuses
 * are where that goes wrong, so the declaration lists them, each with the
 * permission held for it, and records who confirmed it and when. Changing
 * the list is a new declaration that needs confirming again.
 *
 * It records what the author states; it is not legal advice, and the app
 * does not judge whether a use needs permission.
 */
class RightsDeclaration
{
    /** What third-party material can be. */
    public const KINDS = [
        'quote' => 'Quotation',
        'image' => 'Image or illustration',
        'official' => 'Official paper or document',
        'other' => 'Other material',
    ];

    /** What the author holds for it. */
    public const PERMISSIONS = [
        'permission' => 'Written permission',
        'licence' => 'A licence',
        'public_domain' => 'Public domain',
        'unsure' => 'Not sure yet',
    ];

    /**
     * @param  list<array{kind: string, description: string, permission: string}>  $materials
     */
    public static function confirm(Book $book, array $materials, User $user): void
    {
        $book->rights_declaration = [
            'holds_rights' => true,
            'materials' => array_values(array_map(fn ($m) => [
                'kind' => $m['kind'],
                'description' => trim($m['description']),
                'permission' => $m['permission'],
            ], $materials)),
            'confirmed_by' => $user->id,
            'confirmed_name' => $user->name,
            'confirmed_at' => now()->toIso8601String(),
        ];
        $book->save();
    }

    public static function confirmed(Book $book): bool
    {
        $declaration = $book->rights_declaration ?? [];

        return ($declaration['holds_rights'] ?? false) === true && isset($declaration['confirmed_at']);
    }

    /** Material the author has not yet said they may use. */
    public static function unsure(Book $book): int
    {
        return collect(($book->rights_declaration ?? [])['materials'] ?? [])
            ->where('permission', 'unsure')
            ->count();
    }

    /** @return array<string, mixed> */
    public static function for(Book $book): array
    {
        $declaration = $book->rights_declaration ?? [];

        return [
            'confirmed' => self::confirmed($book),
            'materials' => $declaration['materials'] ?? [],
            'confirmed_by' => $declaration['confirmed_name'] ?? null,
            'confirmed_at' => $declaration['confirmed_at'] ?? null,
            'kinds' => self::KINDS,
            'permissions' => self::PERMISSIONS,
        ];
    }
}
