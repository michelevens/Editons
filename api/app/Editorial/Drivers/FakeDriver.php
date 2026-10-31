<?php

namespace App\Editorial\Drivers;

use App\Editorial\Contracts\EditorialDriver;
use App\Editorial\Contracts\SimulatesReview;
use App\Editorial\EditorialAgent;
use App\Editorial\Finding;
use App\Editorial\ReviewResult;

/**
 * Editorial review without an AI provider.
 *
 * This is NOT a stub returning canned text. It runs real heuristics over the
 * actual manuscript — long sentences, passive voice, hedging, unsourced
 * numeric claims, repeated openings — so the findings genuinely describe the
 * text in front of the author, and every layer above (job, storage, panel,
 * accept/reject) is exercised against real data.
 *
 * Why it exists: everything above the EditorialDriver seam can be finished
 * and tested with no API key and no spend. Swapping in Anthropic later
 * changes one binding; nothing above it moves.
 *
 * Its limits, stated plainly because the UI says so too: heuristics catch
 * mechanical problems. They cannot assess an argument, verify a fact, or
 * judge whether a chapter earns its place. Those need a model.
 */
class FakeDriver implements EditorialDriver, SimulatesReview
{
    /** Sentences longer than this are hard to follow in print. */
    private const LONG_SENTENCE_WORDS = 34;

    private const HEDGES = [
        'very', 'really', 'quite', 'somewhat', 'rather', 'fairly',
        'arguably', 'basically', 'essentially', 'actually', 'simply',
    ];

    public function name(): string
    {
        return 'fake';
    }

    public function review(EditorialAgent $agent, string $text): ReviewResult
    {
        $findings = match ($agent) {
            EditorialAgent::Line => $this->lineEdits($text),
            EditorialAgent::Copy => $this->copyEdits($text),
            EditorialAgent::FactChecker => $this->claimsNeedingSources($text),
            EditorialAgent::Developmental => $this->structure($text),
            EditorialAgent::Consistency => $this->consistency($text),
            EditorialAgent::Accessibility => $this->readability($text),
            EditorialAgent::SubjectMatter => $this->subjectMatter($text),
            EditorialAgent::MastersExaminer => $this->examiner($text, doctoral: false),
            EditorialAgent::DoctoralExaminer => $this->examiner($text, doctoral: true),
        };

        return new ReviewResult(findings: $findings, model: null, costUsd: 0.0);
    }

    /** @return array<int, Finding> */
    private function lineEdits(string $text): array
    {
        $out = [];

        foreach ($this->sentences($text) as [$sentence, $offset]) {
            $words = str_word_count($sentence);

            if ($words > self::LONG_SENTENCE_WORDS) {
                $out[] = new Finding(
                    severity: 'suggestion',
                    title: "Long sentence ({$words} words)",
                    detail: 'Sentences beyond about '.self::LONG_SENTENCE_WORDS
                        .' words are hard to hold in a single breath. Consider splitting at a natural clause boundary.',
                    excerpt: $this->clip($sentence),
                    fromOffset: $offset,
                    toOffset: $offset + mb_strlen($sentence),
                );
            }

            // Passive voice: "was/were/is/are/been" followed by a past
            // participle. Crude — English participles are irregular — so this
            // is reported as a suggestion, never a correction.
            if (preg_match('/\b(was|were|is|are|been|being)\s+(\w+ed|written|given|taken|made|shown|known|held)\b/i', $sentence, $m)) {
                $out[] = new Finding(
                    severity: 'suggestion',
                    title: 'Possible passive voice',
                    detail: "\"{$m[0]}\" reads as passive. Active phrasing is usually clearer, though passive is correct when the actor is unknown or irrelevant.",
                    excerpt: $this->clip($sentence),
                    fromOffset: $offset,
                    toOffset: $offset + mb_strlen($sentence),
                );
            }
        }

        return $out;
    }

    /** @return array<int, Finding> */
    private function copyEdits(string $text): array
    {
        $out = [];

        foreach (self::HEDGES as $word) {
            if (preg_match_all('/\b'.preg_quote($word, '/').'\b/i', $text, $m, PREG_OFFSET_CAPTURE)) {
                $count = count($m[0]);
                $fix = $this->hedgeFix($text, $word);
                $at = $fix[2] ?? $m[0][0][1];
                $out[] = new Finding(
                    severity: 'suggestion',
                    title: "Filler word: \"{$word}\" ×{$count}",
                    detail: 'Hedges and intensifiers usually weaken a sentence. Removing them rarely changes the meaning.'
                        .($fix && $count > 1 ? ' The fix removes one; the others are yours to judge.' : ''),
                    excerpt: $fix[0] ?? $this->around($text, $m[0][0][1], mb_strlen($word)),
                    // A concrete deletion when the word sits mid-sentence
                    // between two words; at the start of a sentence or
                    // before punctuation, removing it needs rewording, so
                    // the finding stays advice (ADR — Journey friction fixes).
                    suggestion: $fix[1] ?? null,
                    fromOffset: $at,
                    toOffset: $at + mb_strlen($word),
                );
            }
        }

        // Doubled words — "the the" — which spellcheckers routinely miss.
        if (preg_match_all('/\b(\w+)\s+\1\b/i', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $hit) {
                // The excerpt carries trailing context, not just the repeated
                // pair.
                //
                // "The the" alone is not unique in a book that says it twice,
                // and an ambiguous anchor cannot be applied — the suggestion
                // is then permanently un-actionable. Widening makes it
                // locatable.
                [$excerpt, $replacement] = $this->widen($text, $hit[0], $hit[1]);

                $out[] = new Finding(
                    severity: 'warning',
                    title: 'Repeated word',
                    detail: 'The same word appears twice in a row.',
                    excerpt: $excerpt,
                    suggestion: $replacement,
                    fromOffset: $hit[1],
                    toOffset: $hit[1] + mb_strlen($excerpt),
                );
            }
        }

        // Double spaces after a sentence — a typesetting artefact. Spaces
        // only: a paragraph break is whitespace too in the plain text, and
        // flagging every one of those was a false finding. Each carries the
        // collapsed spacing, so "Apply fix" works without a model.
        if (preg_match_all('/(?<=[.!?])[ \t\x{00A0}]{2,}(?=\S)/u', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach (array_slice($m[0], 0, 10) as [$spaces, $at]) {
                $span = $this->uniqueSpan($text, $at, $at + strlen($spaces));

                $out[] = new Finding(
                    severity: 'suggestion',
                    title: 'Double space between sentences',
                    detail: 'Single spacing is the convention in typeset books.',
                    excerpt: $span[0] ?? trim(substr($text, max(0, $at - 1), strlen($spaces) + 2)),
                    suggestion: $span ? $span[1].' '.$span[2] : null,
                    fromOffset: $at,
                );
            }
        }

        return $out;
    }

    /**
     * Numeric and absolute claims that a publisher would want sourced.
     *
     * @return array<int, Finding>
     */
    private function claimsNeedingSources(string $text): array
    {
        $out = [];

        foreach ($this->sentences($text) as [$sentence, $offset]) {
            $hasStat = (bool) preg_match('/\b\d+(\.\d+)?\s*(%|percent|million|billion|thousand)\b/i', $sentence);
            $hasAbsolute = (bool) preg_match('/\b(always|never|all|none|every|proven|guaranteed|the only)\b/i', $sentence);

            if ($hasStat) {
                $out[] = new Finding(
                    severity: 'critical',
                    title: 'Statistic without a citation',
                    detail: 'A figure stated without a source cannot be verified by a reader or a fact-checker. Attach a citation or soften the claim.',
                    excerpt: $this->clip($sentence),
                    fromOffset: $offset,
                    toOffset: $offset + mb_strlen($sentence),
                );
            } elseif ($hasAbsolute) {
                $out[] = new Finding(
                    severity: 'warning',
                    title: 'Absolute claim',
                    detail: 'Absolutes invite counter-examples. Either evidence it or qualify it.',
                    excerpt: $this->clip($sentence),
                    fromOffset: $offset,
                    toOffset: $offset + mb_strlen($sentence),
                );
            }
        }

        return $out;
    }

    /** @return array<int, Finding> */
    private function structure(string $text): array
    {
        $out = [];
        $words = str_word_count($text);
        $paragraphs = preg_split('/\n\s*\n/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words > 0 && $words < 300) {
            $out[] = new Finding(
                severity: 'suggestion',
                title: 'Short chapter ('.number_format($words).' words)',
                detail: 'Most nonfiction chapters run 1,500–5,000 words. A short one can work as a deliberate interlude, but check it is not an unfinished draft.',
            );
        }

        foreach ($paragraphs as $p) {
            if (str_word_count($p) > 220) {
                $out[] = new Finding(
                    severity: 'suggestion',
                    title: 'Dense paragraph',
                    detail: 'A paragraph over roughly 200 words is a wall of text on a printed page. Look for a natural break.',
                    excerpt: $this->clip($p),
                );
                break; // One is enough to make the point.
            }
        }

        // Repeated sentence openings — a pattern readers notice and writers
        // rarely do.
        $openers = [];
        foreach ($this->sentences($text) as [$s, $_]) {
            $first = strtolower(strtok(trim($s), " \t\n"));
            if (mb_strlen($first) > 2) {
                $openers[$first] = ($openers[$first] ?? 0) + 1;
            }
        }
        foreach ($openers as $word => $n) {
            if ($n >= 3) {
                $out[] = new Finding(
                    severity: 'suggestion',
                    title: "Repeated sentence opening: \"{$word}\" ×{$n}",
                    detail: 'Varying how sentences begin keeps the prose from feeling mechanical.',
                );
            }
        }

        return $out;
    }

    /** @return array<int, Finding> */
    private function consistency(string $text): array
    {
        $out = [];

        // British vs American spelling in the same manuscript.
        $pairs = [
            ['organise', 'organize'], ['colour', 'color'], ['centre', 'center'],
            ['licence', 'license'], ['practise', 'practice'], ['analyse', 'analyze'],
        ];

        foreach ($pairs as [$gb, $us]) {
            $hasGb = preg_match('/\b'.$gb.'\w*\b/i', $text);
            $hasUs = preg_match('/\b'.$us.'\w*\b/i', $text);
            if ($hasGb && $hasUs) {
                $out[] = new Finding(
                    severity: 'warning',
                    title: "Mixed spelling: \"{$gb}\" and \"{$us}\"",
                    detail: 'Both variants appear. Pick one and apply it across the manuscript.',
                );
            }
        }

        return $out;
    }

    /** @return array<int, Finding> */
    private function readability(string $text): array
    {
        $sentences = $this->sentences($text);
        if ($sentences === []) {
            return [];
        }

        $words = str_word_count($text);
        $avg = $words / count($sentences);

        if ($avg > 25) {
            return [new Finding(
                severity: 'warning',
                title: 'Average sentence length is '.round($avg).' words',
                detail: 'Long average sentence length makes a text harder for readers with cognitive or language barriers. Around 15–20 words suits general nonfiction.',
            )];
        }

        return [];
    }

    /** @return array<int, Finding> */
    private function subjectMatter(string $text): array
    {
        // Phrases that carry regulatory weight and should be checked by a
        // qualified reviewer rather than asserted.
        $flags = ['guarantee', 'cure', 'risk-free', 'certified', 'licensed', 'compliant', 'FDA', 'HIPAA'];
        $out = [];

        foreach ($flags as $f) {
            if (preg_match('/\b'.preg_quote($f, '/').'\b/i', $text, $m, PREG_OFFSET_CAPTURE)) {
                $out[] = new Finding(
                    severity: 'warning',
                    title: "Regulated term: \"{$f}\"",
                    detail: 'This word carries legal or regulatory meaning. Have a qualified reviewer confirm the claim before publication.',
                    excerpt: $this->around($text, $m[0][1], mb_strlen($f)),
                    fromOffset: $m[0][1],
                );
            }
        }

        return $out;
    }

    /**
     * What an examiner checks first, by pattern: an aim, citations behind
     * empirical claims, overclaiming, and at doctoral level a stated
     * contribution and limitations. Findings only, never replacement text.
     *
     * @return array<int, Finding>
     */
    private function examiner(string $text, bool $doctoral): array
    {
        $out = [];
        $sentences = $this->sentences($text);

        if ($sentences === []) {
            return [];
        }

        $citation = '/\(\s*[A-Z][^()]{0,80}?\d{4}[a-z]?\s*(,\s*p+\.\s*\d+)?\)|\[\d+([,\-–]\s*\d+)*\]|\bet al\./u';

        if (! preg_match('/research question|\b(aim|aims|objective|purpose)\b|this (study|thesis|dissertation|chapter) (examines|investigates|argues|explores|asks)|hypothes/i', $text)) {
            [$first, $at] = $sentences[0];
            $out[] = new Finding(
                severity: 'warning',
                title: 'No research aim in view',
                detail: 'An examiner looks for how each chapter serves the research question. Say which question or aim this chapter addresses, in your own words.',
                excerpt: $first,
                fromOffset: $at,
            );
        }

        foreach ($sentences as [$sentence, $at]) {
            if (count($out) >= 12) {
                break;
            }

            // An appeal to outside evidence, not any number: a thesis's own
            // results ("accuracy was 79.41%") need no citation, and flagging
            // them buried the real findings in a results chapter.
            $empirical = preg_match('/\b(studies|research|evidence|surveys?|the literature|scholars|experts) (show|shows|suggest|suggests|indicate|indicates|demonstrate|demonstrates|has shown|have shown|agree)\b|\bit is (well )?(known|established|accepted)\b|\b(widely|generally|commonly) (believed|accepted|known)\b/i', $sentence);

            if ($empirical && ! preg_match($citation, $sentence)) {
                $out[] = new Finding(
                    severity: 'warning',
                    title: 'Claim without a citation',
                    detail: 'This states something as established without citing where it comes from. Cite the source you relied on, or present it as your own finding with the evidence for it.',
                    excerpt: $sentence,
                    fromOffset: $at,
                );

                continue;
            }

            if (preg_match('/\b(proves?|proven|undeniabl[ey]|always|never|in all cases|clearly (shows|demonstrates)|without (a )?doubt)\b/i', $sentence)) {
                $out[] = new Finding(
                    severity: 'suggestion',
                    title: 'Possible overclaim',
                    detail: 'Absolute language invites the examiner to find the counter-example. Consider whether your evidence supports this strength of claim, and say what it is limited to.',
                    excerpt: $sentence,
                    fromOffset: $at,
                );
            }
        }

        if ($doctoral && ! preg_match('/contribut/i', $text)) {
            [$first, $at] = $sentences[0];
            $out[] = new Finding(
                severity: 'suggestion',
                title: 'Contribution not stated here',
                detail: 'A doctoral examiner will ask what this chapter adds to knowledge. If it has a part in the contribution, say what that part is.',
                excerpt: $first,
                fromOffset: $at,
            );
        }

        return $out;
    }

    /**
     * Split into sentences, keeping each one's offset.
     *
     * @return array<int, array{0: string, 1: int}>
     */
    private function sentences(string $text): array
    {
        $out = [];
        // A full stop only ends a sentence when a space or the end follows
        // it, so "73.48%", "e.g." mid-word and "U.S." stay inside theirs.
        if (preg_match_all('/(?:[^.!?]|[.!?](?=\S))+[.!?]+/u', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$s, $offset]) {
                $trimmed = trim($s);
                if ($trimmed !== '') {
                    $out[] = [$trimmed, $offset];
                }
            }
        }

        return $out;
    }

    private function clip(string $s, int $max = 180): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s));

        return mb_strlen($s) <= $max ? $s : mb_substr($s, 0, $max - 1).'…';
    }

    private function around(string $text, int $offset, int $length, int $pad = 60): string
    {
        $start = max(0, $offset - $pad);

        return $this->clip(mb_substr($text, $start, $length + $pad * 2));
    }

    /**
     * A filler word that can simply be deleted: mid-sentence, with a word
     * on each side. Returns [excerpt, replacement, byte offset] anchored on
     * text that occurs once, or null when no occurrence qualifies.
     *
     * @return array{0: string, 1: string, 2: int}|null
     */
    private function hedgeFix(string $text, string $word): ?array
    {
        if (! preg_match_all('/(?<=[\p{L}\p{N},;] )'.preg_quote($word, '/').' (?=[\p{L}\p{N}])/iu', $text, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        foreach ($m[0] as [$hit, $at]) {
            if ($span = $this->uniqueSpan($text, $at, $at + strlen($hit))) {
                return [$span[0], rtrim($span[1]).' '.ltrim($span[2]), $at];
            }
        }

        return null;
    }

    /**
     * Grow a byte range by whole words on both sides, within its own line
     * (one paragraph: an excerpt that crosses a paragraph cannot be
     * applied), until the slice occurs once in the text.
     *
     * Returns [excerpt, text before the range, text after it], or null if
     * no width up to eight words is unique. At least one word beside the
     * range, so the excerpt reads as a phrase and the replacement is never
     * empty.
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    private function uniqueSpan(string $text, int $start, int $end): ?array
    {
        $lineStart = strrpos(substr($text, 0, $start), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $lineEnd = strpos($text, "\n", $end);
        $lineEnd = $lineEnd === false ? strlen($text) : $lineEnd;

        $before = substr($text, $lineStart, $start - $lineStart);
        $after = substr($text, $end, $lineEnd - $end);
        $core = substr($text, $start, $end - $start);

        foreach ([1, 2, 3, 5, 8] as $words) {
            preg_match('/(?:\S+\s*){0,'.$words.'}$/u', $before, $head);
            preg_match('/^(?:\s*\S+){0,'.$words.'}/u', $after, $tail);
            $head = $head[0] ?? '';
            $tail = $tail[0] ?? '';
            $candidate = $head.$core.$tail;

            if (trim($head.$tail) !== '' && substr_count($text, $candidate) === 1) {
                return [$candidate, $head, $tail];
            }
        }

        return null;
    }

    /**
     * Extend a match with trailing context until it is unique in the text.
     *
     * Returns the widened excerpt and the matching replacement. An anchor
     * that appears twice cannot be applied — the application has no way to
     * know which occurrence was meant — so a suggestion with an ambiguous
     * excerpt is permanently un-actionable.
     *
     * Grows a few words at a time rather than character by character, so the
     * excerpt stays something a person would recognise as a phrase, and stops
     * after ten: an anchor longer than that is more likely to go stale from an
     * unrelated edit than to disambiguate anything.
     *
     * @return array{0: string, 1: string}
     */
    private function widen(string $text, string $match, int $offset): array
    {
        $repeated = explode(' ', $match)[0];

        if (substr_count($text, $match) <= 1) {
            return [$match, $repeated];
        }

        $tail = mb_substr($text, $offset + mb_strlen($match));

        foreach ([3, 6, 10] as $words) {
            if (! preg_match('/^(\s+\S+){1,'.$words.'}/', $tail, $found)) {
                break;
            }

            $extra = rtrim($found[0]);
            $candidate = $match.$extra;

            if (substr_count($text, $candidate) === 1) {
                return [$candidate, $repeated.$extra];
            }
        }

        // Still ambiguous. Returning the plain match is the honest answer:
        // the applier refuses it rather than rewriting a passage it cannot
        // identify.
        return [$match, $repeated];
    }
}
