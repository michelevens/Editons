<?php

namespace App\Import;

/**
 * What an import created, and what it could not carry.
 *
 * The skipped list is the point. An importer that quietly drops what it does
 * not understand leaves the author to discover the gap at proof stage; one
 * that says "3 code blocks, 2 images" lets them decide what to do about it.
 */
class ImportReport
{
    public int $sections = 0;

    /** @var array<int, string> The chapters created, in binder order. */
    public array $titles = [];

    /** @var array<string, int> */
    public array $skipped = [];

    /** @var int Pictures brought in with the text (DOCX). */
    public int $images = 0;

    public function skip(string $what): void
    {
        $this->skipped[$what] = ($this->skipped[$what] ?? 0) + 1;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sections' => $this->sections,
            'titles' => $this->titles,
            'images' => $this->images,
            'skipped' => $this->skipped,
            'lossless' => $this->skipped === [],
        ];
    }
}
