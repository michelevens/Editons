<?php

namespace App\Covers;

use App\Models\Book;
use App\Publishing\CoverRenderer;
use App\Publishing\CoverWrap;
use App\Publishing\PrintBook;
use Illuminate\Support\Facades\Storage;

/**
 * How well each text layer stands out from what is under it (ADR — Cover
 * backgrounds and series templates).
 *
 * WCAG's contrast ratio between the layer's ink and the ground beneath its
 * box: the panel's background (a palette, the author's colour or gradient,
 * the artwork) with every picture and panel drawn below the layer, sampled
 * on a grid over the box. A gradient or a picture is not one colour, so the
 * ratio reported is the low end of what the box sits on (the tenth
 * percentile of the samples), not the average: type that is legible over
 * most of its box and lost over a corner of it is flagged. Over a picture
 * the figure is labelled approximate.
 *
 * Large text needs 3:1, other text 4.5:1 (WCAG 2.x, 1.4.3): large is 18 pt,
 * or 14 pt in a semibold or bold weight, at the size the layer prints on
 * the book's trim.
 */
class CoverContrast
{
    public const NORMAL = 4.5;

    public const LARGE = 3.0;

    /** The width the front is measured at: 1600 × 2560 scaled by a quarter. */
    private const FRONT_WIDTH = 400;

    /** The resolution the wrap's back and spine are measured at. */
    private const WRAP_DPI = 40;

    /** WCAG relative contrast between two colours (hex or RGB). */
    public static function ratio(string|array $first, string|array $second): float
    {
        $a = self::luminance(is_string($first) ? CoverBackground::rgb($first) : $first);
        $b = self::luminance(is_string($second) ? CoverBackground::rgb($second) : $second);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /** WCAG relative luminance of 0–255 RGB. */
    public static function luminance(array $rgb): float
    {
        $c = array_map(function (int|float $v): float {
            $v /= 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, array_slice($rgb, 0, 3));

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    /**
     * White or near-black, whichever reads better over the whole of a
     * colour background: the higher of their lowest ratios on a 5 × 5 grid.
     */
    public static function inkFor(array $background): string
    {
        $worst = ['#ffffff' => INF, '#1a1a1a' => INF];

        for ($i = 0; $i < 5; $i++) {
            for ($j = 0; $j < 5; $j++) {
                $rgb = CoverBackground::colorAt($background, ($i + 0.5) / 5, ($j + 0.5) / 5, CoverRenderer::WIDTH, CoverRenderer::HEIGHT) ?? [0, 0, 0];

                foreach ($worst as $ink => $low) {
                    $worst[$ink] = min($low, self::ratio($ink, $rgb));
                }
            }
        }

        return $worst['#ffffff'] >= $worst['#1a1a1a'] ? '#ffffff' : '#1a1a1a';
    }

    /** The ratio a layer needs, at its printed size. */
    public static function needed(array $layer, float $points): float
    {
        return self::large($layer, $points) ? self::LARGE : self::NORMAL;
    }

    public static function large(array $layer, float $points): bool
    {
        return $points >= 18 || ($points >= 14 && in_array($layer['weight'] ?? 'regular', ['semibold', 'bold'], true));
    }

    /**
     * Each text layer of the front, measured. A layout cover is measured as
     * the layers "Customise text" would make of it; a finished cover's type
     * is in its artwork and is not measured.
     *
     * @return list<array<string, mixed>>
     */
    public static function front(Book $book): array
    {
        $covers = app(CoverRenderer::class);
        $settings = $covers->settings($book);
        $disk = Storage::disk('files');
        $artwork = $settings['artwork'] && $disk->exists($settings['artwork']) ? $disk->get($settings['artwork']) : null;
        $layers = $settings['layers'] ?? null;

        if ($layers === null) {
            if ($artwork !== null && $settings['layout'] === 'finished') {
                return [];
            }

            $layers = $covers->layersFromLayout($book);
        }

        $ground = @imagecreatefromstring($covers->scale($covers->render(array_merge($settings, ['layers' => null]), $artwork, withText: false), self::FRONT_WIDTH));

        if (! $ground) {
            return [];
        }

        [$trimWidth, $trimHeight] = PrintBook::trimInches($book->layout()['trim']);
        $surface = ['width' => CoverRenderer::WIDTH, 'height' => CoverRenderer::HEIGHT];
        $scale = imagesx($ground) / CoverRenderer::WIDTH;
        $approximate = $artwork !== null || (($settings['background']['kind'] ?? null) === 'image');
        $rows = self::measure($covers, $ground, $layers['items'], $settings['texts'] ?? CoverLayers::texts($book), $settings['images'] ?? [], $surface, $scale,
            fn (array $box) => $box, fn (array $layer) => $layer['size'] * $trimHeight * 72, 'front', $approximate);
        imagedestroy($ground);

        return $rows;
    }

    /**
     * The back's and spine's text layers, measured on the wrap as it prints
     * for geometry $g.
     *
     * @return array{back: list<array<string, mixed>>, spine: list<array<string, mixed>>}
     */
    public static function wrap(Book $book, array $g): array
    {
        $out = ['back' => [], 'spine' => []];
        $back = CoverLayers::stored($book, 'back');
        $spine = CoverLayers::stored($book, 'spine');

        if (! $back && ! $spine) {
            return $out;
        }

        $wraps = app(CoverWrap::class);
        $covers = app(CoverRenderer::class);
        $ground = @imagecreatefromstring($wraps->preview($book, $g, self::WRAP_DPI, bare: true));

        if (! $ground) {
            return $out;
        }

        $surfaces = $wraps->surfaces($g);
        $texts = CoverLayers::wrapTexts($book);
        $images = [];

        foreach (CoverImages::all($book) as $image) {
            $images[$image['id']] = $image;
        }

        $scale = self::WRAP_DPI / CoverWrap::MODEL_DPI;
        $approximate = $wraps->backArtwork($book) !== null || (CoverBackground::for($book, 'wrap')['kind'] ?? null) === 'image';

        if ($back) {
            $panelHeight = $g['height'];
            $out['back'] = self::measure($covers, $ground, $back['items'], $texts, $images, $surfaces['back'], $scale,
                fn (array $box) => $box, fn (array $layer) => $layer['size'] * $panelHeight * 72, 'back', $approximate);
        }

        if ($spine && $g['spine_text']) {
            $direction = $wraps->direction($book)['direction'];
            $px = CoverWrap::MODEL_DPI;
            // The box in the spine's reading frame, turned onto the sheet.
            $onSheet = function (array $box) use ($direction, $g, $px) {
                $corners = [];

                foreach ([[$box['x'], $box['y']], [$box['x'] + $box['width'], $box['y'] + $box['height']]] as [$u, $v]) {
                    $corners[] = CoverText::spinePoint($u, $v, $direction, $g['spine_x'] * $px, $g['spine'] * $px, $g['height'] * $px);
                }

                return ['x' => min($corners[0][0], $corners[1][0]), 'y' => min($corners[0][1], $corners[1][1]),
                    'width' => abs($corners[1][0] - $corners[0][0]), 'height' => abs($corners[1][1] - $corners[0][1])];
            };
            $length = $g['height'];
            // Pictures and panels in the spine's frame are not drawn under
            // its layers here: the spine is measured on its ground.
            $out['spine'] = self::measure(null, $ground, $spine['items'], $texts, $images, $surfaces['spine'], $scale,
                $onSheet, fn (array $layer) => $layer['size'] * $length * 72, 'spine', $approximate);
        }

        imagedestroy($ground);

        return $out;
    }

    /**
     * Measure every visible text layer of a panel on $ground.
     *
     * @param  callable(array): array  $toImage  a layer's box on its surface → on $ground, before scaling
     * @param  callable(array): float  $points  the layer's printed size
     * @return list<array<string, mixed>>
     */
    private static function measure(?CoverRenderer $covers, $ground, array $items, array $texts, array $images, array $surface, float $scale,
        callable $toImage, callable $points, string $panel, bool $approximate): array
    {
        $rows = [];

        foreach ($items as $i => $layer) {
            if (! $layer['visible'] || $layer['source'] === 'image' || trim(CoverLayers::text($layer, $texts)) === '') {
                continue;
            }

            $canvas = imagecreatetruecolor(imagesx($ground), imagesy($ground));
            imagecopy($canvas, $ground, 0, 0, 0, 0, imagesx($ground), imagesy($ground));

            if ($covers) {
                // Everything under this layer, and its own panel, without type.
                $covers->drawItems($canvas, array_slice($items, 0, $i + 1), $texts, $images, $surface, $scale, type: false);
            }

            $box = $toImage(CoverRenderer::place($layer, $surface, $texts)['box']);
            $ratios = self::sample($canvas, $layer, array_map(fn ($v) => $v * $scale, $box));
            imagedestroy($canvas);

            if ($ratios === []) {
                continue;
            }

            sort($ratios);
            $ratio = $ratios[(int) floor(0.1 * (count($ratios) - 1))];
            $pt = $points($layer);
            $needed = self::needed($layer, $pt);

            $rows[] = [
                'panel' => $panel,
                'id' => $layer['id'],
                'source' => $layer['source'],
                'ratio' => round($ratio, 2),
                'needed' => $needed,
                'large' => $needed === self::LARGE,
                'points' => round($pt, 1),
                'ok' => $ratio >= $needed,
                'approximate' => $approximate,
            ];
        }

        return $rows;
    }

    /**
     * Ratios on a grid over the box (inset a tenth on every side), the ink
     * mixed with the ground at the layer's opacity.
     *
     * @return list<float>
     */
    private static function sample($canvas, array $layer, array $box): array
    {
        [$w, $h] = [imagesx($canvas), imagesy($canvas)];
        $ink = CoverBackground::rgb($layer['color']);
        $opacity = max(0.0, min(1.0, (float) $layer['opacity']));
        $cols = 9;
        $rowsN = max(3, min(12, (int) round($box['height'] / max(1, $box['width']) * $cols)));
        $out = [];

        for ($j = 0; $j < $rowsN; $j++) {
            for ($i = 0; $i < $cols; $i++) {
                $x = (int) floor($box['x'] + $box['width'] * (0.1 + 0.8 * ($i + 0.5) / $cols));
                $y = (int) floor($box['y'] + $box['height'] * (0.1 + 0.8 * ($j + 0.5) / $rowsN));

                if ($x < 0 || $y < 0 || $x >= $w || $y >= $h) {
                    continue;
                }

                $c = imagecolorat($canvas, $x, $y);
                $bg = [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
                $fg = [
                    $ink[0] * $opacity + $bg[0] * (1 - $opacity),
                    $ink[1] * $opacity + $bg[1] * (1 - $opacity),
                    $ink[2] * $opacity + $bg[2] * (1 - $opacity),
                ];
                $out[] = self::ratio($fg, $bg);
            }
        }

        return $out;
    }

    /**
     * The worst-placed layer of a list: the one furthest below what it
     * needs (or closest to it, when all pass).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    public static function worst(array $rows): ?array
    {
        if ($rows === []) {
            return null;
        }

        usort($rows, fn ($a, $b) => $a['ratio'] / $a['needed'] <=> $b['ratio'] / $b['needed']);

        return $rows[0];
    }
}
