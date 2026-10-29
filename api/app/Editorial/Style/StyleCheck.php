<?php

namespace App\Editorial\Style;

use App\Editorial\Finding;

/**
 * The style guide's preferred and banned terms, checked without a model.
 *
 * String work, not inference: a term is matched as a whole word or phrase,
 * ignoring case, and each hit is reported with where it is and which rule
 * it breaks. Free, so it runs on every review of an agent that reads the
 * guide, whatever the driver.
 *
 * A preferred term carries its replacement, anchored on text that occurs
 * once in the chapter so "Apply fix" can find it (SuggestionApplier refuses
 * an ambiguous anchor rather than guess). A banned phrase is flagged only:
 * what to say instead is the author's call.
 */
final class StyleCheck
{
    /** More than this in one chapter is one problem, not fifty findings. */
    public const MAX_FINDINGS = 50;

    /**
     * @param  array<string, mixed>  $rules  normalised StyleRules
     * @return list<Finding>
     */
    public static function run(string $text, array $rules): array
    {
        $rules = StyleRules::normalise($rules);
        $findings = [];

        foreach ($rules['preferred_terms'] as $term) {
            foreach (self::matches($text, $term['avoid']) as [$offset, $match]) {
                [$excerpt, $suggestion] = self::anchor($text, $offset, $match, self::matchCase($match, $term['use']));

                $findings[] = new Finding(
                    severity: 'suggestion',
                    title: "Style guide: “{$term['use']}”, not “{$match}”",
                    detail: trim('The style guide prefers “'.$term['use'].'” to “'.$term['avoid'].'”. '.($term['note'] ?? '')),
                    excerpt: $excerpt,
                    suggestion: $suggestion,
                    fromOffset: $offset,
                    toOffset: $offset + mb_strlen($match),
                    rule: 'style:preferred:'.self::key($term['avoid']),
                    term: $match,
                );
            }
        }

        foreach ($rules['banned'] as $banned) {
            foreach (self::matches($text, $banned['phrase']) as [$offset, $match]) {
                [$excerpt] = self::anchor($text, $offset, $match, $match);

                $findings[] = new Finding(
                    severity: 'warning',
                    title: "Style guide: “{$match}” is on the banned list",
                    detail: trim('The style guide bans “'.$banned['phrase'].'”. '.($banned['note'] ?? '')),
                    excerpt: $excerpt,
                    fromOffset: $offset,
                    toOffset: $offset + mb_strlen($match),
                    rule: 'style:banned:'.self::key($banned['phrase']),
                    term: $match,
                );
            }
        }

        usort($findings, fn (Finding $a, Finding $b) => $a->fromOffset <=> $b->fromOffset);

        return array_slice($findings, 0, self::MAX_FINDINGS);
    }

    /**
     * Whole-word, case-insensitive hits, as [character offset, text].
     *
     * @return list<array{0: int, 1: string}>
     */
    public static function matches(string $text, string $phrase): array
    {
        $phrase = preg_replace('/\s+/u', ' ', trim(StyleRules::nfc($phrase)));
        $pattern = '/(?<![\p{L}\p{N}])'.str_replace(' ', '\s+', preg_quote($phrase, '/')).'(?![\p{L}\p{N}])/iu';

        if (! @preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        // preg reports bytes; findings count characters.
        return array_map(fn ($hit) => [mb_strlen(substr($text, 0, $hit[1])), $hit[0]], $m[0]);
    }

    /** A stable, readable rule key for a term: "e-mail" → "e-mail", "Off the record" → "off-the-record". */
    public static function key(string $term): string
    {
        return mb_substr(trim(preg_replace('/[^\p{L}\p{N}]+/u', '-', mb_strtolower(StyleRules::nfc($term))), '-'), 0, 90) ?: 'term';
    }

    /**
     * Widen the match with following words until it occurs once in the
     * chapter, and give the replacement the same context.
     *
     * @return array{0: string, 1: string}
     */
    private static function anchor(string $text, int $offset, string $match, string $replacement): array
    {
        if (substr_count($text, $match) <= 1) {
            return [$match, $replacement];
        }

        $before = mb_substr($text, max(0, $offset - 40), min(40, $offset));
        $after = mb_substr($text, $offset + mb_strlen($match), 80);

        foreach ([2, 4, 8] as $words) {
            preg_match('/^(\s*\S+){0,'.$words.'}/u', $after, $tail);
            preg_match('/(\S+\s*){0,'.$words.'}$/u', $before, $head);
            $tail = rtrim($tail[0] ?? '');
            $head = ltrim($head[0] ?? '');
            $candidate = $head.$match.$tail;

            if (substr_count($text, $candidate) === 1) {
                return [$candidate, $head.$replacement.$tail];
            }
        }

        // Still not unique: the applier will refuse, honestly.
        return [$match, $replacement];
    }

    /** "Email" for a capitalised "E-mail". */
    private static function matchCase(string $found, string $use): string
    {
        $first = mb_substr($found, 0, 1);

        if ($found === mb_strtoupper($found) && mb_strlen($found) > 1) {
            return mb_strtoupper($use);
        }

        return $first !== mb_strtolower($first)
            ? mb_strtoupper(mb_substr($use, 0, 1)).mb_substr($use, 1)
            : $use;
    }
}
