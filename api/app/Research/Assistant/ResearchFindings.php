<?php

namespace App\Research\Assistant;

/**
 * What a research run found: only sources the model actually retrieved, each
 * with a passage quoted from the page and what it supports.
 *
 * Each source: {url, title, publisher, published, excerpt, summary, sample}.
 */
final class ResearchFindings
{
    /**
     * @param  list<array<string, mixed>>  $sources
     */
    public function __construct(
        public readonly array $sources,
        public readonly ?string $summary,
        public readonly ?string $model,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly int $searches = 0,
        public readonly ?float $costUsd = null,
    ) {}
}
