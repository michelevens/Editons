<?php

namespace App\Import;

use App\Models\ManuscriptSection;

/**
 * What the author chose for an import, beyond the file (ADR — AI-written
 * imports).
 *
 * Both are opt-in; with neither, an import is exactly what it was: every
 * section a chapter, its first save an `import`.
 *
 *  - $types: a section type per section, in the file's order, from the
 *    import preview. Missing or unknown entries stay `chapter`.
 *  - $aiLabel: the text was written with an AI tool. Each section's first
 *    version is then saved with reason `ai_draft` and this label, which is
 *    what AiDisclosure reads as evidence for the KDP AI question.
 */
final class ImportOptions
{
    /** The types an author may give an imported section. */
    public const TYPES = ['front_matter', 'part', 'chapter', 'back_matter'];

    /** @param  list<string>|null  $types */
    public function __construct(
        public readonly ?array $types = null,
        public readonly ?string $aiLabel = null,
    ) {}

    /** The type for the section at $index (0-based), `chapter` when not chosen. */
    public function typeAt(int $index): string
    {
        $type = $this->types[$index] ?? null;

        return in_array($type, self::TYPES, true) && in_array($type, ManuscriptSection::TYPES, true) ? $type : 'chapter';
    }
}
