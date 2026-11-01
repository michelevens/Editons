<?php

namespace App\Research\Assistant;

use App\Editorial\Finding;
use App\Editorial\ReviewContext;
use App\Models\Book;
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
