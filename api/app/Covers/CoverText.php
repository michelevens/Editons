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
    public static function layout(array $layer, string $text, int $width, int $height): array
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
