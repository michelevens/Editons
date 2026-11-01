<?php

namespace App\Ai;

/**
 * One call to a writer.
 *
 * Laid out for prompt caching: `system` (the writer's fixed rules) and
 * `shared` (the book's plan and style sheet) are the same for every
 * chapter of a book and come first; everything that changes per call
 * (summaries of earlier chapters, this chapter's brief) is in `prompt`.
 * `documents` are untrusted material (imported text, research notes),
 * delivered as data blocks.
 *
 * `task` and `data` let the sample writer answer in kind without reading
 * the prompt: plan, chapter, summary, consistency.
 */
final class WriterRequest
{
    /**
     * @param  array<int, array{title: string, text: string}>  $documents
     * @param  array<string, mixed>|null  $schema  JSON schema the answer must match
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $task,
        public readonly string $system,
        public readonly string $prompt,
        public readonly string $shared = '',
        public readonly array $documents = [],
        public readonly ?array $schema = null,
        public readonly int $maxTokens = 16000,
        public readonly string $quality = 'standard',
        public readonly array $data = [],
    ) {}

    /** Characters sent, for the estimate before a call. */
    public function characters(): int
    {
        return mb_strlen($this->system) + mb_strlen($this->shared) + mb_strlen($this->prompt)
            + array_sum(array_map(fn ($d) => mb_strlen($d['text']), $this->documents));
    }
}
