<?php

namespace App\Ai;

/**
 * What a writer returned, and what it cost.
 *
 * Tokens are as the provider reported them: `inputTokens` is the uncached
 * part only, so a prompt's full size is input + cacheWrite + cacheRead.
 */
final class WriterResult
{
    /** @param  array<string, mixed>|null  $json */
    public function __construct(
        public readonly string $text,
        public readonly string $model,
        public readonly ?array $json = null,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $cacheWriteTokens = 0,
        public readonly int $cacheReadTokens = 0,
        public readonly ?float $costUsd = null,
        public readonly ?string $stopReason = null,
    ) {}

    public function truncated(): bool
    {
        return $this->stopReason === 'max_tokens';
    }
}
