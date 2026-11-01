<?php

namespace App\Research\Assistant;

use App\Editorial\Finding;
use App\Editorial\ReviewContext;
use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\ResearchSource;

/**
 * The fact-checker's evidence (ADR — AI research, source-backed
 * fact-checking, cover templates).
 *
 * Every fact-check finding leaves here labelled supported, contradicted or
 * unverified. "Supported" and "contradicted" always name one of the book's
 * saved sources and quote it; a verdict the source does not bear out (an
 * unknown source, an excerpt that is not in it) becomes "unverified". There
 * is no "verified": a claim checked against a source is supported by that
 * source, and the author still decides.
 */
final class SourceEvidence
{
    public const VERDICTS = ['supported', 'contradicted', 'unverified'];

    /** Most sources sent to the fact-checker, and the text of each. */
    public const MAX_SOURCES = 40;

    public const MAX_TEXT = 1500;

    /**
     * The saved sources a fact-check reads: everything but disputed ones,
     * with the text the author or a research run recorded.
     *
     * @return list<array{id: int, reference: string, title: string, url: ?string, text: string, verification: string}>
     */
    public static function sourcesFor(Book $book): array
    {
        return $book->sources()
            ->where('verification', '!=', 'disputed')
            ->orderByDesc('verification')
            ->orderBy('id')
            ->limit(self::MAX_SOURCES)
            ->get()
            ->map(fn (ResearchSource $s) => [
                'id' => $s->id,
                'reference' => $s->reference(),
                'title' => $s->title,
                'url' => $s->url,
                'text' => mb_substr(trim(implode("\n", array_filter([
                    $s->provenance['excerpt'] ?? null,
                    $s->notes,
                ]))), 0, self::MAX_TEXT),
                'verification' => $s->verification,
            ])
            ->values()
            ->all();
    }

    /** Most citations and notes sent with one chapter. */
    public const MAX_ATTACHED = 120;

    /**
     * What a chapter already cites (live walkthrough, 92 citations: the
     * reviewer could not see them and called cited facts unsourced).
     * Citations from the citation flow, with their source, and footnotes
     * with the text they follow. Neither is in plainText(), which findings
     * anchor to, so they travel as their own document.
     *
     * @return list<array<string, mixed>>
     */
    public static function attachedTo(ManuscriptSection $section): array
    {
        $out = [];

        foreach ($section->citations()->with('source')->orderBy('from_offset')->get() as $c) {
            $s = $c->source;
            $out[] = array_filter([
                'kind' => 'citation',
                'claim' => $c->claim,
                'cited_words' => $c->excerpt,
                'locator' => $c->locator,
                'status' => $c->status,
                'source_id' => $s?->id,
                'source' => $s ? array_filter([
                    'reference' => $s->reference(),
                    'authors' => $s->authors,
                    'title' => $s->title,
                    'url' => $s->url,
                    'excerpt' => mb_substr(trim(implode("\n", array_filter([$s->provenance['excerpt'] ?? null, $s->notes]))), 0, self::MAX_TEXT) ?: null,
                ]) : null,
            ], fn ($v) => $v !== null && $v !== '');
        }

        // Footnotes: an atom whose text is in its attrs, after the words it
        // annotates.
        $walk = function (array $node, string &$before) use (&$walk, &$out): void {
            if (($node['type'] ?? null) === 'footnote') {
                $out[] = [
                    'kind' => 'footnote',
                    'follows' => trim(mb_substr($before, -240)),
                    'note' => mb_substr(trim((string) ($node['attrs']['text'] ?? '')), 0, 800),
                ];

                return;
            }

            $before .= $node['text'] ?? '';

            foreach ($node['content'] ?? [] as $child) {
                if (is_array($child)) {
                    $walk($child, $before);
                }
            }
        };

        foreach (($section->content['content'] ?? []) as $block) {
            if (is_array($block)) {
                $before = '';
                $walk($block, $before);
            }
        }

        return array_slice(array_values(array_filter($out, fn ($a) => $a['kind'] !== 'footnote' || $a['note'] !== '')), 0, self::MAX_ATTACHED);
    }

    /**
     * A suggestion that is advice, not words for the book ("Add a source
     * note identifying where Danticat said this"): the real Fact Checker
     * returned these, and a bulk Accept wrote one into the book in place of
     * the quote. The suggestion must be literal replacement text or
     * nothing; advice goes in the detail.
     */
    public static function isInstruction(?string $suggestion, ?string $excerpt): bool
    {
        $s = trim((string) $suggestion);

        if ($s === '') {
            return false;
        }

        $imperative = '/^(please\s+)?(add|cite|include|insert|verify|check|double-check|confirm|consider|provide|attribute|specify|clarify|identify|source|supply|link|reference|qualify|support|replace|remove|delete|rephrase|reword|revise|soften|note|mention|ensure|use|find|look|state|give|ajoute[rz]?|citez?|indiquez?|précise[rz]?|vérifie[rz]?|mentionnez|remplacez|supprimez|mete|verifye|site|endike)\b/iu';

        if (! preg_match($imperative, $s)) {
            return (bool) preg_match('/\b(source note|citation needed|add a (source|citation|footnote|reference))\b|\[(citation|source)[^\]]*\]/iu', $s);
        }

        // An imperative that is also the excerpt's own opening ("Add two
        // cups of flour" for "Add two cup of flour") is replacement text:
        // most of its words are the excerpt's.
        $words = fn (string $t) => array_values(array_unique(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($t), -1, PREG_SPLIT_NO_EMPTY) ?: []));
        $mine = $words($s);
        $theirs = $words((string) $excerpt);

        return $mine === [] || count(array_intersect($mine, $theirs)) / count($mine) < 0.6;
    }

    /** The finding with any advice moved out of the suggestion into the detail. */
    public static function adviceToDetail(Finding $f): Finding
    {
        if (! self::isInstruction($f->suggestion, $f->excerpt)) {
            return $f;
        }

        return new Finding(
            severity: $f->severity,
            title: $f->title,
            detail: trim(implode(' ', array_filter([$f->detail, $f->suggestion]))),
            excerpt: $f->excerpt,
            suggestion: null,
            fromOffset: $f->fromOffset,
            toOffset: $f->toOffset,
            rule: $f->rule,
            term: $f->term,
            evidence: $f->evidence,
        );
    }

    /**
     * Label every finding. A driver's own verdict is checked against the
     * sources; a finding with none gets "unverified", or for sample output
     * a deterministic match on the saved sources.
     *
     * @param  array<int, Finding>  $findings
     * @return array<int, Finding>
     */
    public static function label(array $findings, ReviewContext $context, bool $simulated): array
    {
        $sources = collect($context->sources)->keyBy('id');

        return array_map(function (Finding $f) use ($sources, $simulated) {
            // Whatever the driver, a fact-check suggestion is replacement
            // text or nothing.
            $f = self::adviceToDetail($f);
            $e = $f->evidence;

            if ($e !== null && in_array($e['verdict'] ?? null, ['supported', 'contradicted'], true)) {
                $source = $sources->get((int) ($e['source_id'] ?? 0));
                $quote = (string) ($e['source_excerpt'] ?? '');

                if ($source && $quote !== '' && self::contains($source['text'].' '.$source['title'], $quote)) {
                    return $f->withEvidence(self::verdict($e['verdict'], $source, $quote));
                }
            }

            if ($simulated && $e === null && ($match = self::match((string) $f->excerpt, $sources->all()))) {
                return $f->withEvidence(self::verdict('supported', $match[0], $match[1]) + ['sample' => true]);
            }

            return $f->withEvidence(['verdict' => 'unverified', 'source_id' => null, 'source_excerpt' => null]);
        }, $findings);
    }

    /** @param array<string, mixed> $source */
    private static function verdict(string $verdict, array $source, string $quote): array
    {
        return [
            'verdict' => $verdict,
            'source_id' => $source['id'],
            'source_excerpt' => mb_substr($quote, 0, 600),
            'source_reference' => $source['reference'],
            'source_url' => $source['url'],
            'source_verification' => $source['verification'],
        ];
    }

    /** Is the quote in the text, ignoring spacing and case? */
    public static function contains(string $text, string $quote): bool
    {
        $fold = fn (string $s) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', str_replace(['“', '”', '’'], ['"', '"', "'"], $s)) ?? ''));

        return $fold($quote) !== '' && str_contains($fold($text), $fold($quote));
    }

    /**
     * Sample output's match: a saved source whose text holds a figure from
     * the claim and at least one more of its words. Returns the source and
     * the sentence of it that holds the figure.
     *
     * @param  array<int, array<string, mixed>>  $sources
     * @return array{0: array<string, mixed>, 1: string}|null
     */
    private static function match(string $claim, array $sources): ?array
    {
        preg_match_all('/\d[\d,.]*\d|\d/u', $claim, $numbers);
        preg_match_all('/\p{L}{5,}/u', mb_strtolower($claim), $words);

        foreach ($numbers[0] as $number) {
            foreach ($sources as $source) {
                $text = (string) $source['text'];

                if (! str_contains($text, $number)) {
                    continue;
                }

                $lower = mb_strtolower($text);

                if (! collect($words[0])->contains(fn ($w) => str_contains($lower, $w))) {
                    continue;
                }

                foreach (preg_split('/(?<=[.!?])\s+/u', $text) ?: [] as $sentence) {
                    if (str_contains($sentence, $number)) {
                        return [$source, trim($sentence, " \t\n“”\"")];
                    }
                }
            }
        }

        return null;
    }
}
