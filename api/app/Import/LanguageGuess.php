<?php

namespace App\Import;

/**
 * Which of the platform's three writing languages a text is in, by its
 * commonest words (ADR — Journey friction fixes).
 *
 * A French manuscript imported as a new book used to become an English (US)
 * book, and spell-check, typography and exports followed that. Counting
 * stop words is crude and enough here: it only has to tell English, French
 * and Kreyòl apart in a few thousand words of prose, and the import dialog
 * shows its guess for the author to change. Too little text, or no clear
 * winner, is null: the caller falls back to the account's language.
 */
final class LanguageGuess
{
    /** Book language names, as Book Settings offers them (MasterData). */
    public const NAMES = ['en' => 'English (US)', 'fr' => 'French', 'ht' => 'Haitian Creole'];

    /** Words common in one language and rare in the other two. */
    private const WORDS = [
        'en' => ['the', 'and', 'of', 'to', 'is', 'that', 'it', 'was', 'with', 'for', 'this', 'are', 'have', 'from', 'they', 'which', 'what', 'were', 'would', 'their'],
        'fr' => ['le', 'les', 'des', 'est', 'une', 'du', 'que', 'qui', 'dans', 'pour', 'pas', 'sur', 'avec', 'au', 'sont', 'aux', 'cette', 'mais', 'nous', 'leur', 'été', 'être'],
        'ht' => ['nan', 'yo', 'ki', 'pou', 'ak', 'gen', 'nou', 'yon', 'mwen', 'kote', 'konn', 'lè', 'paske', 'anpil', 'genyen', 'pral', 'menm', 'kounye', 'fè', 'bagay', 'moun', 'chak'],
    ];

    /** Fewer counted words than this is not evidence. */
    private const MINIMUM = 12;

    /**
     * A one-sentence idea (ADR — AI writes the book): two counted words are
     * enough when one language has at least twice the other's.
     *
     * @return 'en'|'fr'|'ht'|null
     */
    public static function guessShort(string $text): ?string
    {
        preg_match_all("/[\\p{L}’']+/u", mb_strtolower(mb_substr($text, 0, 2000)), $m);
        $counts = array_count_values($m[0]);
        $scores = [];

        foreach (self::WORDS as $language => $words) {
            $scores[$language] = array_sum(array_map(fn ($w) => $counts[$w] ?? 0, $words));
        }

        arsort($scores);
        [$best, $second] = array_values($scores) + [0, 0];

        return $best >= 2 && $best >= $second * 2 ? array_key_first($scores) : null;
    }

    /** @return 'en'|'fr'|'ht'|null */
    public static function guess(string $text): ?string
    {
        // A sample is plenty, and keeps a 90,000-word import cheap.
        $sample = mb_strtolower(mb_substr($text, 0, 60000));

        preg_match_all('/[\p{L}’\']+/u', $sample, $m);
        $counts = array_count_values($m[0]);

        $scores = [];

        foreach (self::WORDS as $language => $words) {
            $scores[$language] = array_sum(array_map(fn ($w) => $counts[$w] ?? 0, $words));
        }

        arsort($scores);
        [$best, $second] = array_values($scores) + [0, 0];

        if ($best < self::MINIMUM || $best < $second * 1.3) {
            return null;
        }

        return array_key_first($scores);
    }

    /** The book language name for a code, or null. */
    public static function name(?string $code): ?string
    {
        return self::NAMES[$code] ?? null;
    }
}
