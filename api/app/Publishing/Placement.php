<?php

namespace App\Publishing;

use App\Models\Book;

/**
 * Where a picture or a table sits and how it is set (ADR-00XX — Image and
 * table placement).
 *
 * The editor stores the choices as attributes on the nodes it already has,
 * Tiptap's image and table (and the cells' own `align` and `colwidth`), so
 * every existing manuscript, Markdown round trip and suggestion is
 * untouched. A missing or null attribute is the old rendering: a picture
 * at its own size, capped at the measure and centred; a table in booktabs
 * rules at 0.8 em, kept whole when it is short.
 *
 * Picture: `size` (percentage of the text measure, 10–100; null is the
 * original size capped at the measure), `align` (left, center, right),
 * `wrap` (none, left, right: the picture on that side, text beside it),
 * `page` (full: on a page of its own, scaled to the text block; bleed:
 * print only, needs an interior with bleed), `captionPosition` (above,
 * below) and `keep` (the picture never parted from its caption).
 *
 * Table: `tableStyle` (booktabs, grid, shaded, zebra, plain), `fontSize`
 * (small: the old 0.8 em; normal: the text size), `split` (auto, avoid,
 * allow), `repeatHeader` (false stops the header row repeating on a new
 * page) and `captionPosition`. Cells: `align` (left, center, right) and
 * `colwidth` (Tiptap's pixels, read as proportions of the measure).
 *
 * Every reader of these values goes through this class: an unknown value
 * is dropped (read as unset), a number is held to its bounds.
 */
class Placement
{
    public const ALIGNS = ['left', 'center', 'right'];

    public const WRAPS = ['none', 'left', 'right'];

    public const PAGES = ['full', 'bleed'];

    public const CAPTIONS = ['above', 'below'];

    /** A picture's width, percent of the measure. */
    public const SIZE = [10, 100];

    public const TABLE_STYLES = ['booktabs', 'grid', 'shaded', 'zebra', 'plain'];

    public const FONT_SIZES = ['small', 'normal'];

    public const SPLITS = ['auto', 'avoid', 'allow'];

    /** dompdf's default resolution: a picture without a width prints a pixel at 1/96 in. */
    public const SCREEN_DPI = 96;

    public const IMAGE_KEYS = ['size', 'align', 'wrap', 'page', 'captionPosition', 'keep'];

    public const TABLE_KEYS = ['tableStyle', 'fontSize', 'split', 'repeatHeader', 'captionPosition'];

    /**
     * A picture's placement, every key present, invalid values null.
     *
     * @param  array<string, mixed>  $attrs  the image node's attrs
     * @return array{size: ?int, align: ?string, wrap: ?string, page: ?string, captionPosition: ?string, keep: ?bool}
     */
    public static function image(array $attrs): array
    {
        return [
            'size' => self::size($attrs['size'] ?? null),
            'align' => self::oneOf($attrs['align'] ?? null, self::ALIGNS),
            'wrap' => self::oneOf($attrs['wrap'] ?? null, self::WRAPS),
            'page' => self::oneOf($attrs['page'] ?? null, self::PAGES),
            'captionPosition' => self::oneOf($attrs['captionPosition'] ?? null, self::CAPTIONS),
            'keep' => is_bool($attrs['keep'] ?? null) ? $attrs['keep'] : null,
        ];
    }

    /** Does the picture ask for anything but the old rendering? */
    public static function imagePlaced(array $placement): bool
    {
        return $placement['size'] !== null
            || ! in_array($placement['align'], [null, 'center'], true)
            || ! in_array($placement['wrap'], [null, 'none'], true)
            || $placement['page'] !== null
            || $placement['captionPosition'] === 'above'
            || $placement['keep'] === true;
    }

    /**
     * The width a picture is set at, percent of the measure, or null for
     * its own size. A picture text wraps beside needs a width: half the
     * measure when none was chosen. A page of its own is the full measure
     * (print narrows a tall one to fit the text block).
     */
    public static function width(array $placement): ?int
    {
        return match (true) {
            $placement['page'] !== null => 100,
            $placement['size'] !== null => $placement['size'],
            in_array($placement['wrap'], ['left', 'right'], true) => 50,
            default => null,
        };
    }

    /**
     * A table's settings, every key present, invalid values null.
     *
     * @return array{tableStyle: ?string, fontSize: ?string, split: ?string, repeatHeader: ?bool, captionPosition: ?string}
     */
    public static function table(array $attrs): array
    {
        return [
            'tableStyle' => self::oneOf($attrs['tableStyle'] ?? null, self::TABLE_STYLES),
            'fontSize' => self::oneOf($attrs['fontSize'] ?? null, self::FONT_SIZES),
            'split' => self::oneOf($attrs['split'] ?? null, self::SPLITS),
            'repeatHeader' => is_bool($attrs['repeatHeader'] ?? null) ? $attrs['repeatHeader'] : null,
            'captionPosition' => self::oneOf($attrs['captionPosition'] ?? null, self::CAPTIONS),
        ];
    }

    /** A cell's alignment, or null for the default (left). */
    public static function cellAlign(array $attrs): ?string
    {
        return self::oneOf($attrs['align'] ?? null, self::ALIGNS);
    }

    /**
     * Column widths as percentages of the table, from the first row's
     * Tiptap `colwidth` (pixels dragged in the editor); null unless every
     * column has one, since a partial set has nothing to be a share of.
     *
     * @param  array<int, mixed>  $rows
     * @return list<float>|null
     */
    public static function columnShares(array $rows): ?array
    {
        $first = null;

        foreach ($rows as $row) {
            if (is_array($row)) {
                $first = $row;
                break;
            }
        }

        $widths = [];

        foreach ($first['content'] ?? [] as $cell) {
            if (! is_array($cell)) {
                continue;
            }

            $span = max(1, (int) ($cell['attrs']['colspan'] ?? 1));
            $colwidth = $cell['attrs']['colwidth'] ?? null;

            for ($j = 0; $j < $span; $j++) {
                $w = is_array($colwidth) ? ($colwidth[$j] ?? null) : null;

                if (! is_numeric($w) || (float) $w <= 0) {
                    return null;
                }

                $widths[] = min(4000.0, (float) $w);
            }
        }

        $total = array_sum($widths);

        return $widths === [] ? null : array_map(fn (float $w) => round($w / $total * 100, 2), $widths);
    }

    /**
     * The document with every placement value checked: an unknown value is
     * dropped, a size held to 10–100, a colwidth that is not a list of
     * positive numbers removed. Nothing else is touched. Run on every save
     * (ManuscriptWriter), so what is stored is what renders.
     */
    public static function sanitize(?array $doc): ?array
    {
        if ($doc === null) {
            return null;
        }

        return self::clean($doc);
    }

    /** @param array<string, mixed> $node */
    private static function clean(array $node): array
    {
        $type = $node['type'] ?? null;
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : null;

        if ($attrs !== null) {
            // Only the keys the node carries: a picture saved before this
            // stays without them.
            $checked = match ($type) {
                'image' => self::image($attrs),
                'table' => self::table($attrs),
                'tableCell', 'tableHeader' => ['align' => self::cellAlign($attrs)],
                default => [],
            };

            foreach ($checked as $key => $value) {
                if (array_key_exists($key, $attrs)) {
                    $attrs[$key] = $value;
                }
            }

            if (($type === 'tableCell' || $type === 'tableHeader') && ($attrs['colwidth'] ?? null) !== null) {
                $widths = $attrs['colwidth'];
                $valid = is_array($widths) && $widths !== [] && array_is_list($widths)
                    && collect($widths)->every(fn ($w) => (is_int($w) || is_float($w)) && $w > 0);
                $attrs['colwidth'] = $valid ? array_map(fn ($w) => (int) min(4000, max(1, round($w))), $widths) : null;
            }

            $node['attrs'] = $attrs;
        }

        if (is_array($node['content'] ?? null)) {
            $node['content'] = array_map(fn ($child) => is_array($child) ? self::clean($child) : $child, $node['content']);
        }

        return $node;
    }

    /**
     * The text block, inches: [width, height], at the book's set margins
     * (before any widening for the page count, which only narrows it a
     * little on a long book).
     *
     * @return array{0: float, 1: float}
     */
    public static function textBlock(array $layout): array
    {
        [$width, $height] = PageSetup::trimInches((string) ($layout['trim'] ?? '6 x 9 in'));
        $m = PageSetup::margins($layout);

        return [round(max(1.0, $width - $m['inside'] - $m['outside']), 4), round(max(1.0, $height - $m['top'] - $m['bottom']), 4)];
    }

    /**
     * How wide a picture prints, inches, for its pixel size and placement
     * in a text block [width, height]. The original size is dompdf's: a
     * pixel at 1/96 in, capped at the measure.
     *
     * @param  array{0: float, 1: float}  $block
     */
    public static function printedWidth(array $placement, int $pixelsWide, int $pixelsHigh, array $block): float
    {
        [$measure, $depth] = $block;

        if ($placement['page'] !== null) {
            // Scaled to the text block whole, whichever way it is longer.
            return min($measure, $depth * $pixelsWide / max(1, $pixelsHigh));
        }

        $width = self::width($placement);

        return $width !== null ? $measure * $width / 100 : min($measure, $pixelsWide / self::SCREEN_DPI);
    }

    /** The resolution a picture prints at, dots per inch, rounded down. */
    public static function dpi(array $placement, int $pixelsWide, int $pixelsHigh, array $block): int
    {
        $inches = self::printedWidth($placement, $pixelsWide, $pixelsHigh, $block);

        return $inches > 0 ? (int) floor($pixelsWide / $inches) : 0;
    }

    /**
     * Why a full-bleed picture is not printed full bleed. Interiors are set
     * without bleed (ADR-0041): the trim is the page, so there is nothing
     * past it to fill, and a print service would trim into the picture or
     * refuse the file. The picture is set on a page of its own inside the
     * margins instead, and the print checks say so.
     */
    public const BLEED_REFUSAL = 'This book’s interior is set without bleed (the page is the trim), so a full-bleed picture prints as a full page inside the margins.';

    /**
     * Does any of these documents use a placement? The stylesheets add
     * their placement rules only then, so a book that uses none keeps the
     * stylesheet it had, byte for byte.
     *
     * @param  iterable<?array>  $documents
     */
    public static function usedIn(iterable $documents): bool
    {
        foreach ($documents as $document) {
            if (is_array($document) && self::uses($document)) {
                return true;
            }
        }

        return false;
    }

    /** Does any of the book's sections use a placement? */
    public static function bookUses(Book $book): bool
    {
        return self::usedIn($book->sections()
            ->where(fn ($q) => $q->whereRaw('content::text like ?', ['%"type": "image"%'])->orWhereRaw('content::text like ?', ['%"type": "table"%']))
            ->pluck('content'));
    }

    /** @param array<string, mixed> $node */
    private static function uses(array $node): bool
    {
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];

        $used = match ($node['type'] ?? null) {
            'image' => self::imagePlaced(self::image($attrs)),
            'table' => array_filter(self::table($attrs), fn ($v) => $v !== null) !== []
                || self::columnShares($node['content'] ?? []) !== null,
            'tableCell', 'tableHeader' => self::cellAlign($attrs) !== null,
            default => false,
        };

        if ($used) {
            return true;
        }

        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child) && self::uses($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The print rules (PrintBook::css) for placed pictures and styled
     * tables. Floats are dompdf's; a header row repeats on each page
     * (thead as a table-header-group) unless the table turns it off.
     */
    public static function printCss(): string
    {
        return <<<'CSS'
        .text div.placed { margin: 1.1em auto; text-indent: 0; }
        .text div.placed img { display: block; width: 100%; max-width: 100%; margin: 0; }
        .text div.placed-original img { width: auto; margin: 0 auto; }
        .text div.placed-left { margin-left: 0; margin-right: auto; }
        .text div.placed-right { margin-left: auto; margin-right: 0; }
        .text div.placed-left.placed-original img { margin-left: 0; }
        .text div.placed-right.placed-original img { margin-right: 0; }
        .text div.placed-wrap-left { float: left; margin: .25em 1.1em .5em 0; }
        .text div.placed-wrap-right { float: right; margin: .25em 0 .5em 1.1em; }
        .text div.placed-page { page-break-before: always; page-break-after: always; margin: 0 auto; }
        .text div.placed-keep { page-break-inside: avoid; }
        .text div.placed p.caption { margin: .45em 0 0; text-align: left; }
        .text div.placed p.caption-above { margin: 0 0 .45em; }
        .text p.caption-below { margin: .35em 0 1.1em; }
        .text h1, .text h2, .text h3, .text h4, .text table, .text div.placed-page { clear: both; }
        .text table.tbl-normal { font-size: 1em; }
        .text table.tbl-grid th, .text table.tbl-grid td { border: .5pt solid #1a1a1a; padding: .3em .4em .25em; }
        .text table.tbl-grid tbody tr:last-child td { border-bottom: .5pt solid #1a1a1a; }
        .text table.tbl-shaded th { background-color: #e2e2e2; padding-left: .4em; }
        .text table.tbl-shaded td { padding-left: .4em; }
        .text table.tbl-zebra tr.zebra td { background-color: #eeeeee; }
        .text table.tbl-zebra td { padding-left: .4em; border-bottom: 0; }
        .text table.tbl-zebra th { padding-left: .4em; }
        .text table.tbl-plain th, .text table.tbl-plain td, .text table.tbl-plain tbody tr:last-child td { border: 0; }
        .text table.tbl-norepeat thead { display: table-row-group; }
        .text th.ar, .text td.ar, .text th.ar p, .text td.ar p { text-align: right; }
        .text th.ac, .text td.ac, .text th.ac p, .text td.ac p { text-align: center; }

        CSS;
    }

    /**
     * The reader rules (EPUB and HTML). A wrapped picture floats on a
     * screen wide enough for text beside it and stands alone under 30 em,
     * where a column beside it would hold a word a line.
     */
    public static function readerCss(): string
    {
        return "div.placed { margin: 1em auto; text-indent: 0; }\n"
            ."div.placed img { display: block; width: 100%; height: auto; margin: 0; }\n"
            ."div.placed-original img { width: auto; max-width: 100%; margin: 0 auto; }\n"
            ."div.placed-left { margin-left: 0; margin-right: auto; }\n"
            ."div.placed-right { margin-left: auto; margin-right: 0; }\n"
            ."div.placed-wrap-left { float: left; margin: .25em 1em .5em 0; }\n"
            ."div.placed-wrap-right { float: right; margin: .25em 0 .5em 1em; }\n"
            ."div.placed-page { page-break-before: always; page-break-after: always; }\n"
            ."div.placed-keep { page-break-inside: avoid; }\n"
            ."div.placed p.caption { margin: .4em 0 0; }\n"
            ."div.placed p.caption-above { margin: 0 0 .4em; }\n"
            ."h1, h2, h3, h4, table, div.placed-page { clear: both; }\n"
            ."@media (max-width: 30em) { div.placed-wrap-left, div.placed-wrap-right { float: none; width: auto !important; margin: 1em 0; } }\n"
            ."table.keep { page-break-inside: avoid; }\n"
            ."table.tbl-widths { table-layout: fixed; }\n"
            ."table.tbl-normal { font-size: 1em; }\n"
            ."table.tbl-grid th, table.tbl-grid td { border: 1px solid #000; padding: .3em .4em; }\n"
            ."table.tbl-shaded th { background-color: #e2e2e2; padding-left: .4em; }\n"
            ."table.tbl-zebra tr.zebra td { background-color: #eee; }\n"
            ."table.tbl-zebra td, table.tbl-zebra th { padding-left: .4em; }\n"
            ."table.tbl-plain th, table.tbl-plain td { border: 0; }\n"
            ."th.ar, td.ar { text-align: right; }\n"
            ."th.ac, td.ac { text-align: center; }\n";
    }

    private static function size(mixed $value): ?int
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            return null;
        }

        $n = (float) $value;

        return is_finite($n) ? (int) max(self::SIZE[0], min(self::SIZE[1], round($n))) : null;
    }

    private static function oneOf(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }
}
