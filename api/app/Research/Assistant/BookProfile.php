<?php

namespace App\Research\Assistant;

use App\Journey\BookType;
use App\Models\Book;

/**
 * What the AI is told about the book it works on (ADR — AI research,
 * source-backed fact-checking, cover templates): its kind, genre,
 * language and reader. Author configuration, never manuscript text.
 *
 * It also answers the two questions the prompts branch on, from the
 * book's settings rather than anything a model or a chapter says: is this
 * fiction (dialogue and invented events are not fact-checked), and is it
 * academic (and at what level).
 */
final class BookProfile
{
    /** Book types whose story is invented. */
    public const FICTION_TYPES = ['fiction', 'childrens', 'poetry', 'graphic_novel'];

    /**
     * @return array<string, string|null> label => value, nulls for unknown
     */
    public static function for(Book $book): array
    {
        return [
            'Kind of book' => BookType::find($book->book_type)?->name() ?? ($book->book_type ?: null),
            'Genre' => $book->genre ?: null,
            'Language' => self::language($book),
            'Reader' => self::clip($book->audience),
            'Premise' => self::clip($book->premise),
            'Academic level' => self::academicLevel($book),
        ];
    }

    public static function language(Book $book): string
    {
        return trim((string) $book->language) ?: 'English';
    }

    public static function isFiction(Book $book): bool
    {
        if (in_array($book->book_type, self::FICTION_TYPES, true)) {
            return true;
        }

        // A genre says so even when the type was never set: "Fiction",
        // "Young adult", "Middle grade", "Roman", "Novel".
        return ! in_array($book->book_type, ['nonfiction', 'memoir', 'biography', 'academic', 'business', 'technical', 'self_help', 'educational'], true)
            && (bool) preg_match('/\b(fiction|novel|roman|middle grade|young adult|children)/iu', (string) $book->genre)
            && ! preg_match('/non-?fiction/iu', (string) $book->genre);
    }

    /** "master's", "doctoral", "academic", or null for a non-academic book. */
    public static function academicLevel(Book $book): ?string
    {
        return match ($book->book_type) {
            'masters_thesis', 'masters_project' => "master's",
            'phd_dissertation', 'phd_publication' => 'doctoral',
            'academic' => 'academic',
            default => preg_match('/academ|thesis|th[eè]se|dissert/iu', (string) $book->genre) ? 'academic' : null,
        };
    }

    private static function clip(?string $text): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');

        return $text === '' ? null : mb_substr($text, 0, 400);
    }
}
