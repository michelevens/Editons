<?php

namespace App\Research\Assistant;

/**
 * What a research run is asked (ADR — AI research, source-backed
 * fact-checking, cover templates).
 *
 * The question is the author's; the material (a chapter, the book's plan)
 * is manuscript text, sent as untrusted data in its own block. The profile
 * is author configuration: the kind of book, its genre, language and
 * reader, so sources suit the book.
 */
final class ResearchBrief
{
    /**
     * @param  'topic'|'chapter'|'plan'|'claim'  $mode
     * @param  array<string, string|null>  $profile
     */
    public function __construct(
        public readonly string $mode,
        public readonly string $question,
        public readonly ?string $material = null,
        public readonly string $language = 'English',
        public readonly array $profile = [],
    ) {}
}
