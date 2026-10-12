<?php

namespace App\Editorial;

/**
 * What a driver returns: findings plus the provenance CLAUDE.md requires
 * ("Record prompt/model/version and costs").
 *
 * A plain object rather than an array so the shape is checked at the boundary
 * — a driver that forgets a field fails immediately rather than writing a
 * half-formed review row.
 */
final class ReviewResult
{
    /**
     * @param  array<int, Finding>  $findings
     */
    public function __construct(
        public readonly array $findings,
        public readonly ?string $model = null,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?float $costUsd = null,
    ) {}
}
