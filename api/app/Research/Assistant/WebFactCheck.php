<?php

namespace App\Research\Assistant;

use App\Editorial\Finding;
use App\Models\ManuscriptSection;
use App\Models\User;
use Throwable;

/**
 * Web search for the claims the saved sources leave unverified, when the
 * author allows it (ADR — AI research, source-backed fact-checking, cover
 * templates).
 *
 * Each claim is one "Find sources for this claim" research run, charged
 * and logged like any other. What it finds is attached to the finding as
 * candidates: sources the AI retrieved, with their excerpts. The verdict
 * stays unverified: a page found on the web is not yet one of the book's
 * sources, and the author saves and cites it, or not.
 */
final class WebFactCheck
{
    /** Claims searched per review: each one is a charged run. */
    public const MAX_CLAIMS = 3;

    public function __construct(private readonly ResearchAssistant $assistant) {}

    /**
     * @param  array<int, Finding>  $findings  labelled by SourceEvidence
     * @return array<int, Finding>
     */
    public function attach(array $findings, ManuscriptSection $section, User $user): array
    {
        $searched = 0;

        foreach ($findings as $i => $f) {
            if ($searched >= self::MAX_CLAIMS) {
                break;
            }

            if (($f->evidence['verdict'] ?? null) !== 'unverified' || trim((string) $f->excerpt) === '') {
                continue;
            }

            $searched++;

            try {
                $run = $this->assistant->run($section->book, $user, 'claim', (string) $f->excerpt, $section);
            } catch (Throwable $e) {
                // Out of credits or paused: the rest of the review stands.
                $findings[$i] = $f->withEvidence($f->evidence + ['web' => 'skipped']);

                break;
            }

            $findings[$i] = $f->withEvidence($f->evidence + [
                'web' => $run->status,
                'candidates' => $run->status === 'completed' ? [
                    'run_id' => $run->id,
                    'simulated' => $run->simulated,
                    'results' => collect($run->results ?? [])->take(3)->map(fn (array $r, int $index) => [
                        'index' => $index,
                        'url' => $r['url'],
                        'title' => $r['title'],
                        'publisher' => $r['publisher'] ?? null,
                        'excerpt' => $r['excerpt'] ?? null,
                        'summary' => $r['summary'] ?? null,
                    ])->values()->all(),
                ] : null,
            ]);
        }

        return $findings;
    }
}
