<?php

namespace App\Editorial;

use App\Models\EditorialFinding;
use App\Models\ManuscriptSection;
use App\Services\ManuscriptWriter;
use RuntimeException;

/**
 * Applies a finding's suggested replacement to the manuscript.
 *
 * Until now "Apply fix" marked a finding accepted and left the text alone, so
 * the suggestion vanished from the panel while the prose it described stayed
 * exactly as it was. That is the worst shape of failure: the interface agreed
 * with the author and the document disagreed.
 *
 * The replacement is made against the stored excerpt, not an offset. Offsets
 * drift the moment anything above the passage changes, and a stale offset
 * would rewrite the wrong sentence — far worse than refusing.
 */
class SuggestionApplier
{
    public function __construct(private readonly ManuscriptWriter $writer) {}

    /**
     * Replace the finding's excerpt with its suggestion.
     *
     * @throws RuntimeException when the passage is gone or ambiguous
     */
    public function apply(EditorialFinding $finding, ?int $userId = null): ManuscriptSection
    {
        $section = $finding->section;

        if (! $finding->suggestion) {
            throw new RuntimeException('This finding offers no replacement to apply.');
        }

        if (! $finding->excerpt) {
            throw new RuntimeException('This finding is not anchored to any text.');
        }

        $content = $section->content ?? ['type' => 'doc', 'content' => []];
        $occurrences = $this->countOccurrences($content, $finding->excerpt);

        if ($occurrences === 0) {
            throw new RuntimeException(
                'That passage is no longer in the chapter. The suggestion is out of date.',
            );
        }

        // Refuse rather than guess. Two identical passages and one suggestion
        // means the application cannot know which the reviewer meant, and
        // rewriting the wrong one is worse than doing nothing.
        if ($occurrences > 1) {
            throw new RuntimeException(
                'That passage appears more than once, so it is not clear which to change. Edit it by hand.',
            );
        }

        $updated = $this->replaceFirst($content, $finding->excerpt, $finding->suggestion);

        // Through the normal writer: an AI edit is versioned and revertible
        // exactly like something typed, which is what makes accepting one
        // safe to do.
        return $this->writer->save(
            section: $section,
            content: $updated,
            reason: 'manual',
            userId: $userId,
            label: "Applied: {$finding->title}",
        );
    }

    /** @param array<string, mixed> $node */
    private function countOccurrences(array $node, string $needle): int
    {
        $count = 0;

        if (($node['type'] ?? null) === 'text') {
            return substr_count($node['text'] ?? '', $needle);
        }

        foreach ($node['content'] ?? [] as $child) {
            $count += $this->countOccurrences($child, $needle);
        }

        return $count;
    }

    /**
     * Replace the first occurrence, walking the document tree.
     *
     * Operates on text nodes rather than on serialised HTML, so marks and
     * structure survive: replacing inside the JSON cannot accidentally eat a
     * heading or drop a bold span the way a string replace over markup would.
     *
     * The known limit: an excerpt split across two text nodes — because half
     * of it is bold, say — is not found. Those findings are refused above
     * rather than half-applied.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function replaceFirst(array $node, string $needle, string $replacement, bool &$done = false): array
    {
        if ($done) {
            return $node;
        }

        if (($node['type'] ?? null) === 'text') {
            $text = $node['text'] ?? '';

            if (str_contains($text, $needle)) {
                $position = mb_strpos($text, $needle);

                $node['text'] = mb_substr($text, 0, $position)
                    .$replacement
                    .mb_substr($text, $position + mb_strlen($needle));

                $done = true;
            }

            return $node;
        }

        if (isset($node['content'])) {
            foreach ($node['content'] as $index => $child) {
                $node['content'][$index] = $this->replaceFirst($child, $needle, $replacement, $done);

                if ($done) {
                    break;
                }
            }
        }

        return $node;
    }
}
