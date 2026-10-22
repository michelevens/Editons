<?php

namespace App\Publishing;

use Dompdf\Options;
use FontLib\Font;
use FontLib\TrueType\File;

/**
 * Letters as drawn outlines, for the text inside typeset formulas.
 *
 * MathJax draws what its TeX fonts cover as paths, and anything else (é, ô,
 * ç in \text{réel}, \text{impôts}) as an SVG <text> element. The PDF's SVG
 * reader sets such text in the built-in Times, unembedded and without the
 * accented letters, so "PIB réel" printed as "PIB r el". Drawn as outlines
 * from the book's own Garamond, the letters are part of the formula like
 * every other glyph, and need no font at all.
 */
class GlyphOutlines
{
    /** @var array<string, array{font: File, map: array<int, int>, upem: int, hmtx: array, glyf: array}> */
    private static array $faces = [];

    /**
     * The outlines of a run of text, set from its baseline at the origin.
     *
     * Coordinates are SVG's (y grows downward) in MathJax's 1000 units per
     * em, and $size is the font size in those units, so each matrix places
     * one glyph in the text element's own space. math.cjs measures the same
     * Garamond advances, so the run fills exactly the room left for it.
     *
     * @return array<int, array{0: string, 1: array<int, float>}> [path data, matrix]
     */
    public function paths(string $text, float $size, bool $italic = false, bool $bold = false): array
    {
        $paths = [];
        $pen = 0.0;

        foreach (mb_str_split($text) as $character) {
            $face = $this->faceFor(mb_ord($character), $italic, $bold);

            if ($face === null) {
                // Nothing can draw it: leave the 0.6em MathJax set aside.
                $pen += 600;

                continue;
            }

            $glyph = $face['map'][mb_ord($character)];
            $scale = $size / $face['upem'];

            // Font units grow upward; SVG's grow down.
            $this->collect($face, $glyph, [$scale, 0, 0, -$scale, $pen, 0], $paths);
            $pen += ($face['hmtx'][$glyph][0] ?? $face['upem'] / 2) * $scale;
        }

        return $paths;
    }

    /**
     * @param  array<int, float>  $matrix
     * @param  array<int, array{0: string, 1: array<int, float>}>  $paths
     */
    private function collect(array $face, int $glyph, array $matrix, array &$paths): void
    {
        $outline = $face['glyf'][$glyph] ?? null;

        if ($outline === null) {
            return;
        }

        $outline->parseData();
        $this->append($outline->getSVGContours(), $matrix, $paths);
    }

    /**
     * A simple glyph's contours are one path; a composite's are its parts,
     * each with its own transform (an accent placed over a letter).
     *
     * @param  string|array<int, mixed>  $contours
     * @param  array<int, float>  $matrix
     * @param  array<int, array{0: string, 1: array<int, float>}>  $paths
     */
    private function append(string|array $contours, array $matrix, array &$paths): void
    {
        if (is_string($contours)) {
            if (trim($contours) !== '') {
                $paths[] = [trim($contours), $matrix];
            }

            return;
        }

        foreach ($contours as $part) {
            $this->append($part['contours'] ?? '', self::multiply($matrix, array_map('floatval', $part['transform'] ?? [1, 0, 0, 1, 0, 0])), $paths);
        }
    }

    /** The book face in the right style, or DejaVu Sans for what it lacks. */
    private function faceFor(int $codePoint, bool $italic, bool $bold): ?array
    {
        $garamond = 'EBGaramond-'.match (true) {
            $bold && $italic => 'SemiBoldItalic',
            $bold => 'SemiBold',
            $italic => 'Italic',
            default => 'Regular',
        };

        foreach ([resource_path("fonts/{$garamond}.ttf"), (new Options)->getRootDir().'/lib/fonts/DejaVuSans.ttf'] as $file) {
            $face = self::load($file);

            if ($face !== null && isset($face['map'][$codePoint])) {
                return $face;
            }
        }

        return null;
    }

    private static function load(string $file): ?array
    {
        if (array_key_exists($file, self::$faces)) {
            return self::$faces[$file];
        }

        if (! is_file($file)) {
            return self::$faces[$file] = null;
        }

        // The table directory and the tables needed, never a full parse,
        // which reads every glyph and takes seconds.
        $font = Font::load($file);
        $font->parseTableEntries();

        return self::$faces[$file] = [
            'font' => $font,
            'map' => $font->getUnicodeCharMap(),
            'upem' => (int) ($font->getData('head', 'unitsPerEm') ?: 1000),
            'hmtx' => $font->getData('hmtx'),
            'glyf' => $font->getData('glyf'),
        ];
    }

    /**
     * @param  array<int, float>  $m
     * @param  array<int, float>  $n
     * @return array<int, float>
     */
    public static function multiply(array $m, array $n): array
    {
        return [
            $m[0] * $n[0] + $m[2] * $n[1],
            $m[1] * $n[0] + $m[3] * $n[1],
            $m[0] * $n[2] + $m[2] * $n[3],
            $m[1] * $n[2] + $m[3] * $n[3],
            $m[0] * $n[4] + $m[2] * $n[5] + $m[4],
            $m[1] * $n[4] + $m[3] * $n[5] + $m[5],
        ];
    }
}
