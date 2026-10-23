<?php

namespace App\Publishing;

/**
 * Tracked changes in a manuscript: what the book says while they are pending.
 *
 * In Suggest mode the editor never removes or adds text outright. Typing
 * carries an `insertion` mark and deleting wraps the text in a `deletion`
 * mark, each with the change's id, its author and when (ADR "Suggest mode").
 * Both stay in the canonical ProseMirror JSON until somebody accepts or
 * rejects them in the editor.
 *
 * A pending suggestion is a proposal, not the book. Every renderer therefore
 * reads the document as if each one had been rejected: inserted text is
 * left out and deleted text is printed as ordinary text. That is the only
 * reading that never prints words the author has not agreed to, and it is
 * the book as it stood before anybody suggested anything.
 */
final class Suggestions
{
    public const INSERTION = 'insertion';

    public const DELETION = 'deletion';

    /** Blocks that are dropped when everything in them was a pending insertion. */
    private const TEXTBLOCKS = ['paragraph', 'heading'];

    /** Containers that are dropped when the step above emptied them. */
    private const CONTAINERS = ['blockquote', 'listItem', 'bulletList', 'orderedList'];

    /** Table cells must hold a block; an emptied one keeps an empty paragraph. */
    private const CELLS = ['tableCell', 'tableHeader'];

    /**
     * The document with every pending suggestion rejected: what exports,
     * previews and the word count read.
     *
     * @param  array<string, mixed>|null  $doc
     * @return array<string, mixed>|null
     */
    public static function published(?array $doc): ?array
    {
        return self::resolve($doc, drop: self::INSERTION, keep: self::DELETION);
    }

    /**
     * The document with every pending suggestion accepted.
     *
     * @param  array<string, mixed>|null  $doc
     * @return array<string, mixed>|null
     */
    public static function accepted(?array $doc): ?array
    {
        return self::resolve($doc, drop: self::DELETION, keep: self::INSERTION);
    }

    /**
     * How many suggestions are pending: distinct change ids. A replacement
     * (deleted words and the words typed over them) is one change.
     *
     * @param  array<string, mixed>|null  $doc
     */
    public static function count(?array $doc): int
    {
        if (! $doc) {
            return 0;
        }

        $ids = [];
        $walk = function (array $node) use (&$walk, &$ids): void {
            foreach ($node['marks'] ?? [] as $mark) {
                if (is_array($mark) && in_array($mark['type'] ?? null, [self::INSERTION, self::DELETION], true)) {
                    // A mark without an id (hand-made JSON) counts on its own.
                    $ids[(string) ($mark['attrs']['id'] ?? 'unnamed-'.count($ids))] = true;
                }
            }

            foreach ($node['content'] ?? [] as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($doc);

        return count($ids);
    }

    /**
     * @param  array<string, mixed>|null  $doc
     * @return array<string, mixed>|null
     */
    private static function resolve(?array $doc, string $drop, string $keep): ?array
    {
        // Most documents never had a suggestion: leave them byte for byte.
        if (! $doc || self::count($doc) === 0) {
            return $doc;
        }

        return self::node($doc, $drop, $keep) ?? ['type' => 'doc', 'content' => []];
    }

    /**
     * One node resolved, or null when it goes.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    private static function node(array $node, string $drop, string $keep): ?array
    {
        if (isset($node['marks']) && is_array($node['marks'])) {
            $types = array_map(fn ($mark) => is_array($mark) ? ($mark['type'] ?? null) : null, $node['marks']);

            if (in_array($drop, $types, true)) {
                return null;
            }

            $marks = array_values(array_filter(
                $node['marks'],
                fn ($mark) => ! is_array($mark) || ($mark['type'] ?? null) !== $keep,
            ));

            if ($marks === []) {
                unset($node['marks']);
            } else {
                $node['marks'] = $marks;
            }
        }

        if (! isset($node['content']) || ! is_array($node['content'])) {
            return $node;
        }

        $had = $node['content'] !== [];
        $children = [];

        foreach ($node['content'] as $child) {
            if (! is_array($child)) {
                continue;
            }

            if (($child = self::node($child, $drop, $keep)) !== null) {
                $children[] = $child;
            }
        }

        $children = self::merge($children);
        $type = $node['type'] ?? '';

        if ($had && $children === []) {
            // A paragraph typed whole as a suggestion is not in the book
            // until it is accepted; leaving it as a blank line would be.
            if (in_array($type, self::TEXTBLOCKS, true) || in_array($type, self::CONTAINERS, true)) {
                return null;
            }

            if (in_array($type, self::CELLS, true)) {
                $children = [['type' => 'paragraph']];
            }
        }

        $node['content'] = $children;

        return $node;
    }

    /**
     * Join neighbouring text nodes that now carry the same marks.
     *
     * "hel" and "lo" split by a deletion are one word again once the
     * deletion is gone; the Markdown renderer would otherwise write
     * `**hel****lo**`, which is not bold.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<int, array<string, mixed>>
     */
    private static function merge(array $nodes): array
    {
        $out = [];

        foreach ($nodes as $node) {
            $last = $out === [] ? null : $out[array_key_last($out)];

            if ($last !== null
                && ($last['type'] ?? null) === 'text' && ($node['type'] ?? null) === 'text'
                && self::sameMarks($last['marks'] ?? [], $node['marks'] ?? [])) {
                $out[array_key_last($out)]['text'] = ($last['text'] ?? '').($node['text'] ?? '');

                continue;
            }

            $out[] = $node;
        }

        return $out;
    }

    /** @param array<int, mixed> $a @param array<int, mixed> $b */
    private static function sameMarks(array $a, array $b): bool
    {
        // Key order is not meaning: jsonb hands keys back in its own order.
        $norm = fn (array $marks): array => array_map(function ($mark) {
            if (! is_array($mark)) {
                return $mark;
            }

            ksort($mark);

            if (isset($mark['attrs']) && is_array($mark['attrs'])) {
                ksort($mark['attrs']);
            }

            return $mark;
        }, $marks);

        return $norm($a) === $norm($b);
    }
}
