<?php

namespace App\Editorial\Drivers;

use App\Editorial\Contracts\EditorialDriver;
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
class FakeDriver implements EditorialDriver
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
                $out[] = new Finding(
                    severity: 'suggestion',
                    title: "Filler word: \"{$word}\" ×{$count}",
                    detail: 'Hedges and intensifiers usually weaken a sentence. Removing them rarely changes the meaning.',
                    excerpt: $this->around($text, $m[0][0][1], mb_strlen($word)),
                    suggestion: null,
                    fromOffset: $m[0][0][1],
                    toOffset: $m[0][0][1] + mb_strlen($word),
                );
            }
        }

        // Doubled words — "the the" — which spellcheckers routinely miss.
        if (preg_match_all('/\b(\w+)\s+\1\b/i', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $hit) {
                $out[] = new Finding(
                    severity: 'warning',
                    title: 'Repeated word',
                    detail: 'The same word appears twice in a row.',
                    excerpt: $hit[0],
                    suggestion: explode(' ', $hit[0])[0],
                    fromOffset: $hit[1],
                    toOffset: $hit[1] + mb_strlen($hit[0]),
                );
            }
        }

        // Double spaces after a sentence — a typesetting artefact.
        if (preg_match('/\.\s{2,}[A-Z]/', $text, $m, PREG_OFFSET_CAPTURE)) {
            $out[] = new Finding(
                severity: 'suggestion',
                title: 'Double space between sentences',
                detail: 'Single spacing is the convention in typeset books.',
                excerpt: trim($m[0][0]),
                fromOffset: $m[0][1],
            );
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
     * Split into sentences, keeping each one's offset.
     *
     * @return array<int, array{0: string, 1: int}>
     */
    private function sentences(string $text): array
    {
        $out = [];
        if (preg_match_all('/[^.!?]+[.!?]+/u', $text, $m, PREG_OFFSET_CAPTURE)) {
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
}
