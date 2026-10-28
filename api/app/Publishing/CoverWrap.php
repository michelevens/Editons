<?php

namespace App\Publishing;

use App\Covers\CoverFonts;
use App\Covers\CoverImages;
use App\Covers\CoverLayers;
use App\Covers\CoverText;
use App\Models\Book;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The printed cover: back, spine and front on one sheet, with bleed.
 *
 * A print service binds the interior inside a cover supplied separately,
 * sized to the book: two trims wide plus the spine, which depends on the
 * page count and the paper, plus bleed all round. This builds that sheet
 * for one service from its published figures (PrintSpecs, config/
 * publishing.php) and the book's own material:
 *
 *  - Front: the cover the stores get (CoverRenderer), scaled whole to the
 *    front panel, never distorted; the bands it leaves are painted in the
 *    colour of the image's own edge there, as the reading PDF does.
 *  - Spine: the colour of the front's spine-side edge. Text (title, series,
 *    author, publisher) only at the service's minimum page count, set
 *    clear of its spine margins, reading top to bottom unless the author
 *    chose bottom to top.
 *  - Back: an uploaded back cover when the book has one. Otherwise the
 *    book's own back-cover copy (its description), its publisher or
 *    imprint, and a blank white box where the service places the barcode.
 *    Nothing is invented: a book with no author name prints none, anywhere.
 *
 * The back and the spine can instead carry layers (CoverLayers, the same
 * system as the front's): "Customise back" and "Customise spine" convert
 * what is generated here into layers once, and from then on nothing is
 * fitted or moved for the author. Layers are laid out on a 300 dpi model of
 * the panel and drawn at whatever resolution is asked, so the screen's
 * preview and the print file break every line alike. The barcode box is
 * drawn last, over everything, so nothing can print on it.
 *
 * Drawn at 300 dpi and wrapped in a one-page PDF whose MediaBox is the
 * full sheet and whose TrimBox is the sheet less the bleed.
 */
class CoverWrap
{
    public const DPI = 300;

    /**
     * Bumped when the drawing changes, so every wrap made before is stale
     * (Freshness) and is made again. 2: back and spine layers, image
     * layers, spine direction, artwork framing.
     */
    public const VERSION = 2;

    /** The resolution back and spine layers are laid out at. */
    public const MODEL_DPI = 300;

    /**
     * How far inside the trim a layer should stay, in inches, on the back
     * and along the spine: the cutter's tolerance. Cover Studio shows it
     * and warns past it; the generated back keeps a wider 0.5 in (SAFE).
     */
    public const EDGE = 0.125;

    /** Inches kept clear inside the trim and the spine folds on the back. */
    private const SAFE = 0.5;

    /** The smallest type a spine is set in, in points. Below it, no text. */
    private const SPINE_MIN_PT = 5.0;

    public function __construct(private readonly CoverRenderer $covers) {}

    /**
     * The choices a wrap is made from, checked and completed.
     *
     * @param  array{service?: string, paper?: string, spine?: float|string|null}  $input
     * @return array{service: string, paper: string, ink: string, spine: ?float}
     */
    public function options(Book $book, array $input): array
    {
        $service = (string) ($input['service'] ?? 'kdp');

        if (! in_array($service, PrintSpecs::services(), true)) {
            throw new RuntimeException('Choose a print service for the cover.');
        }

        $print = $book->printSettings();
        $ink = $print['ink'];
        $paper = $ink === 'bw' ? (string) ($input['paper'] ?? $print['paper']) : 'white';

        if (! in_array($paper, Book::PRINT_OPTIONS['paper'], true)) {
            throw new RuntimeException('Choose white or cream paper.');
        }

        $spec = PrintSpecs::spec($service);

        if (isset($spec['spine_papers']) && ! in_array($paper, $spec['spine_papers'], true)) {
            throw new RuntimeException(PrintSpecs::label($service).' publishes its spine formula for white paper only.');
        }

        $spine = isset($input['spine']) && $input['spine'] !== '' ? (float) $input['spine'] : null;

        if ($spine !== null && ($spine <= 0 || $spine > 4)) {
            throw new RuntimeException('Enter the spine width in inches, as the service gives it.');
        }

        if ($spine === null && PrintSpecs::spineWidth($service, 100, $paper, $ink) === null) {
            throw new RuntimeException(PrintSpecs::label($service).' publishes spine widths only through its cover template generator. Enter the spine width it gives you for this book.');
        }

        $color = $print['spine_color'] ?? null;

        return ['service' => $service, 'paper' => $paper, 'ink' => $ink, 'spine' => $spine]
            + ($color ? ['spine_color' => strtolower($color)] : []);
    }

    /**
     * Where everything goes, in inches from the top left of the sheet.
     *
     * @param  array{service: string, paper: string, ink: string, spine: ?float}  $options
     * @return array<string, mixed>
     */
    public function geometry(Book $book, array $options, int $pages): array
    {
        $service = $options['service'];
        $spec = PrintSpecs::spec($service);
        [$tw, $th] = PrintBook::trimInches($book->layout()['trim']);
        $bleed = (float) $spec['bleed'];
        $spine = PrintSpecs::spineWidth($service, $pages, $options['paper'], $options['ink'], $options['spine'])
            ?? throw new RuntimeException('The spine width is not known for this service.');

        $width = 2 * $tw + $spine + 2 * $bleed;
        $height = $th + 2 * $bleed;
        $barcode = null;

        if ($box = $spec['barcode'] ?? null) {
            // Lower right of the back cover: inset from the spine fold and
            // from the bottom trim.
            $barcode = [
                'x' => $bleed + $tw - $box['inset'] - $box['width'],
                'y' => $height - $bleed - $box['inset'] - $box['height'],
                'width' => $box['width'],
                'height' => $box['height'],
            ];
        }

        return [
            'service' => $service,
            'label' => $spec['label'],
            'paper' => $options['paper'],
            'ink' => $options['ink'],
            'pages' => PrintSpecs::evenPages($pages),
            'trim' => [$tw, $th],
            'bleed' => $bleed,
            'spine' => $spine,
            'spine_given' => $options['spine'] !== null,
            'width' => round($width, 4),
            'height' => round($height, 4),
            'back' => ['x' => $bleed, 'width' => $tw],
            'spine_x' => $bleed + $tw,
            'front' => ['x' => $bleed + $tw + $spine, 'width' => $tw],
            'spine_text' => PrintSpecs::spineText($service, $pages),
            'spine_text_min_pages' => $spec['spine_text_min_pages'],
            'spine_margin' => PrintSpecs::spineMargin($service, $spine),
            'barcode' => $barcode,
        ];
    }

    /** The finished cover as a print-ready PDF. */
    public function pdf(Book $book, array $geometry): string
    {
        return $this->wrapPdf($this->image($book, $geometry, self::DPI), $geometry, $book->title);
    }

    /**
     * A small JPEG of the same drawing, for the screen. $bare leaves out
     * the back's and the spine's type and layers and the barcode box: the
     * ground Cover Studio's back and spine editors draw their layers over.
     */
    public function preview(Book $book, array $geometry, int $dpi = 48, bool $bare = false): string
    {
        return $this->image($book, $geometry, $dpi, 80, $bare);
    }

    // --- Layers -------------------------------------------------------------

    /**
     * Which way the spine reads.
     *
     * `chosen` is the author's choice, stored as cover.spine_direction;
     * `suggested` the convention of the book's language: bottom to top for
     * French (and Haitian Creole, which follows French publishing), top to
     * bottom otherwise, as the US, UK and ISO 6357 have it. A spine nobody
     * has chosen for keeps reading top to bottom, as every wrap did before,
     * so a book that does not touch its spine prints exactly as it did;
     * "Customise spine" starts from the suggestion and records it.
     *
     * @return array{direction: string, chosen: ?string, suggested: string}
     */
    public function direction(Book $book): array
    {
        $stored = ($book->cover ?? [])['spine_direction'] ?? null;
        $chosen = in_array($stored, CoverLayers::DIRECTIONS, true) ? $stored : null;

        return ['direction' => $chosen ?? 'ttb', 'chosen' => $chosen, 'suggested' => self::languageDirection($book->language)];
    }

    public static function languageDirection(?string $language): string
    {
        return in_array(Exporter::languageCode($language), ['fr', 'ht'], true) ? 'btt' : 'ttb';
    }

    /**
     * The models back and spine layers are laid out on, in pixels at
     * MODEL_DPI: the back panel from the sheet's left edge (its bleed
     * included) to the spine fold; the spine as its reading frame, as long
     * as the sheet is tall and as deep as the spine is thick.
     *
     * @return array{back: array{width: float, height: float}, spine: array{width: float, height: float, spine: bool}}
     */
    public function surfaces(array $g): array
    {
        $px = fn (float $inches) => $inches * self::MODEL_DPI;

        return [
            'back' => ['width' => $px($g['trim'][0] + $g['bleed']), 'height' => $px($g['height'])],
            'spine' => ['width' => $px($g['height']), 'height' => $px($g['spine']), 'spine' => true],
        ];
    }

    /** Artwork framing for an uploaded back: null keeps it whole. @return array{x: float, y: float, zoom: float}|null */
    public static function backFraming(mixed $framing): ?array
    {
        if (! is_array($framing) || ($framing['fit'] ?? null) !== 'fill') {
            return null;
        }

        return [
            'x' => max(0.0, min(1.0, (float) ($framing['x'] ?? 0.5))),
            'y' => max(0.0, min(1.0, (float) ($framing['y'] ?? 0.5))),
            'zoom' => max(1.0, min(4.0, (float) ($framing['zoom'] ?? 1))),
        ];
    }

    /**
     * Everything the wrap is drawn from beyond its options and page count,
     * hashed: a wrap made from another design is stale (Freshness). The
     * front's own hash, the back's and spine's layers and the book text
     * they show, the spine's direction, the uploaded back and its framing,
     * the pictures, and VERSION.
     */
    public function fingerprint(Book $book): string
    {
        $cover = $book->cover ?? [];

        return substr(md5(json_encode([
            self::VERSION,
            $this->covers->version($book),
            CoverLayers::stored($book, 'back'),
            CoverLayers::stored($book, 'spine'),
            $cover['spine_direction'] ?? null,
            $cover['back_artwork'] ?? null,
            self::backFraming($cover['back_framing'] ?? null),
            CoverImages::all($book),
            CoverLayers::wrapTexts($book),
            $this->covers->settings($book)['font'],
        ])), 0, 16);
    }

    /**
     * The pictures a panel's layers use, by id.
     *
     * @return array<string, array<string, mixed>>
     */
    private function images(Book $book): array
    {
        $images = [];

        foreach (CoverImages::all($book) as $image) {
            $images[$image['id']] = $image;
        }

        return $images;
    }

    /**
     * The back as generated, converted once into layers: the title, the
     * back-cover copy at the size it fits today, the publisher at the foot,
     * in the same faces, sizes, places and ink. From then on nothing is
     * fitted: the copy wraps inside its box, and the box stays put.
     *
     * @return array{version: int, items: array<int, array<string, mixed>>}
     */
    public function backLayersFromGenerated(Book $book, array $g): array
    {
        $family = $this->covers->settings($book)['font'] === 'sans' ? 'source-sans' : 'eb-garamond';
        $ink = self::hex($this->inkOn($this->groundFor($book)));
        $area = $this->copyArea($g);
        $panelW = $g['trim'][0] + $g['bleed'];
        $panelH = $g['height'];
        $texts = CoverLayers::wrapTexts($book);

        // A block's box from its first baseline (inches) and size (points).
        $layer = function (string $source, string $weight, float $pt, float $baseline, float $leading, float $x, float $width) use ($family, $ink, $panelH, $panelW) {
            $face = CoverFonts::metrics(CoverFonts::faceKey($family, $weight, false));

            return CoverLayers::make($source, [
                'id' => $source,
                'x' => $x / $panelW, 'y' => max(0, ($baseline - $face['asc'] / $face['upm'] * $pt / 72) / $panelH),
                'width' => $width / $panelW,
                'align' => 'left',
                'font' => $family, 'weight' => $weight,
                'size' => $pt / 72 / $panelH,
                'color' => $ink, 'leading' => $leading,
            ]);
        };

        $title = $this->titleBlock($book, $g, 72);
        $items = [$layer('title', 'semibold', $title['size'], $area['y'] + $title['line'] / 72, 1.2, $area['x'], $area['width'])];
        $y = $area['y'] + ($title['line'] * count($title['lines']) + $title['line'] * 0.6) / 72;
        $copy = $this->backCopy($book);
        $size = $copy === '' ? 12.0 : $this->fitCopy($copy, $this->face($book, 'regular'), $area['width'], $area['height'] - ($y - $area['y']), 72)[0];
        $items[] = $layer('description', 'regular', $size, $y + $size * 1.4 / 72, 1.4, $area['x'], $area['width']);

        $room = $g['barcode'] ? ($g['barcode']['x'] - $area['x'] - 0.25) : $area['width'];
        $items[] = $layer('publisher', 'regular', 10.0, $g['height'] - $g['bleed'] - self::SAFE, 1.2, $area['x'], max(0.5, $room));

        return CoverLayers::validate(['version' => CoverLayers::VERSION, 'items' => $items], 'back');
    }

    /**
     * The spine as generated, converted once into layers in its reading
     * frame: the title from the start of the spine, then the series,
     * author and publisher ending at its foot, at today's size. A part
     * that would not fit today starts hidden.
     *
     * @return array{version: int, items: array<int, array<string, mixed>>}
     */
    public function spineLayersFromGenerated(Book $book, array $g): array
    {
        $family = $this->covers->settings($book)['font'] === 'sans' ? 'source-sans' : 'eb-garamond';
        $ink = self::hex($this->inkOn($this->groundFor($book)));
        $length = $g['height'];
        $lines = $this->spineLines($book, $g);
        $size = $lines[2] ?? 8.0;
        $texts = CoverLayers::wrapTexts($book);
        $series = trim((string) $book->series_name);
        $same = fn (string $a, string $b) => $a !== '' && $b !== ''
            && mb_strtolower(Str::ascii($a)) === mb_strtolower(Str::ascii($b));

        // A part's width at a size, in inches, as CoverText measures it.
        $width = function (string $source, string $weight, float $pt) use ($family, $texts, $length) {
            $probe = CoverLayers::make($source, ['x' => 0, 'width' => 1, 'font' => $family, 'weight' => $weight, 'size' => $pt / 72 / $length]);
            $set = CoverText::layout($probe, CoverLayers::text($probe, $texts), $length * 100, $length * 100);

            return ($set['lines'][0]['width'] ?? 0) / 100 + 0.02;
        };

        $make = fn (string $source, string $weight, float $pt, float $x, float $w, bool $visible) => CoverLayers::make($source, [
            'id' => $source, 'x' => max(0, $x) / $length, 'y' => 0, 'width' => max(0.02 * $length, min($w, $length - max(0, $x))) / $length,
            'align' => 'left', 'font' => $family, 'weight' => $weight, 'size' => $pt / 72 / $length,
            'color' => $ink, 'visible' => $visible, 'leading' => 1.2,
        ]);

        $start = $g['bleed'] + self::SAFE;
        $titleWidth = min($width('title', 'semibold', $size), $length - 2 * $start);
        $items = [$make('title', 'semibold', $size, $start, $titleWidth, true)];

        // The rest ends at the foot, the publisher last; a series named for
        // its publisher is said once, as the generated spine says it.
        $rest = array_filter([
            'series' => $series !== '',
            'author' => $texts['author'] !== '',
            'publisher' => $texts['publisher'] !== '' && ! $same($series, $texts['publisher']),
        ]);
        $shown = $lines !== null && $lines[1] !== '';
        $end = $length - $start;

        foreach (array_reverse(array_keys($rest)) as $source) {
            $w = $width($source, 'regular', $size * 0.8);
            $end -= $w;
            $items[] = $make($source, 'regular', $size * 0.8, $end, $w, $shown && $end > $start + $titleWidth + 0.25);
            $end -= 0.3;
        }

        return CoverLayers::validate(['version' => CoverLayers::VERSION, 'items' => $items], 'spine');
    }

    /**
     * What Cover Studio should tell the author about this wrap's layers:
     * pictures that print below 300 dpi, layers over the barcode box or
     * past the trim's safe edge, spine type too deep for the spine, and
     * why the spine carries no type when the book is too thin.
     *
     * @return list<array{panel: string, layer: ?string, kind: string, message: string}>
     */
    public function warnings(Book $book, array $g): array
    {
        $warnings = [];
        $surfaces = $this->surfaces($g);
        $texts = CoverLayers::wrapTexts($book);
        $images = $this->images($book);
        $in = fn (float $px) => $px / self::MODEL_DPI;
        $name = fn (array $layer) => $layer['source'] === 'custom' ? 'A text layer' : ($layer['source'] === 'image' ? 'A picture' : ucfirst($layer['source']));

        $dpi = function (string $panel, array $layer, float $inches) use (&$warnings, $images) {
            $image = $images[$layer['image']] ?? null;

            if ($image && $layer['visible'] && ($value = CoverImages::dpi($layer, $image, $inches)) < CoverImages::PRINT_DPI) {
                $warnings[] = ['panel' => $panel, 'layer' => $layer['id'], 'kind' => 'dpi',
                    'message' => sprintf('“%s” prints at %d dpi at this size; print wants 300. Use a larger picture or make it smaller.', $image['name'], (int) floor($value))];
            }
        };

        // The front: its pictures, at the size the front panel prints.
        if ($front = CoverLayers::stored($book, 'front')) {
            $coverWidth = CoverRenderer::WIDTH * min(($g['front']['width'] + $g['bleed']) / CoverRenderer::WIDTH, $g['height'] / CoverRenderer::HEIGHT);

            foreach ($front['items'] as $layer) {
                if ($layer['source'] === 'image') {
                    $dpi('front', $layer, $layer['width'] * $coverWidth);
                }
            }
        }

        if ($back = CoverLayers::stored($book, 'back')) {
            $safe = ['x' => $g['bleed'] + self::EDGE, 'y' => $g['bleed'] + self::EDGE,
                'right' => $g['bleed'] + $g['trim'][0] - self::EDGE, 'bottom' => $g['height'] - $g['bleed'] - self::EDGE];

            foreach ($back['items'] as $layer) {
                if (! $layer['visible']) {
                    continue;
                }

                $box = CoverRenderer::place($layer, $surfaces['back'], $texts)['box'];
                [$x, $y, $w, $h] = [$in($box['x']), $in($box['y']), $in($box['width']), $in($box['height'])];

                if ($layer['source'] === 'image') {
                    $dpi('back', $layer, $w);
                }

                if ($h <= 0) {
                    continue;
                }

                if (($bar = $g['barcode']) && ! $this->backArtwork($book)
                    && $x < $bar['x'] + $bar['width'] && $x + $w > $bar['x'] && $y < $bar['y'] + $bar['height'] && $y + $h > $bar['y']) {
                    $warnings[] = ['panel' => 'back', 'layer' => $layer['id'], 'kind' => 'barcode',
                        'message' => $name($layer).' overlaps the barcode box, which prints white over it. Move it clear.'];
                }

                if ($x < $safe['x'] - 1e-6 || $y < $safe['y'] - 1e-6 || $x + $w > $safe['right'] + 1e-6 || $y + $h > $safe['bottom'] + 1e-6) {
                    $warnings[] = ['panel' => 'back', 'layer' => $layer['id'], 'kind' => 'safe',
                        'message' => $name($layer).' runs past the safe edge, '.self::EDGE.' in inside the trim, and may be cut.'];
                }
            }
        }

        if ($spine = CoverLayers::stored($book, 'spine')) {
            $hasText = false;
            $margin = $g['spine_margin'];

            foreach ($spine['items'] as $layer) {
                if (! $layer['visible']) {
                    continue;
                }

                $hasText = $hasText || $layer['source'] !== 'image';
                $box = CoverRenderer::place($layer, $surfaces['spine'], $texts)['box'];
                [$u, $v, $w, $h] = [$in($box['x']), $in($box['y']), $in($box['width']), $in($box['height'])];

                if ($layer['source'] === 'image') {
                    $dpi('spine', $layer, $w);

                    continue;
                }

                if (! $g['spine_text'] || $h <= 0) {
                    continue;
                }

                if ($v < $margin - 1e-6 || $v + $h > $g['spine'] - $margin + 1e-6) {
                    $warnings[] = ['panel' => 'spine', 'layer' => $layer['id'], 'kind' => 'spine',
                        'message' => sprintf('%s is deeper than the spine allows: keep %s in clear of each fold (%s).', $name($layer), $margin, $g['label'])];
                }

                if ($u < $g['bleed'] + self::EDGE - 1e-6 || $u + $w > $g['height'] - $g['bleed'] - self::EDGE + 1e-6) {
                    $warnings[] = ['panel' => 'spine', 'layer' => $layer['id'], 'kind' => 'safe',
                        'message' => $name($layer).' runs past the ends of the spine and may be cut.'];
                }
            }

            if ($hasText && ! $g['spine_text']) {
                $warnings[] = ['panel' => 'spine', 'layer' => null, 'kind' => 'thin', 'message' => $this->spineTextReason($g)];
            }
        }

        return $warnings;
    }

    /** Why a spine carries no type, in the service's own terms. */
    public function spineTextReason(array $g): string
    {
        return sprintf('%s prints spine text only on books of %d pages or more; this one has %d. The spine shows its colour and pictures only.',
            $g['label'], $g['spine_text_min_pages'], $g['pages']);
    }

    /**
     * What Cover Studio's back, spine and full-wrap views draw with, in
     * MODEL_DPI pixels: the sheet and its panels, the trim, folds and
     * bleed, the safe edges, the barcode box, the spine's margins and
     * whether it may carry type, which way it reads, and the warnings.
     *
     * @return array<string, mixed>
     */
    public function editor(Book $book, array $g): array
    {
        $px = fn (float $inches) => round($inches * self::MODEL_DPI, 3);
        $surfaces = $this->surfaces($g);
        $bar = $g['barcode'];

        return [
            'dpi' => self::MODEL_DPI,
            'geometry' => $g,
            'sheet' => ['width' => $px($g['width']), 'height' => $px($g['height'])],
            'bleed' => $px($g['bleed']),
            'edge' => $px(self::EDGE),
            'back' => $surfaces['back'] + [
                // In the back's own pixels, which start at the sheet's edge.
                'trim' => ['x' => $px($g['bleed']), 'y' => $px($g['bleed']), 'width' => $px($g['trim'][0]), 'height' => $px($g['trim'][1])],
                'safe' => ['x' => $px($g['bleed'] + self::EDGE), 'y' => $px($g['bleed'] + self::EDGE),
                    'width' => $px($g['trim'][0] - 2 * self::EDGE), 'height' => $px($g['trim'][1] - 2 * self::EDGE)],
                'barcode' => $bar && ! $this->backArtwork($book) ? ['x' => $px($bar['x']), 'y' => $px($bar['y']), 'width' => $px($bar['width']), 'height' => $px($bar['height'])] : null,
            ],
            'spine' => $surfaces['spine'] + [
                'x' => $px($g['spine_x']),
                'margin' => $px($g['spine_margin']),
                // Along the spine, in its reading frame.
                'safe' => ['x' => $px($g['bleed'] + self::EDGE), 'y' => $px($g['spine_margin']),
                    'width' => $px($g['height'] - 2 * ($g['bleed'] + self::EDGE)), 'height' => max(0, $px($g['spine'] - 2 * $g['spine_margin']))],
                'text' => $g['spine_text'],
                'reason' => $g['spine_text'] ? null : $this->spineTextReason($g),
            ] + $this->direction($book),
            'front' => ['x' => $px($g['front']['x']), 'width' => $px($g['front']['width'] + $g['bleed'])],
            'layers' => [
                'back' => CoverLayers::stored($book, 'back'),
                'spine' => CoverLayers::stored($book, 'spine'),
            ],
            'texts' => CoverLayers::wrapTexts($book),
            // The ink the generated back and spine set their type in, for
            // a new layer: near-black on a light ground, white on a dark one.
            'ink' => self::hex($this->inkOn($this->groundFor($book))),
            'back_artwork' => $this->backArtwork($book) !== null,
            'back_artwork_image' => $this->backArtwork($book) ? route('design.cover.image', [
                'book' => $book->id, 'part' => 'back-artwork', 'v' => substr(md5((string) $this->backArtwork($book)), 0, 12),
            ]) : null,
            'back_framing' => self::backFraming(($book->cover ?? [])['back_framing'] ?? null),
            'warnings' => $this->warnings($book, $g),
            'fingerprint' => $this->fingerprint($book),
        ];
    }

    /**
     * What the back cover says when the book has no uploaded back: the
     * book's description, which is its back-cover copy (Anatomy, the Back
     * Cover Copy part). Empty when there is none; nothing stands in for it.
     */
    public function backCopy(Book $book): string
    {
        return trim((string) ($book->description ?: (($book->meta ?? [])['description'] ?? '')));
    }

    /** The uploaded back cover's stored path, when there is a usable one. */
    public function backArtwork(Book $book): ?string
    {
        $back = ($book->cover ?? [])['back_artwork'] ?? null;

        return is_string($back) && $back !== '' && Storage::disk('files')->exists($back) ? $back : null;
    }

    /**
     * Whether the back-cover copy fits the back at a readable size.
     *
     * @return array{fits: bool, size: float}
     */
    public function copyFits(Book $book, array $geometry): array
    {
        $copy = $this->backCopy($book);

        if ($copy === '' || $this->backArtwork($book)) {
            return ['fits' => true, 'size' => 0.0];
        }

        $area = $this->copyArea($geometry);
        [$size, , $fits] = $this->fitCopy($copy, $this->face($book, 'regular'), $area['width'], $area['height'] - $this->titleBlock($book, $geometry, 72)['height'] / 72, 72);

        return ['fits' => $fits, 'size' => $size];
    }

    // --- Drawing ----------------------------------------------------------

    /** The whole sheet as JPEG bytes at a resolution. */
    private function image(Book $book, array $g, int $dpi, int $quality = 92, bool $bare = false): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('Cover rendering needs the GD extension.');
        }

        $this->covers->headroom('768M');

        $px = fn (float $inches) => (int) round($inches * $dpi);
        $image = imagecreatetruecolor($px($g['width']), $px($g['height']));
        imagealphablending($image, true);

        // The front at the width its panel prints it, so a cover's text
        // layers are set sharp at print resolution rather than enlarged.
        $frontWidth = (int) round(min(($g['front']['width'] + $g['bleed']) * $dpi, $g['height'] * $dpi * CoverRenderer::WIDTH / CoverRenderer::HEIGHT));
        $front = @imagecreatefromstring($this->covers->front($book, $frontWidth));

        if (! $front) {
            throw new RuntimeException('The front cover could not be drawn.');
        }

        // The colour the spine and a designed back take: the author's choice,
        // or the front's own edge on the spine side, so the three panels read
        // as one cover. The front's bleed keeps its own edges either way.
        [$spineSide, $outerSide] = $this->edgeColours($front, true);
        $spineGround = self::rgb($book->printSettings()['spine_color'] ?? null) ?? $spineSide;
        $ground = $this->allocate($image, $spineGround);

        imagefilledrectangle($image, 0, 0, imagesx($image) - 1, imagesy($image) - 1, $ground);

        $this->drawFront($image, $front, $g, $dpi, $spineSide, $outerSide);
        $this->drawBack($book, $image, $g, $dpi, $spineGround, $bare);

        if ($bare) {
            // The ground alone, for the spine editor.
        } elseif ($spine = CoverLayers::stored($book, 'spine')) {
            $this->drawSpineLayers($book, $image, $g, $dpi, $spine['items']);
        } elseif ($g['spine_text']) {
            $this->drawSpine($book, $image, $g, $dpi, $spineGround);
        }

        imagedestroy($front);

        ob_start();
        imagejpeg($image, null, $quality);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /**
     * The front fitted whole into its panel with bleed, centred. Covers
     * are 5:8 and trims are not, so one pair of bands is painted in the
     * image's edge colours.
     */
    private function drawFront($image, $front, array $g, int $dpi, array $spineSide, array $outerSide): void
    {
        $x0 = ($g['front']['x']) * $dpi;
        $panelW = ($g['front']['width'] + $g['bleed']) * $dpi;
        $panelH = $g['height'] * $dpi;
        [$iw, $ih] = [imagesx($front), imagesy($front)];
        $scale = min($panelW / $iw, $panelH / $ih);
        [$w, $h] = [$iw * $scale, $ih * $scale];
        [$x, $y] = [$x0 + ($panelW - $w) / 2, ($panelH - $h) / 2];

        if ($x - $x0 > 0.5) {
            imagefilledrectangle($image, (int) floor($x0), 0, (int) ceil($x), (int) $panelH, $this->allocate($image, $spineSide));
            imagefilledrectangle($image, (int) floor($x + $w) - 1, 0, (int) ceil($x0 + $panelW), (int) $panelH, $this->allocate($image, $outerSide));
        } elseif ($y > 0.5) {
            [$top, $bottom] = $this->edgeColours($front, false);
            imagefilledrectangle($image, (int) floor($x0), 0, (int) ceil($x0 + $panelW), (int) ceil($y), $this->allocate($image, $top));
            imagefilledrectangle($image, (int) floor($x0), (int) floor($y + $h) - 1, (int) ceil($x0 + $panelW), (int) $panelH, $this->allocate($image, $bottom));
        }

        imagecopyresampled($image, $front, (int) round($x), (int) round($y), 0, 0, (int) round($w), (int) round($h), $iw, $ih);
    }

    private function drawBack(Book $book, $image, array $g, int $dpi, array $ground, bool $bare = false): void
    {
        $panelW = ($g['back']['width'] + $g['bleed']) * $dpi;
        $panelH = $g['height'] * $dpi;
        $layers = $bare ? null : CoverLayers::stored($book, 'back');
        $draw = fn () => $layers && $this->covers->drawItems(
            $image, $layers['items'], CoverLayers::wrapTexts($book), $this->images($book),
            $this->surfaces($g)['back'], $dpi / self::MODEL_DPI,
        );

        if ($path = $this->backArtwork($book)) {
            $art = @imagecreatefromstring((string) Storage::disk('files')->get($path));

            if ($art && ($framing = self::backFraming(($book->cover ?? [])['back_framing'] ?? null))) {
                // Panned and zoomed to fill the panel, as the front can be.
                $panel = imagecreatetruecolor((int) round($panelW), (int) round($panelH));
                $this->covers->cover($panel, $art, $framing);
                imagecopy($image, $panel, 0, 0, 0, 0, imagesx($panel), imagesy($panel));
                imagedestroy($panel);
                imagedestroy($art);
                $draw();

                return;
            }

            if ($art) {
                // Fitted whole, like the front: an uploaded back is the
                // author's design, and cropping it could cut its copy.
                [$iw, $ih] = [imagesx($art), imagesy($art)];
                $scale = min($panelW / $iw, $panelH / $ih);
                [$w, $h] = [$iw * $scale, $ih * $scale];
                [$sides, $ends] = [$this->edgeColours($art, true), $this->edgeColours($art, false)];
                $wide = ($panelW - $w) > ($panelH - $h);
                $fill = $wide ? $sides : $ends;
                imagefilledrectangle($image, 0, 0, (int) ($wide ? $panelW / 2 : $panelW), (int) ($wide ? $panelH : $panelH / 2), $this->allocate($image, $fill[0]));
                imagefilledrectangle($image, (int) ($wide ? $panelW / 2 : 0), (int) ($wide ? 0 : $panelH / 2), (int) $panelW, (int) $panelH, $this->allocate($image, $fill[1]));
                imagecopyresampled($image, $art, (int) round(($panelW - $w) / 2), (int) round(($panelH - $h) / 2), 0, 0, (int) round($w), (int) round($h), $iw, $ih);
                imagedestroy($art);
                // Layers, when the author added any, over the finished back.
                $draw();

                return;
            }
        }

        if ($bare) {
            return;
        }

        if ($layers) {
            $draw();
            $this->barcode($image, $g, $dpi);

            return;
        }

        $ink = $this->allocate($image, $this->inkOn($ground));
        $area = $this->copyArea($g);
        $left = (int) round($area['x'] * $dpi);
        $top = $area['y'] * $dpi;

        // The title, then the copy, set as large as the space allows.
        $title = $this->titleBlock($book, $g, $dpi);
        $y = $top;

        foreach ($title['lines'] as $line) {
            $y += $title['line'];
            $this->text($image, $line, $title['face'], $title['size'], $ink, $left, (int) round($y), $dpi);
        }

        $y += $title['line'] * 0.6;
        $copy = $this->backCopy($book);

        if ($copy !== '') {
            [$size, $lines] = $this->fitCopy($copy, $this->face($book, 'regular'), $area['width'], $area['height'] - ($y - $top) / $dpi, $dpi);
            $line = $size * 1.4 * $dpi / 72;

            foreach ($lines as $text) {
                $y += $line;

                if ($text !== '') {
                    $this->text($image, $text, $this->face($book, 'regular'), $size, $ink, $left, (int) round($y), $dpi);
                }
            }
        }

        // The publisher or imprint at the foot, opposite the barcode.
        $publication = $book->publication();
        $publisher = trim((string) ($publication['imprint'] ?: $publication['publisher']));

        if ($publisher !== '') {
            $size = 10.0;
            $baseline = ($g['height'] - $g['bleed'] - self::SAFE) * $dpi;
            $room = $g['barcode'] ? ($g['barcode']['x'] - $area['x'] - 0.25) : $area['width'];
            $this->text($image, $this->ellipsis($publisher, $this->face($book, 'regular'), $size, $room * 72), $this->face($book, 'regular'), $size, $ink, $left, (int) round($baseline), $dpi);
        }

        $this->barcode($image, $g, $dpi);
    }

    /** A blank white box where the service puts its barcode. */
    private function barcode($image, array $g, int $dpi): void
    {
        if ($box = $g['barcode']) {
            imagefilledrectangle(
                $image,
                (int) round($box['x'] * $dpi), (int) round($box['y'] * $dpi),
                (int) round(($box['x'] + $box['width']) * $dpi), (int) round(($box['y'] + $box['height']) * $dpi),
                imagecolorallocate($image, 255, 255, 255),
            );
        }
    }

    /**
     * Title, then series, author and publisher, rotated to read top to
     * bottom (or, when the author chose it, bottom to top), set as large
     * as the spine allows inside its margins.
     */
    private function drawSpine(Book $book, $image, array $g, int $dpi, array $ground): void
    {
        $lines = $this->spineLines($book, $g);

        if ($lines === null) {
            return;
        }

        [$title, $rest, $size] = $lines;
        $ink = $this->allocate($image, $this->inkOn($ground));
        $centre = ($g['spine_x'] + $g['spine'] / 2) * $dpi;
        $top = ($g['bleed'] + self::SAFE) * $dpi;
        $bottom = ($g['height'] - $g['bleed'] - self::SAFE) * $dpi;
        $up = $this->direction($book)['direction'] === 'btt';

        $place = function (string $text, string $face, float $pt, float $start) use ($image, $ink, $centre, $dpi, $up): void {
            [$ascent, $descent] = $this->extent($face, $pt);
            // Rotated clockwise, the tops of the letters face the front
            // cover; anticlockwise, the back. Centre the line's height on
            // the spine.
            $x = $up
                ? $centre + ($ascent - $descent) * $dpi / 72 / 2
                : $centre - ($ascent - $descent) * $dpi / 72 / 2;
            imagettftext($image, $this->gdSize($pt, $dpi), $up ? 90 : -90, (int) round($x), (int) round($start), $ink, $face, $text);
        };

        $bold = $this->face($book, 'bold');
        $regular = $this->face($book, 'regular');
        $place($title, $bold, $size, $up ? $bottom : $top);

        if ($rest !== '') {
            $length = $this->measure($rest, $regular, $size * 0.8) * $dpi / 72;
            $place($rest, $regular, $size * 0.8, $up ? $top + $length : $bottom - $length);
        }
    }

    /**
     * Spine layers: the spine cut out of the sheet, turned into its
     * reading frame (a quarter turn, which moves pixels without
     * resampling them), the layers drawn on it exactly as on a front, and
     * turned back. Type is drawn only at the service's minimum page count;
     * pictures always. Nothing leaves the spine.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function drawSpineLayers(Book $book, $image, array $g, int $dpi, array $items): void
    {
        if (! $g['spine_text']) {
            $items = array_values(array_filter($items, fn ($layer) => $layer['source'] === 'image'));
        }

        if ($items === []) {
            return;
        }

        $x0 = (int) round($g['spine_x'] * $dpi);
        $width = max(1, (int) round(($g['spine_x'] + $g['spine']) * $dpi) - $x0);
        $height = imagesy($image);
        $up = $this->direction($book)['direction'] === 'btt';

        $strip = imagecreatetruecolor($width, $height);
        imagecopy($strip, $image, 0, 0, $x0, 0, $width, $height);
        // Anticlockwise for top to bottom: the frame's start is the top of
        // the sheet, and the tops of the letters face the front cover.
        $frame = imagerotate($strip, $up ? 270 : 90, 0);
        imagedestroy($strip);
        imagealphablending($frame, true);

        $this->covers->drawItems($frame, $items, CoverLayers::wrapTexts($book), $this->images($book), $this->surfaces($g)['spine'], $dpi / self::MODEL_DPI);

        $back = imagerotate($frame, $up ? 90 : 270, 0);
        imagedestroy($frame);
        imagecopy($image, $back, $x0, 0, 0, 0, $width, $height);
        imagedestroy($back);
    }

    /**
     * What the spine says and at what size, or null when it is too narrow
     * to carry legible type.
     *
     * @return array{0: string, 1: string, 2: float}|null
     */
    public function spineLines(Book $book, array $g): ?array
    {
        $height = $g['spine'] - 2 * $g['spine_margin'];
        // Ascent plus descent is about 1.25 em in these faces.
        $size = min(14.0, $height * 72 / 1.25);

        if ($size < self::SPINE_MIN_PT) {
            return null;
        }

        $settings = $this->covers->settings($book);
        $publication = $book->publication();
        $series = trim((string) $book->series_name);
        $publisher = trim((string) ($publication['imprint'] ?: $publication['publisher']));
        // A series named for its publisher ("Écrivain Prépa", published by
        // Écrivain Prépa) is said once: the spine printed it twice.
        $same = fn (string $a, string $b) => $a !== '' && $b !== ''
            && mb_strtolower(Str::ascii($a)) === mb_strtolower(Str::ascii($b));
        $rest = array_values(array_filter([
            $series !== '' ? $series.($book->series_number ? ' · '.$book->series_number : '') : '',
            $settings['author'],
            $same($series, $publisher) ? '' : $publisher,
        ], fn ($part) => trim((string) $part) !== ''));
        $title = $settings['title'] !== '' ? $settings['title'] : (string) $book->title;
        $room = ($g['trim'][1] - 2 * self::SAFE) * 72;
        $bold = $this->face($book, 'bold');
        $regular = $this->face($book, 'regular');
        $restText = implode('  ·  ', $rest);
        $length = fn (float $pt, string $other) => $this->measure($title, $bold, $pt)
            + ($other !== '' ? 0.5 * 72 + $this->measure($other, $regular, $pt * 0.8) : 0);

        while ($size > self::SPINE_MIN_PT && $length($size, $restText) > $room) {
            $size -= 0.5;
        }

        if ($length($size, $restText) > $room) {
            $restText = '';
        }

        return [$this->ellipsis($title, $bold, $size, $room), $restText, $size];
    }

    /** The spine and back's colour, as image() picks it, from the stored front. */
    private function groundFor(Book $book): array
    {
        if ($chosen = self::rgb($book->printSettings()['spine_color'] ?? null)) {
            return $chosen;
        }

        $front = @imagecreatefromstring((string) Storage::disk('files')->get($this->covers->ensure($book)));

        if (! $front) {
            return [255, 255, 255];
        }

        $edge = $this->edgeColours($front, true)[0];
        imagedestroy($front);

        return $edge;
    }

    private static function hex(array $rgb): string
    {
        return sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
    }

    // --- Text -------------------------------------------------------------

    /** @return array{x: float, y: float, width: float, height: float} inches */
    private function copyArea(array $g): array
    {
        $x = $g['back']['x'] + self::SAFE;
        $y = $g['bleed'] + self::SAFE + 0.25;
        $bottom = $g['barcode'] ? $g['barcode']['y'] - 0.3 : $g['height'] - $g['bleed'] - self::SAFE - 0.4;

        return ['x' => $x, 'y' => $y, 'width' => $g['back']['width'] - 2 * self::SAFE, 'height' => $bottom - $y];
    }

    /** @return array{lines: list<string>, face: string, size: float, line: float, height: float} */
    private function titleBlock(Book $book, array $g, int $dpi): array
    {
        $face = $this->face($book, 'bold');
        $size = 20.0;
        $width = ($g['back']['width'] - 2 * self::SAFE) * 72;
        $lines = $this->wrap((string) $book->title, $face, $size, $width);
        $line = $size * 1.2 * $dpi / 72;

        return ['lines' => $lines, 'face' => $face, 'size' => $size, 'line' => $line, 'height' => $line * (count($lines) + 0.6)];
    }

    /**
     * The copy wrapped at the largest size from 12 pt down to 8 pt that
     * fits; at 8 pt it is cut at the last whole line that fits.
     *
     * @return array{0: float, 1: list<string>, 2: bool}
     */
    private function fitCopy(string $copy, string $face, float $widthIn, float $heightIn, int $dpi): array
    {
        $paragraphs = preg_split('/\R\s*\R|\R/u', trim($copy));

        for ($size = 12.0; $size >= 8.0; $size -= 0.5) {
            $lines = [];

            foreach ($paragraphs as $i => $paragraph) {
                if ($i > 0) {
                    $lines[] = '';
                }

                array_push($lines, ...$this->wrap(trim($paragraph), $face, $size, $widthIn * 72));
            }

            $fit = (int) floor($heightIn * 72 / ($size * 1.4));

            if (count($lines) <= $fit) {
                return [$size, $lines, true];
            }
        }

        $kept = array_slice($lines, 0, max(0, $fit));

        if ($kept !== []) {
            $kept[count($kept) - 1] = rtrim($kept[count($kept) - 1], ' .,;:').'…';
        }

        return [8.0, $kept, false];
    }

    /** @return list<string> */
    private function wrap(string $text, string $face, float $size, float $widthPt): array
    {
        $lines = [];
        $line = '';

        foreach (preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $candidate = $line === '' ? $word : $line.' '.$word;

            if ($line !== '' && $this->measure($candidate, $face, $size) > $widthPt) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }

        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    private function ellipsis(string $text, string $face, float $size, float $widthPt): string
    {
        while (mb_strlen($text) > 2 && $this->measure($text, $face, $size) > $widthPt) {
            $text = rtrim(mb_substr($text, 0, mb_strlen($text) - 2)).'…';
        }

        return $text;
    }

    /** Width in points of a line set at $size points. */
    private function measure(string $text, string $face, float $size): float
    {
        // Measured large and scaled, so rounding to whole pixels does not
        // accumulate across a long line.
        $box = imagettfbbox($this->gdSize(100, 72), 0, $face, $text);

        return ($box[2] - $box[0]) * $size / 100;
    }

    /** @return array{0: float, 1: float} ascent and descent in points */
    private function extent(string $face, float $size): array
    {
        $box = imagettfbbox($this->gdSize(100, 72), 0, $face, 'Hg');

        return [-$box[7] * $size / 100, $box[1] * $size / 100];
    }

    private function text($image, string $text, string $face, float $size, int $color, int $x, int $y, int $dpi): void
    {
        imagettftext($image, $this->gdSize($size, $dpi), 0, $x, $y, $color, $face, $text);
    }

    /** GD sets type at 96 dpi; this is the size that comes out at $pt points on a $dpi sheet. */
    private function gdSize(float $pt, int $dpi): float
    {
        return $pt * $dpi / 96;
    }

    private function face(Book $book, string $style): string
    {
        $sans = $this->covers->settings($book)['font'] === 'sans';

        return resource_path('fonts/'.match ([$sans, $style]) {
            [true, 'bold'] => 'SourceSans3-Semibold',
            [true, 'regular'] => 'SourceSans3-Regular',
            [false, 'bold'] => 'EBGaramond-SemiBold',
            default => 'EBGaramond-Regular',
        }.'.ttf');
    }

    // --- Colour -----------------------------------------------------------

    /**
     * The commonest colour along two opposite edges, as 0-255 RGB: left
     * and right when $sides, else top and bottom. The commonest, not the
     * average, as in PrintBook: an edge crossing a ground, an illustration
     * and a band averages to a colour on none of them.
     *
     * @return array{0: array{int, int, int}, 1: array{int, int, int}}
     */
    private function edgeColours($image, bool $sides): array
    {
        [$width, $height] = [imagesx($image), imagesy($image)];
        $commonest = function (callable $point, int $count) use ($image): array {
            $votes = [];
            $samples = min($count, 256);

            for ($i = 0; $i < $samples; $i++) {
                $rgb = imagecolorat($image, ...$point((int) floor(($i + 0.5) * $count / $samples)));
                $rgb = imageistruecolor($image) ? $rgb & 0xFFFFFF : $this->paletteRgb($image, $rgb);
                $bucket = ($rgb >> 4) & 0x0F0F0F;
                $votes[$bucket] ??= [0, $rgb];
                $votes[$bucket][0]++;
            }

            uasort($votes, fn ($a, $b) => $b[0] <=> $a[0]);
            $rgb = reset($votes)[1];

            return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
        };

        return $sides
            ? [$commonest(fn ($i) => [0, $i], $height), $commonest(fn ($i) => [$width - 1, $i], $height)]
            : [$commonest(fn ($i) => [$i, 0], $width), $commonest(fn ($i) => [$i, $height - 1], $width)];
    }

    private function paletteRgb($image, int $index): int
    {
        $c = imagecolorsforindex($image, $index);

        return ($c['red'] << 16) | ($c['green'] << 8) | $c['blue'];
    }

    /** "#rrggbb" as [r, g, b], or null. */
    private static function rgb(?string $hex): ?array
    {
        if (! is_string($hex) || ! preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $hex, $m)) {
            return null;
        }

        return [hexdec($m[1]), hexdec($m[2]), hexdec($m[3])];
    }

    /** Near-black on a light ground, white on a dark one. */
    private function inkOn(array $rgb): array
    {
        $luminance = (0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2]) / 255;

        return $luminance > 0.55 ? [26, 26, 26] : [255, 255, 255];
    }

    private function allocate($image, array $rgb): int
    {
        return imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
    }

    // --- PDF --------------------------------------------------------------

    /**
     * One page, the size of the whole sheet in points, holding the JPEG.
     * TrimBox is the sheet less the bleed, BleedBox the sheet.
     */
    private function wrapPdf(string $jpeg, array $g, string $title): string
    {
        $size = getimagesizefromstring($jpeg);
        $w = round($g['width'] * 72, 3);
        $h = round($g['height'] * 72, 3);
        $b = round($g['bleed'] * 72, 3);
        $content = "q {$w} 0 0 {$h} 0 0 cm /Im0 Do Q";
        $text = fn (string $s) => '('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], Str::ascii($s)).')';

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$w} {$h}] /BleedBox [0 0 {$w} {$h}] "
                .'/TrimBox ['.$b.' '.$b.' '.round($w - $b, 3).' '.round($h - $b, 3).'] '
                .'/Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>',
            "<< /Type /XObject /Subtype /Image /Width {$size[0]} /Height {$size[1]} /ColorSpace /DeviceRGB "
                .'/BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($jpeg)." >>\nstream\n".$jpeg."\nendstream",
            '<< /Length '.strlen($content)." >>\nstream\n".$content."\nendstream",
            '<< /Title '.$text($title.' - cover').' /Producer (Ecrivain) >>',
        ];

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$object."\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n".'0 '.(count($objects) + 1)."\n".'0000000000 65535 f '."\n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset)."\n";
        }

        return $pdf.'trailer'."\n".'<< /Size '.(count($objects) + 1).' /Root 1 0 R /Info 6 0 R >>'."\n"
            .'startxref'."\n".$xref."\n".'%%EOF'."\n";
    }
}
