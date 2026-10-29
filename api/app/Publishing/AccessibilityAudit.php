<?php

namespace App\Publishing;

use App\Covers\CoverBackground;
use App\Covers\CoverContrast;
use App\Covers\CoverLayers;
use App\Models\Book;
use App\Models\ManuscriptSection;

/**
 * Accessibility checks over a manuscript.
 *
 * Every check is computed from the content on each request. None of it is
 * stored, because all of it can go stale the moment somebody edits a chapter
 * — and a passing tick next to a check that no longer holds is worse than no
 * check at all.
 *
 * These are the structural problems that make an EPUB unusable with a screen
 * reader, and the ones stores validate against. They are not the whole of
 * accessibility, and the screen says so rather than implying a clean result
 * means the book is accessible.
 */
class AccessibilityAudit
{
    /** @return array<string, mixed> */
    public function run(Book $book): array
    {
        $sections = $book->sections()
            ->where('type', '!=', 'part')
            ->orderBy('position')
            ->get();

        $images = [];
        $tables = [];
        $links = [];
        $headingProblems = [];

        foreach ($sections as $section) {
            $this->walk($section, $section->content ?? [], $images, $tables, $links);

            if ($problem = $this->headingOrder($section)) {
                $headingProblems[] = $problem;
            }
        }

        $missingAlt = array_values(array_filter($images, fn (array $i) => trim($i['alt']) === ''));
        $headerlessTables = array_values(array_filter($tables, fn (array $t) => ! $t['has_header']));
        $vagueLinks = array_values(array_filter($links, fn (array $l) => $l['vague']));

        $contrast = $this->coverContrast($book);

        return [
            'checks' => [
                [
                    'key' => 'heading_order',
                    'label' => 'Heading levels run in order',
                    'passed' => $headingProblems === [],
                    'detail' => $headingProblems === []
                        ? 'Every chapter steps down one level at a time.'
                        : $headingProblems[0],
                    'why' => 'Screen reader users navigate by heading. A skipped level reads as a missing section.',
                ],
                [
                    'key' => 'image_alt',
                    'label' => 'Images have alt text',
                    'passed' => $missingAlt === [],
                    'detail' => $images === []
                        ? 'No images in the manuscript.'
                        : count($missingAlt).' of '.count($images).' still need a description.',
                    'why' => 'Without it the image is announced only as "image", or skipped entirely.',
                ],
                [
                    'key' => 'language',
                    'label' => 'The language is set',
                    'passed' => (bool) $book->language,
                    'detail' => $book->language ?: 'Not set.',
                    'why' => 'It tells a screen reader which voice and pronunciation to use.',
                ],
                [
                    'key' => 'table_headers',
                    'label' => 'Tables have header rows',
                    'passed' => $headerlessTables === [],
                    'detail' => $tables === []
                        ? 'No tables.'
                        : count($headerlessTables).' of '.count($tables).' have no header row.',
                    'why' => 'Without headers a table is read as a run of unlabelled cells.',
                ],
                [
                    'key' => 'link_text',
                    'label' => 'Link text describes where it goes',
                    'passed' => $vagueLinks === [],
                    'detail' => $links === []
                        ? 'No links.'
                        : count($vagueLinks).' say "click here" or show a bare URL.',
                    'why' => 'Links are often read out of context, as a list on their own.',
                ],
                [
                    'key' => 'cover_contrast',
                    'label' => 'Cover title contrast',
                    'passed' => $contrast === null || $contrast['passed'],
                    'detail' => $contrast === null
                        ? 'Uploaded artwork — contrast cannot be measured automatically.'
                        : $contrast['detail'],
                    'why' => 'A title that disappears into its background is unreadable at thumbnail size.',
                ] + ($contrast['layers'] ?? null ? ['layers' => $contrast['layers']] : []),
            ],
            'images' => $images,
            'tables' => $headerlessTables,
            'links' => $vagueLinks,
        ];
    }

    /**
     * Collect images, tables and links from a section's document tree.
     *
     * @param  array<string, mixed>  $node
     * @param  array<int, mixed>  $images
     * @param  array<int, mixed>  $tables
     * @param  array<int, mixed>  $links
     */
    private function walk(
        ManuscriptSection $section,
        array $node,
        array &$images,
        array &$tables,
        array &$links,
    ): void {
        $type = $node['type'] ?? null;

        if ($type === 'image') {
            $images[] = [
                'section_id' => $section->id,
                'section_title' => $section->title,
                // The index within this section, which is how the alt-text
                // editor addresses it: nodes have no stable id of their own.
                'index' => count(array_filter(
                    $images,
                    fn (array $i) => $i['section_id'] === $section->id,
                )),
                'src' => $node['attrs']['src'] ?? '',
                'alt' => (string) ($node['attrs']['alt'] ?? ''),
            ];
        }

        if ($type === 'table') {
            $tables[] = [
                'section_id' => $section->id,
                'section_title' => $section->title,
                'has_header' => $this->hasHeaderCell($node),
            ];
        }

        foreach ($node['marks'] ?? [] as $mark) {
            if (($mark['type'] ?? '') === 'link') {
                $text = trim((string) ($node['text'] ?? ''));
                $href = (string) ($mark['attrs']['href'] ?? '');

                $links[] = [
                    'section_id' => $section->id,
                    'section_title' => $section->title,
                    'text' => $text,
                    'href' => $href,
                    // A bare URL, or one of the stock phrases that carry no
                    // meaning when read on their own.
                    'vague' => $text === $href
                        || preg_match('/^(click here|here|link|read more|more|this)$/i', $text) === 1,
                ];
            }
        }

        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                $this->walk($section, $child, $images, $tables, $links);
            }
        }
    }

    /** @param array<string, mixed> $node */
    private function hasHeaderCell(array $node): bool
    {
        if (($node['type'] ?? '') === 'tableHeader') {
            return true;
        }

        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child) && $this->hasHeaderCell($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first skipped heading level in a section, if any.
     *
     * Only descending jumps count. Going from an h3 back up to an h2 starts a
     * new sibling section and is correct; jumping h2 straight to h4 leaves a
     * level with nothing in it.
     */
    private function headingOrder(ManuscriptSection $section): ?string
    {
        $previous = 0;

        foreach ($section->content['content'] ?? [] as $node) {
            if (! is_array($node) || ($node['type'] ?? '') !== 'heading') {
                continue;
            }

            $level = (int) ($node['attrs']['level'] ?? 2);

            if ($previous > 0 && $level > $previous + 1) {
                return "“{$section->title}” jumps from heading {$previous} to heading {$level}.";
            }

            $previous = $level;
        }

        return null;
    }

    /**
     * Contrast between the cover's title and its background.
     *
     * Null where the cover is uploaded artwork: the title sits over a
     * photograph whose luminance varies across the area behind the text, and
     * a single number would be a guess presented as a measurement.
     */
    private function coverContrast(Book $book): ?array
    {
        $cover = $book->cover ?? [];

        if (! empty($cover['artwork'])) {
            return null;
        }

        $backgrounds = CoverBackground::all($book);

        // A preset palette with the layout's type: its ink against its deep
        // end, as always (the palettes are chosen to pass).
        if (CoverLayers::stored($book, 'front') === null && ! isset($backgrounds['front']) && ! isset($backgrounds['wrap'])) {
            $palettes = self::PALETTES;
            $palette = $palettes[$cover['palette'] ?? 0] ?? $palettes[0];
            $ratio = $this->contrast($palette['ink'], $palette['from']);

            return ['passed' => $ratio >= 4.5, 'detail' => number_format($ratio, 1).':1 (4.5:1 is the minimum)'];
        }

        // Text layers, or a background of the author's own: each layer
        // measured against what is under it (CoverContrast), the worst one
        // reported.
        $rows = CoverContrast::front($book);
        $worst = CoverContrast::worst($rows);

        if ($worst === null) {
            return ['passed' => true, 'detail' => 'No text on the front to measure.'];
        }

        $failing = count(array_filter($rows, fn ($r) => ! $r['ok']));
        $name = $worst['source'] === 'custom' ? 'a text layer' : 'the '.$worst['source'];

        return [
            'passed' => $failing === 0,
            'detail' => sprintf('Lowest %s:1 for %s (%s:1 needed for %s text)%s', number_format($worst['ratio'], 1), $name,
                rtrim(rtrim(number_format($worst['needed'], 1), '0'), '.'), $worst['large'] ? 'large' : 'small',
                $failing > 1 ? "; {$failing} layers below the minimum." : '.'),
            'layers' => $rows,
        ];
    }

    /** WCAG relative contrast between two hex colours (CoverContrast). */
    private function contrast(string $first, string $second): float
    {
        return CoverContrast::ratio($first, $second);
    }

    /**
     * The cover palettes, as DesignController defines them.
     *
     * Duplicated deliberately rather than imported: the audit must not depend
     * on a controller, and these are a fixed part of the product. A test
     * asserts the two lists stay identical.
     */
    public const PALETTES = [
        ['name' => 'Dusk', 'from' => '#1e3a5f', 'to' => '#4a6fa5', 'ink' => '#ffffff'],
        ['name' => 'Sand', 'from' => '#e8d5b7', 'to' => '#c9a227', 'ink' => '#2d2416'],
        ['name' => 'Forest', 'from' => '#1b4332', 'to' => '#52796f', 'ink' => '#ffffff'],
        // White ink on a deepened terracotta. The original pairing — dark
        // ink on #9c6644 — measured 3.41:1, below the 4.5:1 minimum, so a
        // palette the cover editor offered would have failed the contrast
        // check the accessibility screen then showed. This is 6.17:1.
        ['name' => 'Clay', 'from' => '#8a5436', 'to' => '#dda15e', 'ink' => '#ffffff'],
        ['name' => 'Slate', 'from' => '#2b2d42', 'to' => '#8d99ae', 'ink' => '#ffffff'],
        ['name' => 'Plum', 'from' => '#4a1942', 'to' => '#9d4edd', 'ink' => '#ffffff'],
    ];
}
