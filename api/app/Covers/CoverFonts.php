<?php

namespace App\Covers;

use Dompdf\Options;
use FontLib\Font;
use Illuminate\Support\Facades\Cache;

/**
 * The typefaces a cover's text layers can be set in.
 *
 * One list for both ends: the server draws each face with GD from these
 * files, and Cover Studio loads the very same files through @font-face
 * (FontController), so the live canvas and the JPEG are one design. The
 * metrics — advance widths from each file's own hmtx table — are what both
 * ends wrap and place text with (CoverText, and coverText.js in the
 * browser), so a line breaks at the same word on both.
 *
 * Every face is SIL OFL 1.1, from github.com/google/fonts (licences beside
 * the files, provenance in resources/fonts/cover/SOURCES.txt). Families
 * with a Reserved Font Name ship unmodified, which leaves only their
 * default (regular) instance, since GD cannot pick a variable font's
 * weight; the others were cut into static weights.
 */
class CoverFonts
{
    /**
     * Families, in the order the font picker offers them.
     *
     * Each style names its file under resources/fonts; a style the family
     * lacks is absent, and the server refuses a layer that asks for it.
     */
    public const FAMILIES = [
        'eb-garamond' => [
            'name' => 'EB Garamond', 'category' => 'serif',
            'styles' => [
                'regular' => 'EBGaramond-Regular', 'regular-italic' => 'EBGaramond-Italic',
                'semibold' => 'EBGaramond-SemiBold', 'semibold-italic' => 'EBGaramond-SemiBoldItalic',
            ],
        ],
        'playfair-display' => [
            'name' => 'Playfair Display', 'category' => 'serif',
            'styles' => [
                'regular' => 'cover/PlayfairDisplay-Regular-VF', 'regular-italic' => 'cover/PlayfairDisplay-Italic-VF',
            ],
        ],
        'cormorant-garamond' => [
            'name' => 'Cormorant Garamond', 'category' => 'serif',
            'styles' => [
                'regular' => 'cover/CormorantGaramond-Regular', 'regular-italic' => 'cover/CormorantGaramond-Italic',
                'semibold' => 'cover/CormorantGaramond-SemiBold', 'semibold-italic' => 'cover/CormorantGaramond-SemiBoldItalic',
                'bold' => 'cover/CormorantGaramond-Bold', 'bold-italic' => 'cover/CormorantGaramond-BoldItalic',
            ],
        ],
        'lora' => [
            'name' => 'Lora', 'category' => 'serif',
            'styles' => ['regular' => 'cover/Lora-Regular-VF', 'regular-italic' => 'cover/Lora-Italic-VF'],
        ],
        'libre-baskerville' => [
            'name' => 'Libre Baskerville', 'category' => 'serif',
            'styles' => [
                'regular' => 'cover/LibreBaskerville-Regular-VF', 'regular-italic' => 'cover/LibreBaskerville-Italic-VF',
            ],
        ],
        'cinzel' => [
            'name' => 'Cinzel', 'category' => 'display',
            'styles' => ['regular' => 'cover/Cinzel-Regular', 'semibold' => 'cover/Cinzel-SemiBold', 'bold' => 'cover/Cinzel-Bold'],
        ],
        'source-sans' => [
            'name' => 'Source Sans 3', 'category' => 'sans',
            'styles' => [
                'regular' => 'SourceSans3-Regular', 'regular-italic' => 'SourceSans3-It',
                'semibold' => 'SourceSans3-Semibold', 'semibold-italic' => 'SourceSans3-SemiboldIt',
            ],
        ],
        'montserrat' => [
            'name' => 'Montserrat', 'category' => 'sans',
            'styles' => [
                'regular' => 'cover/Montserrat-Regular', 'regular-italic' => 'cover/Montserrat-Italic',
                'semibold' => 'cover/Montserrat-SemiBold', 'semibold-italic' => 'cover/Montserrat-SemiBoldItalic',
                'bold' => 'cover/Montserrat-Bold', 'bold-italic' => 'cover/Montserrat-BoldItalic',
            ],
        ],
        'oswald' => [
            'name' => 'Oswald', 'category' => 'display',
            'styles' => ['regular' => 'cover/Oswald-Regular', 'semibold' => 'cover/Oswald-SemiBold', 'bold' => 'cover/Oswald-Bold'],
        ],
        'bebas-neue' => [
            'name' => 'Bebas Neue', 'category' => 'display',
            'styles' => ['regular' => 'cover/BebasNeue-Regular'],
        ],
        'great-vibes' => [
            'name' => 'Great Vibes', 'category' => 'script',
            'styles' => ['regular' => 'cover/GreatVibes-Regular'],
        ],
    ];

    public const WEIGHTS = ['regular', 'semibold', 'bold'];

    /**
     * The face for characters a family lacks: DejaVu Sans, which dompdf
     * ships and the PDF already falls back to (ADR-0018's glyph fallback).
     */
    public const FALLBACK = 'fallback';

    /** Code points past this are not measured: covers do not set them. */
    private const LAST_CODE_POINT = 0xFFFF;

    /** Bumped when the metrics' shape changes, so caches refill. */
    private const METRICS_VERSION = 1;

    /** @var array<string, array<string, mixed>> */
    private static array $memo = [];

    public static function exists(string $family): bool
    {
        return isset(self::FAMILIES[$family]);
    }

    /** The style key for a weight and slant: "semibold-italic". */
    public static function style(string $weight, bool $italic): string
    {
        return $weight.($italic ? '-italic' : '');
    }

    /** Whether a family has a weight, upright or italic. */
    public static function has(string $family, string $weight, bool $italic): bool
    {
        return isset(self::FAMILIES[$family]['styles'][self::style($weight, $italic)]);
    }

    /**
     * A face's key, as the font route and the browser name it:
     * "montserrat.bold-italic", or "fallback".
     */
    public static function faceKey(string $family, string $weight, bool $italic): string
    {
        return $family.'.'.self::style($weight, $italic);
    }

    /** The TTF behind a face key, or null for a key that is not one. */
    public static function file(string $face): ?string
    {
        if ($face === self::FALLBACK) {
            return (new Options)->getRootDir().'/lib/fonts/DejaVuSans.ttf';
        }

        [$family, $style] = array_pad(explode('.', $face, 2), 2, null);
        $name = self::FAMILIES[$family]['styles'][$style] ?? null;

        return $name ? resource_path("fonts/{$name}.ttf") : null;
    }

    /** Every face key, the fallback included. @return array<int, string> */
    public static function faces(): array
    {
        $faces = [];

        foreach (self::FAMILIES as $family => $spec) {
            foreach (array_keys($spec['styles']) as $style) {
                $faces[] = "{$family}.{$style}";
            }
        }

        $faces[] = self::FALLBACK;

        return $faces;
    }

    /**
     * The families as Cover Studio needs them: names, styles, and where
     * to fetch each face and its metrics.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function catalog(): array
    {
        $catalog = [];

        foreach (self::FAMILIES as $key => $spec) {
            $styles = [];

            foreach (array_keys($spec['styles']) as $style) {
                $styles[$style] = self::urls("{$key}.{$style}");
            }

            $catalog[] = ['key' => $key, 'name' => $spec['name'], 'category' => $spec['category'], 'styles' => $styles];
        }

        return $catalog;
    }

    /** @return array{key: string, font: string, metrics: string} */
    public static function urls(string $face): array
    {
        $file = (string) self::file($face);
        // Versioned by the file, so a replaced face is never served from
        // a browser's year-long cache.
        $v = substr(md5(self::METRICS_VERSION.'|'.@filesize($file).'|'.basename($file)), 0, 10);

        return [
            'key' => $face,
            'font' => route('cover-fonts.file', ['face' => $face, 'v' => $v]),
            'metrics' => route('cover-fonts.metrics', ['face' => $face, 'v' => $v]),
        ];
    }

    /**
     * What wrapping and placing text needs from a face: units per em, the
     * ascender and descender, and each character's advance, in font units.
     *
     * Read from the file's own tables and cached: a parse takes a few
     * tenths of a second.
     *
     * @return array{upm: int, asc: int, desc: int, adv: array<int, int>}
     */
    public static function metrics(string $face): array
    {
        $file = self::file($face);

        if ($file === null || ! is_file($file)) {
            throw new \InvalidArgumentException("No cover face {$face}.");
        }

        $key = 'cover-font-metrics:'.self::METRICS_VERSION.':'.$face.':'.filesize($file);

        return self::$memo[$key] ??= Cache::rememberForever($key, fn () => self::read($file));
    }

    /** @return array{upm: int, asc: int, desc: int, adv: array<int, int>} */
    private static function read(string $file): array
    {
        $font = Font::load($file);
        // The table directory, then only the tables needed: a full parse
        // reads every glyph.
        $font->parseTableEntries();

        $upm = (int) $font->getData('head', 'unitsPerEm');
        $hhea = $font->getData('hhea');
        $hmtx = $font->getData('hmtx');
        $cmap = $font->getUnicodeCharMap() ?? [];
        $font->close();

        $advances = [];

        foreach ($cmap as $codePoint => $glyph) {
            if ($codePoint < 0x20 || $codePoint > self::LAST_CODE_POINT || $glyph === 0) {
                continue;
            }

            $metric = $hmtx[$glyph] ?? null;
            $advances[(int) $codePoint] = (int) (is_array($metric) ? $metric[0] : $metric);
        }

        ksort($advances);

        return [
            'upm' => $upm,
            'asc' => (int) $hhea['ascent'],
            'desc' => abs((int) $hhea['descent']),
            'adv' => $advances,
        ];
    }
}
