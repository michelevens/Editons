<?php

namespace App\Editorial\Style;

use App\Editorial\EditorialAgent;
use App\Editorial\Finding;
use Illuminate\Support\Str;

/**
 * What a finding is about (its rule and its word), and whether the book has
 * said never to flag it.
 *
 * Server-side and after the driver: a model told "never flag Kreyòl" may
 * still flag it, and a dictionary that only works when the model listens is
 * not one. A finding whose word is in the book's dictionary, or whose rule
 * the book turned off, is dropped before it is stored.
 */
final class Suppression
{
    /**
     * The rule a finding belongs to: the driver's own when it gives one,
     * otherwise the agent and its title without the particulars ("Filler
     * word: “very” ×3" is copy:filler-word).
     */
    public static function ruleFor(EditorialAgent $agent, Finding $finding): string
    {
        if ($finding->rule) {
            return mb_substr($finding->rule, 0, 120);
        }

        $title = preg_replace('/[“"«‘\'][^”"»’\']*[”"»’\']|\(.*?\)|[×x]?\d+/u', '', $finding->title);
        $slug = Str::slug((string) $title) ?: 'finding';

        return mb_substr($agent->value.':'.$slug, 0, 120);
    }

    /**
     * The word or phrase a finding flags, when it flags one: the driver's,
     * a quoted word in the title, or a short excerpt.
     */
    public static function termFor(Finding $finding): ?string
    {
        if ($finding->term) {
            return mb_substr(trim($finding->term), 0, 120);
        }

        if (preg_match('/[“"«]\s*([^”"»]{1,60}?)\s*[”"»]/u', $finding->title, $m)) {
            return $m[1];
        }

        $excerpt = trim((string) $finding->excerpt, " \t\n.,;:!?…");

        if ($excerpt !== '' && mb_strlen($excerpt) <= 40 && count(preg_split('/\s+/u', $excerpt)) <= 3) {
            return $excerpt;
        }

        return null;
    }

    /** Is this finding one the book said never to raise? */
    public static function suppressed(?string $term, string $rule, array $dictionary, array $disabledRules): bool
    {
        if (in_array($rule, $disabledRules, true)) {
            return true;
        }

        if ($term === null) {
            return false;
        }

        foreach ($dictionary as $word) {
            if (self::sameWord($term, $word)) {
                return true;
            }
        }

        return false;
    }

    /** The same word, whatever its case or Unicode form. */
    public static function sameWord(string $a, string $b): bool
    {
        return mb_strtolower(StyleRules::nfc(trim($a))) === mb_strtolower(StyleRules::nfc(trim($b)));
    }
}
