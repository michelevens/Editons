<?php

namespace App\Editorial;

use App\Models\Citation;
use App\Models\ManuscriptSection;

/**
 * What may be written into a manuscript when a finding is applied (ADR —
 * Safe apply, honest prices, citation fixes).
 *
 * A finding's "suggestion" is meant to be replacement text for its excerpt.
 * A real reviewer does not always keep to that: a fact-checker asked for a
 * fix will write "Add a source note identifying where Danticat said or
 * wrote this", and applying that put the instruction into the book in place
 * of a verified quotation, forty-one times in one bulk accept.
 *
 * Two questions, answered without a model:
 *
 * - isAdvice(): is the suggestion plausibly replacement text, or advice to
 *   the author? Advice is shown as advice (no Apply) and never written in.
 * - context(): does the excerpt sit inside quotation marks, a cited
 *   passage, or a quotation with a citation attached? A cited quotation is
 *   never changed by a bulk accept; the author can still apply that one
 *   finding on its own.
 *
 * The checks lean towards advice. A real fix wrongly held back costs the
 * author a copy and paste; an instruction wrongly applied corrupts the book.
 */
final class ApplyGuard
{
    /**
     * Agents whose fixes are applied one by one, never in a bulk accept:
     * their "fixes" are factual claims a reviewer may have from memory, and
     * each must be checked against the source before it goes in.
     */
    public const ONE_BY_ONE = ['fact_checker', 'subject_matter'];

    /** Agents a bulk accept starts with (the others are opt-in). */
    public const BULK_DEFAULT = ['copy', 'line'];

    /**
     * Verbs an editor uses to tell an author what to do. As the first word
     * of a suggestion whose excerpt does not start with it, or capitalised
     * after a colon or a full stop, they mark advice, not prose.
     */
    private const IMPERATIVES = [
        'add', 'consider', 'verify', 'cite', 'remove', 'rephrase', 'reword', 'rewrite',
        'revise', 'check', 'confirm', 'include', 'provide', 'clarify', 'specify',
        'replace', 'delete', 'insert', 'mention', 'attribute', 'double-check',
        'cross-check', 'footnote', 'identify', 'flag', 'ensure', 'expand', 'define',
        'explain', 'indicate', 'acknowledge', 'qualify', 'soften', 'substantiate',
        'source', 'research', 'omit', 'shorten', 'simplify', 'avoid', 'try',
        // French and Kreyòl, the app's other authoring languages.
        'ajoutez', 'ajouter', 'vérifiez', 'vérifier', 'citez', 'supprimez', 'reformulez',
        'envisagez', 'précisez', 'remplacez', 'indiquez', 'mentionnez', 'considérez',
        'sourcez', 'ajoute', 'verifye', 'retire', 'presize',
    ];

    /** Words about sourcing and authorship that prose rarely gains in an edit. */
    private const INSTRUCTION_WORDS = '/\b(sources?|sourcing|sourced|citations?|cite[sd]?|citing|footnotes?|endnotes?|attribution|fact-?check\w*|verify|verifying|the author|the writer|l’auteur|l\'auteur|referans)\b/iu';

    /** Phrases that address the author rather than the reader. */
    private const META = '/\b(source note|add(ing)? (a |an )?note|note that|you (should|could|might|may|can)|i (would|suggest|recommend)|we (suggest|recommend)|it would be (better|clearer|good|stronger)|consider(ing)?|e\.g\.|or similar|something like|for instance:)\b/iu';

    /** A placeholder for the author to fill: [year], <name>, TK, XX. */
    private const PLACEHOLDER = '/\[[^\]]{1,40}\]|<[^>]{1,40}>|\bTK\b|\bXX+\b/u';

    /** Is this suggestion advice to the author rather than replacement text? */
    public static function isAdvice(?string $excerpt, ?string $suggestion): bool
    {
        $suggestion = trim((string) $suggestion);
        $excerpt = trim((string) $excerpt);

        if ($suggestion === '') {
            return false;
        }

        // Nothing to replace: whatever it says, it cannot be applied.
        if ($excerpt === '') {
            return true;
        }

        $lowerExcerpt = mb_strtolower($excerpt);

        // 1. Starts with an editor's verb the excerpt does not start with.
        $first = self::firstWord($suggestion);

        if (in_array($first, self::IMPERATIVES, true) && self::firstWord($excerpt) !== $first) {
            return true;
        }

        // 2. An editor's verb, capitalised, after a colon or a sentence end:
        //    "In her own words: Add a source note…".
        if (preg_match_all('/(?:^|[:.;!?—–]\s+)["“«(]?(\p{Lu}[\p{L}-]+)/u', $suggestion, $m)) {
            foreach ($m[1] as $word) {
                $word = mb_strtolower($word);

                if (in_array($word, self::IMPERATIVES, true) && ! preg_match('/\b'.preg_quote($word, '/').'\b/u', $lowerExcerpt)) {
                    return true;
                }
            }
        }

        // 3. Sourcing or authorship vocabulary the excerpt did not have.
        foreach ([self::INSTRUCTION_WORDS, self::META, self::PLACEHOLDER] as $pattern) {
            if (preg_match_all($pattern, $suggestion, $m)) {
                foreach ($m[0] as $hit) {
                    if (! str_contains($lowerExcerpt, mb_strtolower($hit))) {
                        return true;
                    }
                }
            }
        }

        // 4. A question back to the author.
        if (str_ends_with($suggestion, '?') && ! str_contains($excerpt, '?')) {
            return true;
        }

        // 5. Far longer than what it replaces.
        if (mb_strlen($suggestion) > 2.5 * mb_strlen($excerpt) + 60) {
            return true;
        }

        // 6. Structurally unlike: a long passage that shares almost none of
        //    its words with the "replacement" is being described, not edited.
        $words = self::words($excerpt);

        if (count($words) >= 8) {
            $kept = count(array_intersect($words, self::words($suggestion)));

            if ($kept / count($words) < 0.25) {
                return true;
            }
        }

        return false;
    }

    /** @var array<int, list<array{text: string, cited: bool}>> blocks per section */
    private array $blocks = [];

    /**
     * Where the excerpt sits: 'cited_quote' inside quotation marks in a
     * passage with a citation attached, 'quote' inside quotation marks,
     * 'citation' in cited text, or null.
     */
    public function context(ManuscriptSection $section, ?string $excerpt): ?string
    {
        $excerpt = (string) $excerpt;

        if ($excerpt === '') {
            return null;
        }

        foreach ($this->blocksOf($section) as $block) {
            $at = strpos($block['text'], $excerpt);

            if ($at === false) {
                continue;
            }

            $end = $at + strlen($excerpt);
            $quoted = false;

            // Curly, guillemet and straight quotation marks; an opening mark
            // with no close in the paragraph runs to its end (a quotation
            // over several paragraphs).
            if (preg_match_all('/“[^”]*(?:”|$)|«[^»]*(?:»|$)|„[^“”]*(?:[“”]|$)|"[^"]*(?:"|$)/u', $block['text'], $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$quote, $start]) {
                    if ($at < $start + strlen($quote) && $end > $start) {
                        $quoted = true;
                    }
                }
            }

            if ($quoted) {
                return $block['cited'] ? 'cited_quote' : 'quote';
            }

            return $block['cited'] ? 'citation' : null;
        }

        return null;
    }

    /** @return list<array{text: string, cited: bool}> */
    private function blocksOf(ManuscriptSection $section): array
    {
        if (isset($this->blocks[$section->id])) {
            return $this->blocks[$section->id];
        }

        $cited = Citation::where('manuscript_section_id', $section->id)
            ->pluck('excerpt')
            ->map(fn ($e) => trim((string) $e))
            ->filter(fn ($e) => mb_strlen($e) >= 8)
            ->values()
            ->all();

        $blocks = [];
        $this->collect($section->content ?? [], $blocks);

        foreach ($blocks as &$block) {
            $block['cited'] = $block['note']
                // An author-date marker: "(Danticat 2007)", "(NPS, 2020, 4)".
                || preg_match('/\([^()]*\b(1[5-9]|20)\d{2}[a-z]?\b[^()]*\)/u', $block['text'])
                || collect($cited)->contains(fn ($c) => str_contains($block['text'], $c) || str_contains($c, $block['text']));
            unset($block['note']);
        }

        return $this->blocks[$section->id] = $blocks;
    }

    /**
     * Every text block (a paragraph, a heading) with its text, and whether
     * a note marker sits in it.
     *
     * @param  array<string, mixed>  $node
     * @param  list<array{text: string, note: bool}>  $blocks
     */
    private function collect(array $node, array &$blocks): void
    {
        $children = $node['content'] ?? [];
        $inline = collect($children)->contains(fn ($c) => ($c['type'] ?? null) === 'text');

        if ($inline) {
            $text = '';
            $note = false;

            foreach ($children as $child) {
                match ($child['type'] ?? null) {
                    'text' => $text .= $child['text'] ?? '',
                    'hardBreak' => $text .= "\n",
                    'footnote' => $note = true,
                    default => null,
                };
            }

            $blocks[] = ['text' => $text, 'note' => $note];

            return;
        }

        foreach ($children as $child) {
            if (is_array($child)) {
                $this->collect($child, $blocks);
            }
        }
    }

    private static function firstWord(string $text): string
    {
        preg_match('/[\p{L}][\p{L}-]*/u', $text, $m);

        return mb_strtolower($m[0] ?? '');
    }

    /** @return list<string> distinct words of three letters or more */
    private static function words(string $text): array
    {
        preg_match_all('/\p{L}{3,}/u', mb_strtolower($text), $m);

        return array_values(array_unique($m[0]));
    }
}
