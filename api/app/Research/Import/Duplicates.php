<?php

namespace App\Research\Import;

/**
 * Remembers what a library already holds, so an import can say "already in
 * your library" (or "twice in this file") instead of creating a second copy.
 *
 * Same DOI, or same address, is the same work. Same folded title is too —
 * unless both records carry DOIs and they differ: two papers called
 * "Introduction" are not one paper.
 */
final class Duplicates
{
    /** @var array<string, array{label: string, doi: ?string}> */
    private array $seen = [];

    /** @param  iterable<array<string, mixed>>  $entries  each with a 'label' */
    public function __construct(iterable $entries = [])
    {
        foreach ($entries as $entry) {
            $this->add($entry, (string) ($entry['label'] ?? $entry['title'] ?? ''));
        }
    }

    /**
     * The label of the record this one repeats, or null.
     *
     * @param  array<string, mixed>  $entry
     */
    public function of(array $entry): ?string
    {
        $doi = Entry::doi($entry['doi'] ?? null);

        foreach (Entry::keys($entry) as $key) {
            if (! isset($this->seen[$key])) {
                continue;
            }

            $other = $this->seen[$key];

            if (str_starts_with($key, 'title:') && $doi && $other['doi'] && strcasecmp($doi, $other['doi']) !== 0) {
                continue;
            }

            return $other['label'];
        }

        return null;
    }

    /** @param  array<string, mixed>  $entry */
    public function add(array $entry, string $label): void
    {
        $doi = Entry::doi($entry['doi'] ?? null);

        foreach (Entry::keys($entry) as $key) {
            $this->seen[$key] ??= ['label' => $label, 'doi' => $doi];
        }
    }
}
