<?php

namespace App\Covers;

use Normalizer;

/**
 * Where every character of a text layer goes.
 *
 * The one algorithm both ends run: this class for the server's render, and
 * resources/js/Components/Studio/cover/coverText.js for Cover Studio's live
 * canvas, line for line. Both measure with the same numbers (CoverFonts'
 * advances, from the font files' hmtx tables) in the same order, so they
 * break at the same words and put each glyph at the same x — the browser
 * is handed every glyph's position rather than left to its own wrapping,
 * kerning or letter-spacing. tests/Feature/CoverTextParityTest.php runs the
 * JavaScript on the same fixtures.
 *
 * The rules: the box is the layer's width; text breaks between words at
 * spaces (an explicit line break in custom text starts a new line); a word
 * wider than the box breaks between characters; no kerning, no ligatures.
 * The first baseline sits one ascender below the box's top, and each next
 * one `leading` × size lower. Typing never moves the box: it grows down.
 */
class CoverText
{
    /** Width comparisons allow this much, in pixels, for float sums. */
    private const EPSILON = 0.01;

    /**
     * Lay out one layer's text at a canvas size.
     *
     * @param  array<string, mixed>  $layer  a validated layer (CoverLayers)
     * @return array{box: array{x: float, y: float, width: float, height: float}, size: float, face: string, lines: array<int, array{text: string, baseline: float, x: float, width: float, glyphs: array<int, array{char: string, x: float, face: string}>}>}
     */
    public static function layout(array $layer, string $text, float $width, float $height): array
    {
        $face = CoverFonts::faceKey($layer['font'], $layer['weight'], (bool) $layer['italic']);
        $metrics = CoverFonts::metrics($face);
        $fallback = CoverFonts::metrics(CoverFonts::FALLBACK);

        $size = $layer['size'] * $height;
        $spacing = $layer['tracking'] * $size;
        $boxX = $layer['x'] * $width;
        $boxY = $layer['y'] * $height;
        $boxWidth = $layer['width'] * $width;

        $advance = function (string $char) use ($metrics, $fallback, $size): float {
            $code = mb_ord($char);

            if (isset($metrics['adv'][$code])) {
                return $metrics['adv'][$code] / $metrics['upm'] * $size;
            }

            return ($fallback['adv'][$code] ?? $fallback['upm'] / 2) / $fallback['upm'] * $size;
        };

        $measure = function (array $chars) use ($advance, $spacing): float {
            $sum = 0.0;

            foreach ($chars as $i => $char) {
                $sum += $advance($char);

                if ($i > 0) {
                    $sum += $spacing;
                }
            }

            return $sum;
        };

        $lines = [];

        foreach (self::paragraphs(self::prepare($text, (bool) $layer['uppercase'])) as $paragraph) {
            foreach (self::wrap($paragraph, $measure, $boxWidth) as $line) {
                $lines[] = $line;
            }
        }

        $ascent = $metrics['asc'] / $metrics['upm'] * $size;
        $descent = $metrics['desc'] / $metrics['upm'] * $size;
        $step = $layer['leading'] * $size;

        $placed = [];

        foreach ($lines as $i => $chars) {
            $lineWidth = $measure($chars);
            $start = match ($layer['align']) {
                'left' => $boxX,
                'right' => $boxX + $boxWidth - $lineWidth,
                default => $boxX + ($boxWidth - $lineWidth) / 2,
            };

            $glyphs = [];
            $cursor = $start;

            foreach ($chars as $char) {
                $glyphs[] = [
                    'char' => $char,
                    'x' => $cursor,
                    'face' => isset($metrics['adv'][mb_ord($char)]) ? $face : CoverFonts::FALLBACK,
                ];
                $cursor += $advance($char) + $spacing;
            }

            $placed[] = [
                'text' => implode('', $chars),
                'baseline' => $boxY + $ascent + $i * $step,
                'x' => $start,
                'width' => $lineWidth,
                'glyphs' => $glyphs,
            ];
        }

        // No text, no height: an empty custom layer is a shape, as tall as
        // its own height says.
        $textHeight = $lines === [] ? 0.0 : $ascent + $descent + (count($lines) - 1) * $step;

        return [
            'box' => [
                'x' => $boxX,
                'y' => $boxY,
                'width' => $boxWidth,
                'height' => max($textHeight, ($layer['height'] ?? 0) * $height),
            ],
            'size' => $size,
            'face' => $face,
            'lines' => $placed,
        ];
    }

    /**
     * A spine layer laid out in the spine's reading frame: a strip as long
     * as the spine ($length) and as deep as it is thick ($thickness), the
     * text running along it as it does once the book is turned to read it.
     *
     * x, width and size are fractions of the length; y moves the box's
     * centre off the strip's centre line, by a fraction of the length. So
     * the layout is set exactly as a front layer is (a strip $length
     * square, then moved down), and a layer stays centred across the spine
     * whatever the page count makes its thickness.
     *
     * @param  array<string, mixed>  $layer  a validated spine layer
     * @return array<string, mixed> as layout()
     */
    public static function spine(array $layer, string $text, float $length, float $thickness): array
    {
        $set = self::layout(array_merge($layer, ['y' => 0.0]), $text, $length, $length);

        return self::shift($set, 0.0, $thickness / 2 + $layer['y'] * $length - $set['box']['height'] / 2);
    }

    /**
     * A point of the spine's reading frame on the sheet, the frame's
     * origin at the start of reading and the top of the letters.
     *
     * Top to bottom, the frame turns clockwise: reading runs down the sheet
     * and the tops of the letters face the front cover. Bottom to top, it
     * turns anticlockwise: reading runs up and the tops face the back.
     * $spineX is the spine's left fold, $sheetHeight its length, both in
     * the same units as the point.
     *
     * @return array{0: float, 1: float}
     */
    public static function spinePoint(float $u, float $v, string $direction, float $spineX, float $thickness, float $sheetHeight): array
    {
        return $direction === 'btt'
            ? [$spineX + $v, $sheetHeight - $u]
            : [$spineX + $thickness - $v, $u];
    }

    /**
     * A layout moved by ($dx, $dy): its box, baselines and every glyph.
     *
     * @param  array<string, mixed>  $set
     * @return array<string, mixed>
     */
    public static function shift(array $set, float $dx, float $dy): array
    {
        $set['box']['x'] += $dx;
        $set['box']['y'] += $dy;

        foreach ($set['lines'] as $i => $line) {
            $set['lines'][$i]['baseline'] += $dy;
            $set['lines'][$i]['x'] += $dx;

            foreach ($line['glyphs'] as $g => $glyph) {
                $set['lines'][$i]['glyphs'][$g]['x'] += $dx;
            }
        }

        return $set;
    }

    /** NFC, so "é" is one character on both ends; capitals if asked. */
    public static function prepare(string $text, bool $uppercase): string
    {
        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $text);

        if (class_exists(Normalizer::class)) {
            $text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
        }

        return $uppercase ? mb_strtoupper($text) : $text;
    }

    /** @return array<int, string> */
    private static function paragraphs(string $text): array
    {
        return trim($text) === '' ? [] : explode("\n", $text);
    }

    /**
     * Greedy: as many words on a line as fit, a too-wide word broken
     * between characters.
     *
     * @return array<int, array<int, string>>
     */
    private static function wrap(string $paragraph, callable $measure, float $width): array
    {
        $words = array_values(array_filter(explode(' ', $paragraph), fn ($w) => $w !== ''));

        if ($words === []) {
            return [[]];
        }

        $lines = [];
        $line = null;

        foreach ($words as $word) {
            $chars = mb_str_split($word);

            if ($measure($chars) > $width + self::EPSILON) {
                if ($line !== null) {
                    $lines[] = $line;
                    $line = null;
                }

                $chunk = [];

                foreach ($chars as $char) {
                    if ($chunk !== [] && $measure([...$chunk, $char]) > $width + self::EPSILON) {
                        $lines[] = $chunk;
                        $chunk = [];
                    }

                    $chunk[] = $char;
                }

                $line = $chunk;

                continue;
            }

            $candidate = $line === null ? $chars : [...$line, ' ', ...$chars];

            if ($line === null || $measure($candidate) <= $width + self::EPSILON) {
                $line = $candidate;
            } else {
                $lines[] = $line;
                $line = $chars;
            }
        }

        $lines[] = $line;

        return $lines;
    }
}
