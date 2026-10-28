<?php

namespace App\Publishing;

/**
 * The typefaces a printed interior can be set in (ADR-00XX — Mirrored
 * margins, trims, typography and house styles).
 *
 * Text faces, chosen for long reading at 9–24 pt: each has a real italic
 * and a real bold (or semibold), draws every letter French and Haitian
 * Creole need ("Réussir l'examen — Kreyòl"), and is a static TrueType file
 * dompdf can embed. All are SIL OFL 1.1; licences and provenance are beside
 * the files (resources/fonts, resources/fonts/interior/SOURCES.txt).
 *
 * The keys are what a book's layout stores: `serif` and `sans` are the two
 * faces the interior always had (EB Garamond and Source Sans 3), so a book
 * saved before this list grew reads the same.
 *
 * The browser previews load the same files (PreviewController::font), so
 * the Design & Layout page is drawn in the faces the PDF embeds.
 */
class InteriorFonts
{
    /**
     * key => name, category, and its four files (regular, italic, bold,
     * bold italic) under resources/fonts, without ".ttf". `bold` is the
     * weight the files' bold is (600 semibold or 700 bold), for the
     * browser's @font-face.
     */
    public const FAMILIES = [
        'serif' => [
            'name' => 'EB Garamond', 'category' => 'serif', 'bold' => 600,
            'files' => ['EBGaramond-Regular', 'EBGaramond-Italic', 'EBGaramond-SemiBold', 'EBGaramond-SemiBoldItalic'],
            'fallback' => 'Garamond, Georgia, serif',
        ],
        'lora' => [
            'name' => 'Lora', 'category' => 'serif', 'bold' => 700,
            'files' => ['interior/Lora-Regular', 'interior/Lora-Italic', 'interior/Lora-Bold', 'interior/Lora-BoldItalic'],
            'fallback' => 'Georgia, serif',
        ],
        'crimson-pro' => [
            'name' => 'Crimson Pro', 'category' => 'serif', 'bold' => 600,
            'files' => ['interior/CrimsonPro-Regular', 'interior/CrimsonPro-Italic', 'interior/CrimsonPro-SemiBold', 'interior/CrimsonPro-SemiBoldItalic'],
            'fallback' => 'Georgia, serif',
        ],
        'literata' => [
            'name' => 'Literata', 'category' => 'serif', 'bold' => 700,
            'files' => ['interior/Literata-Regular', 'interior/Literata-Italic', 'interior/Literata-Bold', 'interior/Literata-BoldItalic'],
            'fallback' => 'Georgia, serif',
        ],
        'spectral' => [
            'name' => 'Spectral', 'category' => 'serif', 'bold' => 600,
            'files' => ['interior/Spectral-Regular', 'interior/Spectral-Italic', 'interior/Spectral-SemiBold', 'interior/Spectral-SemiBoldItalic'],
            'fallback' => 'Georgia, serif',
        ],
        'alegreya' => [
            'name' => 'Alegreya', 'category' => 'serif', 'bold' => 700,
            'files' => ['interior/Alegreya-Regular', 'interior/Alegreya-Italic', 'interior/Alegreya-Bold', 'interior/Alegreya-BoldItalic'],
            'fallback' => 'Georgia, serif',
        ],
        'sans' => [
            'name' => 'Source Sans 3', 'category' => 'sans', 'bold' => 600,
            'files' => ['SourceSans3-Regular', 'SourceSans3-It', 'SourceSans3-Semibold', 'SourceSans3-SemiboldIt'],
            'fallback' => "'Helvetica Neue', Arial, sans-serif",
        ],
    ];

    public static function keys(): array
    {
        return array_keys(self::FAMILIES);
    }

    /** A stored key, or the serif for one that is no longer offered. */
    public static function key(?string $key): string
    {
        return isset(self::FAMILIES[$key]) ? $key : 'serif';
    }

    /** The family name dompdf and the browser know the face by. */
    public static function name(?string $key): string
    {
        return self::FAMILIES[self::key($key)]['name'];
    }

    /** The CSS font-family list: the face, then what a browser falls back to. */
    public static function stack(?string $key): string
    {
        $family = self::FAMILIES[self::key($key)];

        return "'{$family['name']}', {$family['fallback']}";
    }

    /** @return list<string> the four files, absolute */
    public static function paths(?string $key): array
    {
        return array_map(fn (string $file) => resource_path("fonts/{$file}.ttf"), self::FAMILIES[self::key($key)]['files']);
    }

    /**
     * Every file the previews may fetch, by its base name: what
     * /preview/fonts/{name} serves.
     *
     * @return array<string, string> "Lora-Regular.ttf" => "interior/Lora-Regular.ttf"
     */
    public static function files(): array
    {
        $out = [];

        foreach (self::FAMILIES as $family) {
            foreach ($family['files'] as $file) {
                $out[basename($file).'.ttf'] = $file.'.ttf';
            }
        }

        return $out;
    }

    /**
     * The faces for the Design screen and the browser previews: key, name,
     * category, and the @font-face sources.
     *
     * @return list<array<string, mixed>>
     */
    public static function catalog(): array
    {
        return collect(self::FAMILIES)->map(fn (array $family, string $key) => [
            'key' => $key,
            'name' => $family['name'],
            'category' => $family['category'],
            'bold' => $family['bold'],
            'files' => array_map(fn ($file) => basename($file).'.ttf', $family['files']),
        ])->values()->all();
    }
}
