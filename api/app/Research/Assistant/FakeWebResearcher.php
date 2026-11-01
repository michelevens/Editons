<?php

namespace App\Research\Assistant;

/**
 * Sample research for tests and development: three clearly labelled
 * placeholder sources on example.org (ADR — AI research, source-backed
 * fact-checking, cover templates). It calls nobody, is never charged, and
 * every result says it is a sample, so nobody mistakes it for a real page.
 */
class FakeWebResearcher implements WebResearcher
{
    public function name(): string
    {
        return 'fake';
    }

    public function simulated(): bool
    {
        return true;
    }

    public function research(ResearchBrief $brief): ResearchFindings
    {
        $subject = mb_substr(trim(preg_replace('/\s+/u', ' ', $brief->question) ?: 'this book'), 0, 80);
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($subject)) ?? '', '-') ?: 'topic';
        $sources = [];

        foreach ([1, 2, 3] as $n) {
            $sources[] = [
                'url' => "https://example.org/sample-source-{$n}/".mb_substr($slug, 0, 60),
                'title' => "Sample source {$n}: {$subject}",
                'publisher' => 'example.org (sample)',
                'published' => '2024',
                'excerpt' => "Sample excerpt {$n}. Not a real page: a real research run quotes the page it retrieved here.",
                'summary' => $brief->mode === 'claim'
                    ? "Sample: a retrieved source would say here whether it supports or contradicts “{$subject}”."
                    : "Sample: a retrieved source would say here what it supports about “{$subject}”.",
                'sample' => true,
            ];
        }

        return new ResearchFindings(
            sources: $sources,
            summary: 'Sample research: these placeholder sources show where real, retrieved sources will appear.',
            model: 'fake',
        );
    }
}
