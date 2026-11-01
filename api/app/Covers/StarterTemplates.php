<?php

namespace App\Covers;

use App\Models\Book;
use App\Publishing\CoverRenderer;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Starter cover templates: a designed, typographic cover with no artwork
 * (ADR — AI research, source-backed fact-checking, cover templates).
 *
 * A template is a layout of the book's own bound text (title, subtitle,
 * author, series line), a colour palette across the whole print wrap, a
 * type pairing from the cover faces, and a simple vector motif from a
 * small built-in library: book, pages, path, leaf, sun, kite, mountains,
 * abstract shapes. The motifs are original, drawn here as primitives in a
 * 100 × 100 box; the same drawing is served as SVG for the picker and
 * rasterised (GD, supersampled) into a PNG cover image for the renderer,
 * so the front, the print wrap and the ebook cover all draw it the same.
 * No likenesses, no stock art.
 *
 * Every palette's ink passes the text contrast check against both of its
 * colours, and every layout keeps the motif clear of the type, so a
 * template passes CoverContrast as applied. Applying writes ordinary
 * Cover Studio settings (layers, a wrap background, the faces), keeps
 * what it replaced in `cover.pre_template` (so "Restore the previous
 * cover" works), and everything stays editable.
 */
final class StarterTemplates
{
    /** Motif PNG size, in pixels (square); drawn at twice that and reduced. */
    public const MOTIF_PIXELS = 1200;

    public const MOTIFS = ['book', 'pages', 'path', 'leaf', 'sun', 'kite', 'mountains', 'shapes'];

    /**
     * Palettes: a wrap background (two stops), the ink for type, the
     * motif's accent. Each ink clears 4.5:1 on both stops.
     */
    public const PALETTES = [
        'midnight' => ['from' => '#1f2a44', 'to' => '#2e3f63', 'ink' => '#f7f3e8', 'accent' => '#e0b66c'],
        'ivory' => ['from' => '#f4efe4', 'to' => '#ebe3d2', 'ink' => '#1f2a37', 'accent' => '#b5523b'],
        'forest' => ['from' => '#1e3d32', 'to' => '#2c5545', 'ink' => '#f1efe6', 'accent' => '#a8c686'],
        'terracotta' => ['from' => '#7f3a24', 'to' => '#94452b', 'ink' => '#fff8ef', 'accent' => '#f2c48d'],
        'sky' => ['from' => '#e3f1fb', 'to' => '#c9e5f6', 'ink' => '#12324a', 'accent' => '#e07b2c'],
        'sunshine' => ['from' => '#ffe08a', 'to' => '#ffd166', 'ink' => '#2b2d42', 'accent' => '#d6336c'],
        'plum' => ['from' => '#3c1f3e', 'to' => '#5a2d5c', 'ink' => '#fbe9f0', 'accent' => '#f2a65a'],
        'slate' => ['from' => '#eef1f4', 'to' => '#dfe4e9', 'ink' => '#212529', 'accent' => '#2a6f97'],
    ];

    /** Type pairings: the title's face, weight and case; the rest's face. */
    public const PAIRINGS = [
        'classic' => ['title' => ['eb-garamond', 'semibold', false], 'text' => 'eb-garamond', 'italic' => true],
        'modern' => ['title' => ['montserrat', 'bold', true], 'text' => 'source-sans', 'italic' => false],
        'elegant' => ['title' => ['playfair-display', 'regular', false], 'text' => 'cormorant-garamond', 'italic' => true],
        'display' => ['title' => ['oswald', 'bold', true], 'text' => 'source-sans', 'italic' => false],
        'scholarly' => ['title' => ['cinzel', 'semibold', false], 'text' => 'libre-baskerville', 'italic' => true],
        'friendly' => ['title' => ['montserrat', 'bold', false], 'text' => 'lora', 'italic' => false],
    ];

    /**
     * The templates. Boxes are fractions of the front (x, y, width of the
     * width; y of the height; size of the height); the motif's box is
     * square on the page. `for` is who it suits, shown in the picker.
     */
    public const TEMPLATES = [
        'quiet' => [
            'for' => ['nonfiction', 'memoir', 'biography'],
            'palette' => 'ivory', 'pairing' => 'classic', 'motif' => 'book', 'align' => 'center',
            'motif_box' => ['x' => 0.38, 'y' => 0.15, 'width' => 0.24],
            'series' => ['y' => 0.07, 'size' => 0.02],
            'title' => ['y' => 0.36, 'size' => 0.062],
            'subtitle' => ['y' => 0.56, 'size' => 0.03],
            'author' => ['y' => 0.85, 'size' => 0.034],
        ],
        'statement' => [
            'for' => ['business', 'self_help', 'nonfiction'],
            'palette' => 'midnight', 'pairing' => 'display', 'motif' => 'shapes', 'align' => 'left',
            'motif_box' => ['x' => 0.52, 'y' => 0.58, 'width' => 0.4],
            'series' => ['y' => 0.06, 'size' => 0.02],
            'title' => ['y' => 0.12, 'size' => 0.08],
            'subtitle' => ['y' => 0.44, 'size' => 0.03],
            'author' => ['y' => 0.9, 'size' => 0.032, 'width' => 0.42],
        ],
        'storybook' => [
            'for' => ['childrens', 'fiction'],
            'palette' => 'sunshine', 'pairing' => 'friendly', 'motif' => 'kite', 'align' => 'center',
            'motif_box' => ['x' => 0.22, 'y' => 0.33, 'width' => 0.56],
            'series' => ['y' => 0.03, 'size' => 0.02],
            'title' => ['y' => 0.07, 'size' => 0.072],
            'subtitle' => ['y' => 0.73, 'size' => 0.03],
            'author' => ['y' => 0.86, 'size' => 0.036],
        ],
        'scholar' => [
            'for' => ['academic', 'masters_thesis', 'phd_dissertation', 'educational', 'technical'],
            'palette' => 'slate', 'pairing' => 'scholarly', 'motif' => 'pages', 'align' => 'center',
            'motif_box' => ['x' => 0.43, 'y' => 0.64, 'width' => 0.14],
            'series' => ['y' => 0.08, 'size' => 0.018],
            'title' => ['y' => 0.24, 'size' => 0.054],
            'subtitle' => ['y' => 0.45, 'size' => 0.026],
            'author' => ['y' => 0.84, 'size' => 0.03],
        ],
        'horizon' => [
            'for' => ['fiction', 'memoir', 'poetry'],
            'palette' => 'plum', 'pairing' => 'elegant', 'motif' => 'mountains', 'align' => 'center',
            'motif_box' => ['x' => 0.05, 'y' => 0.42, 'width' => 0.9],
            'author' => ['y' => 0.05, 'size' => 0.03],
            'title' => ['y' => 0.13, 'size' => 0.07],
            'subtitle' => ['y' => 0.33, 'size' => 0.028],
            'series' => ['y' => 0.42, 'size' => 0.018],
        ],
        'sunrise' => [
            'for' => ['self_help', 'devotional', 'nonfiction'],
            'palette' => 'terracotta', 'pairing' => 'modern', 'motif' => 'sun', 'align' => 'center',
            'motif_box' => ['x' => 0.32, 'y' => 0.06, 'width' => 0.36],
            'series' => ['y' => 0.33, 'size' => 0.02],
            'title' => ['y' => 0.39, 'size' => 0.062],
            'subtitle' => ['y' => 0.6, 'size' => 0.03],
            'author' => ['y' => 0.86, 'size' => 0.034],
        ],
        'journey' => [
            'for' => ['memoir', 'biography', 'nonfiction'],
            'palette' => 'sky', 'pairing' => 'elegant', 'motif' => 'path', 'align' => 'center',
            'motif_box' => ['x' => 0.28, 'y' => 0.5, 'width' => 0.44],
            'series' => ['y' => 0.06, 'size' => 0.02],
            'title' => ['y' => 0.12, 'size' => 0.066],
            'subtitle' => ['y' => 0.33, 'size' => 0.028],
            'author' => ['y' => 0.87, 'size' => 0.034],
        ],
        'field' => [
            'for' => ['nonfiction', 'devotional', 'cookbook', 'educational'],
            'palette' => 'forest', 'pairing' => 'classic', 'motif' => 'leaf', 'align' => 'center',
            'motif_box' => ['x' => 0.39, 'y' => 0.62, 'width' => 0.22],
            'series' => ['y' => 0.08, 'size' => 0.02],
            'title' => ['y' => 0.2, 'size' => 0.064],
            'subtitle' => ['y' => 0.41, 'size' => 0.028],
            'author' => ['y' => 0.86, 'size' => 0.034],
        ],
    ];

    /**
     * A motif's drawing: primitives in a 100 × 100 box, each with an
     * opacity. c: circle [cx, cy, r]; p: polygon [x, y, …]; l: polyline
     * [[x, y, …], width]; r: rectangle [x, y, w, h].
     *
     * @return list<array{0: string, 1: array, 2: float}>
     */
    public static function drawing(string $motif): array
    {
        return match ($motif) {
            'book' => [
                ['p', [6, 30, 48, 37, 48, 80, 6, 73], 1.0],
                ['p', [52, 37, 94, 30, 94, 73, 52, 80], 1.0],
                ['l', [[50, 36, 50, 82], 2.5], 0.6],
                ['l', [[13, 44, 41, 48], 1.6], 0.35], ['l', [[13, 52, 41, 56], 1.6], 0.35], ['l', [[13, 60, 35, 63], 1.6], 0.35],
                ['l', [[59, 48, 87, 44], 1.6], 0.35], ['l', [[59, 56, 87, 52], 1.6], 0.35], ['l', [[59, 64, 81, 61], 1.6], 0.35],
            ],
            'pages' => [
                ['p', [24, 20, 70, 14, 78, 80, 32, 86], 0.35],
                ['p', [20, 18, 66, 18, 66, 84, 20, 84], 0.6],
                ['p', [28, 12, 76, 16, 72, 82, 24, 78], 1.0],
                ['l', [[34, 28, 66, 30], 2], 0.35], ['l', [[33, 38, 65, 40], 2], 0.35], ['l', [[32, 48, 64, 50], 2], 0.35], ['l', [[31, 58, 52, 59], 2], 0.35],
            ],
            'path' => [
                ['p', [30, 100, 70, 100, 56, 70, 64, 50, 52, 30, 50, 22, 48, 30, 56, 50, 44, 70], 1.0],
                ['l', [[4, 24, 96, 24], 1.5], 0.5],
                ['c', [78, 14, 6], 0.7],
            ],
            'leaf' => [
                ['p', [50, 6, 66, 18, 76, 38, 74, 60, 62, 78, 50, 86, 38, 78, 26, 60, 24, 38, 34, 18], 1.0],
                ['l', [[50, 14, 50, 98], 2.5], 0.45],
                ['l', [[50, 34, 64, 26], 1.8], 0.4], ['l', [[50, 34, 36, 26], 1.8], 0.4],
                ['l', [[50, 52, 68, 42], 1.8], 0.4], ['l', [[50, 52, 32, 42], 1.8], 0.4],
                ['l', [[50, 70, 64, 62], 1.8], 0.4], ['l', [[50, 70, 36, 62], 1.8], 0.4],
            ],
            'sun' => [['c', [50, 50, 22], 1.0], ...self::rays()],
            'kite' => [
                ['p', [50, 6, 74, 34, 50, 70, 26, 34], 1.0],
                ['l', [[50, 6, 50, 70], 1.5], 0.4], ['l', [[26, 34, 74, 34], 1.5], 0.4],
                ['l', [[50, 70, 44, 80, 54, 88, 46, 96], 1.5], 0.7],
                ['p', [40, 78, 46, 80, 42, 84], 0.8], ['p', [56, 86, 60, 90, 54, 90], 0.8],
            ],
            'mountains' => [
                ['c', [76, 40, 7], 0.8],
                ['p', [0, 100, 34, 52, 58, 82, 72, 66, 100, 100], 1.0],
                ['p', [34, 52, 42, 63, 36, 60, 30, 64, 27, 62], 0.45],
                ['p', [10, 100, 52, 74, 90, 100], 0.55],
            ],
            'shapes' => [
                ['c', [36, 40, 26], 0.9],
                ['r', [46, 46, 40, 40], 0.55],
                ['p', [14, 92, 40, 54, 66, 92], 0.7],
            ],
            default => throw new \InvalidArgumentException("No motif {$motif}."),
        };
    }

    /** The sun's twelve rays. */
    private static function rays(): array
    {
        $rays = [];

        for ($i = 0; $i < 12; $i++) {
            $a = $i * M_PI / 6;
            $b = 0.13;
            $rays[] = ['p', [
                round(50 + 28 * cos($a - $b), 2), round(50 + 28 * sin($a - $b), 2),
                round(50 + 44 * cos($a), 2), round(50 + 44 * sin($a), 2),
                round(50 + 28 * cos($a + $b), 2), round(50 + 28 * sin($a + $b), 2),
            ], 0.85];
        }

        return $rays;
    }

    /** The motif as an SVG, for the picker. */
    public static function svg(string $motif, string $color): string
    {
        $color = self::hexOr($color, '#333333');
        $parts = [];

        foreach (self::drawing($motif) as [$kind, $d, $o]) {
            $parts[] = match ($kind) {
                'c' => sprintf('<circle cx="%s" cy="%s" r="%s" fill="%s" fill-opacity="%s"/>', $d[0], $d[1], $d[2], $color, $o),
                'p' => sprintf('<polygon points="%s" fill="%s" fill-opacity="%s"/>', self::points($d), $color, $o),
                'l' => sprintf('<polyline points="%s" fill="none" stroke="%s" stroke-width="%s" stroke-linecap="round" stroke-linejoin="round" stroke-opacity="%s"/>', self::points($d[0]), $color, $d[1], $o),
                'r' => sprintf('<rect x="%s" y="%s" width="%s" height="%s" fill="%s" fill-opacity="%s"/>', $d[0], $d[1], $d[2], $d[3], $color, $o),
            };
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" aria-hidden="true">'.implode('', $parts).'</svg>';
    }

    /** The motif as a transparent PNG, the same drawing as svg(). */
    public static function png(string $motif, string $color, int $size = self::MOTIF_PIXELS): string
    {
        $big = $size * 2;
        $k = $big / 100;
        $image = imagecreatetruecolor($big, $big);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagealphablending($image, true);
        [$r, $g, $b] = CoverBackground::rgb(self::hexOr($color, '#333333'));

        foreach (self::drawing($motif) as [$kind, $d, $o]) {
            $ink = imagecolorallocatealpha($image, $r, $g, $b, (int) round(127 * (1 - $o)));

            match ($kind) {
                'c' => imagefilledellipse($image, (int) round($d[0] * $k), (int) round($d[1] * $k), (int) round(2 * $d[2] * $k), (int) round(2 * $d[2] * $k), $ink),
                'p' => imagefilledpolygon($image, array_map(fn ($v) => (int) round($v * $k), $d), $ink),
                'r' => imagefilledrectangle($image, (int) round($d[0] * $k), (int) round($d[1] * $k), (int) round(($d[0] + $d[2]) * $k), (int) round(($d[1] + $d[3]) * $k), $ink),
                'l' => self::stroke($image, $d[0], $d[1] * $k, $k, $ink),
            };
        }

        $out = imagecreatetruecolor($size, $size);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopyresampled($out, $image, 0, 0, 0, 0, $size, $size, $big, $big);
        imagedestroy($image);

        ob_start();
        imagepng($out);
        imagedestroy($out);

        return (string) ob_get_clean();
    }

    /** A polyline of a width, as round-capped segments. */
    private static function stroke($image, array $pts, float $width, float $k, int $ink): void
    {
        imagesetthickness($image, max(1, (int) round($width)));

        for ($i = 0; $i + 3 < count($pts); $i += 2) {
            imageline($image, (int) round($pts[$i] * $k), (int) round($pts[$i + 1] * $k), (int) round($pts[$i + 2] * $k), (int) round($pts[$i + 3] * $k), $ink);
        }

        for ($i = 0; $i + 1 < count($pts); $i += 2) {
            imagefilledellipse($image, (int) round($pts[$i] * $k), (int) round($pts[$i + 1] * $k), (int) round($width), (int) round($width), $ink);
        }

        imagesetthickness($image, 1);
    }

    /**
     * The picker's catalogue: each template with its defaults and a
     * thumbnail motif, and the palettes, pairings and motifs to tweak.
     *
     * @return array<string, mixed>
     */
    public static function catalogue(): array
    {
        return [
            'templates' => collect(self::TEMPLATES)->map(fn (array $t, string $key) => [
                'key' => $key,
                'for' => $t['for'],
                'palette' => $t['palette'],
                'pairing' => $t['pairing'],
                'motif' => $t['motif'],
                'align' => $t['align'],
                'boxes' => array_intersect_key($t, array_flip(['motif_box', 'series', 'title', 'subtitle', 'author'])),
            ])->values()->all(),
            'palettes' => collect(self::PALETTES)->map(fn (array $p, string $key) => ['key' => $key] + $p)->values()->all(),
            'pairings' => collect(self::PAIRINGS)->map(fn (array $p, string $key) => [
                'key' => $key,
                'title' => CoverFonts::FAMILIES[$p['title'][0]]['name'],
                'text' => CoverFonts::FAMILIES[$p['text']]['name'],
            ])->values()->all(),
            'motifs' => collect(self::MOTIFS)->map(fn (string $m) => ['key' => $m, 'svg' => self::svg($m, '#334155')])->all(),
        ];
    }

    /**
     * The template's choices with the author's tweaks over them.
     *
     * @param  array{palette?: ?string, pairing?: ?string, motif?: ?string}  $tweaks
     * @return array{template: string, palette: string, pairing: string, motif: ?string}
     */
    public static function choices(string $template, array $tweaks = []): array
    {
        $t = self::TEMPLATES[$template] ?? throw ValidationException::withMessages(['template' => 'There is no such cover template.']);
        $motif = array_key_exists('motif', $tweaks) ? $tweaks['motif'] : $t['motif'];

        return [
            'template' => $template,
            'palette' => isset(self::PALETTES[$tweaks['palette'] ?? '']) ? $tweaks['palette'] : $t['palette'],
            'pairing' => isset(self::PAIRINGS[$tweaks['pairing'] ?? '']) ? $tweaks['pairing'] : $t['pairing'],
            // 'none' leaves the motif out: type alone.
            'motif' => in_array($motif, self::MOTIFS, true) ? $motif : null,
        ];
    }

    /**
     * Apply a template to a book: its cover design replaced, its text kept,
     * the previous design kept for "Restore the previous cover".
     *
     * @param  array{template: string, palette: string, pairing: string, motif: ?string}  $choices
     *
     * @throws ValidationException
     */
    public static function apply(Book $book, array $choices, bool $replaceFinished = false): void
    {
        CoverTemplates::refuseWhileFollowing($book);

        $cover = $book->cover ?? [];
        $uploaded = app(CoverTemplates::class)->uploaded($book);

        if ($uploaded['front'] && ($cover['layout'] ?? null) === 'finished' && ! $replaceFinished) {
            throw ValidationException::withMessages(['cover' => 'This book has an uploaded finished cover. Choose to replace it to use a template; you can restore it afterwards.']);
        }

        $t = self::TEMPLATES[$choices['template']];
        $palette = self::PALETTES[$choices['palette']];

        // What it replaces, once: trying one starter after another keeps the
        // cover from before the first, which "Restore" puts back.
        if (! isset($cover['starter'], $cover['pre_template'])) {
            $cover['pre_template'] = [
                'template' => 'starter:'.$choices['template'],
                'at' => now()->toIso8601String(),
                'cover' => array_intersect_key($cover, array_flip([...CoverTemplates::DESIGN_KEYS, 'artwork', 'artwork_source', 'back_artwork', 'images'])),
                'spine_color' => $book->printSettings()['spine_color'] ?? null,
            ];
        }

        foreach ([...CoverTemplates::DESIGN_KEYS, 'artwork', 'artwork_source'] as $key) {
            unset($cover[$key]);
        }

        $items = [];

        if ($choices['motif']) {
            $id = 'img'.Str::lower(Str::random(10));
            $path = "covers/book-{$book->id}/images/{$id}.png";
            Storage::disk('files')->put($path, self::png($choices['motif'], $palette['accent']));
            $cover['images'] = [...array_values(array_filter(CoverImages::all($book), fn ($i) => ! str_starts_with((string) ($i['name'] ?? ''), 'Motif: '))), [
                'id' => $id, 'path' => $path, 'width' => self::MOTIF_PIXELS, 'height' => self::MOTIF_PIXELS, 'type' => 'png', 'name' => 'Motif: '.$choices['motif'],
            ]];
            $box = $t['motif_box'];
            $items[] = CoverLayers::makeImage($id, [
                'id' => 'motif',
                'x' => $box['x'], 'y' => $box['y'], 'width' => $box['width'],
                'height' => round($box['width'] * CoverRenderer::WIDTH / CoverRenderer::HEIGHT, 4),
            ]);
        }

        $pair = self::PAIRINGS[$choices['pairing']];
        $x = $t['align'] === 'left' ? 0.1 : 0.12;
        $width = $t['align'] === 'left' ? 0.8 : 0.76;
        $text = fn (string $source, array $box, array $style) => CoverLayers::make($source, array_merge([
            'id' => $source,
            'x' => $x, 'y' => $box['y'], 'width' => $box['width'] ?? $width,
            'align' => $t['align'], 'size' => $box['size'], 'color' => $palette['ink'],
        ], $style));

        [$titleFace, $titleWeight, $upper] = $pair['title'];
        $textBold = CoverFonts::has($pair['text'], 'semibold', false) ? 'semibold' : 'regular';

        $items[] = $text('series', $t['series'], ['font' => $pair['text'], 'weight' => 'regular', 'uppercase' => true, 'tracking' => 0.12]);
        $items[] = $text('title', $t['title'], ['font' => $titleFace, 'weight' => $titleWeight, 'uppercase' => $upper, 'leading' => 1.08, 'tracking' => $upper ? 0.03 : 0.0]);
        $items[] = $text('subtitle', $t['subtitle'], ['font' => $pair['text'], 'weight' => 'regular', 'italic' => $pair['italic'] && CoverFonts::has($pair['text'], 'regular', true)]);
        $items[] = $text('author', $t['author'], ['font' => $pair['text'], 'weight' => $textBold, 'tracking' => 0.04]);

        $cover['layers'] = CoverLayers::validate(['version' => CoverLayers::VERSION, 'items' => $items], 'front', array_column($cover['images'] ?? CoverImages::all($book), 'id'));
        $cover['backgrounds'] = ['wrap' => CoverBackground::validate([
            'kind' => 'linear', 'angle' => 160,
            'stops' => [['color' => $palette['from'], 'at' => 0], ['color' => $palette['to'], 'at' => 1]],
        ], 'wrap')];
        $cover['font'] = in_array(CoverFonts::FAMILIES[$titleFace]['category'], ['sans', 'display'], true) ? 'sans' : 'serif';
        $cover['layout'] = 'classic';
        $cover['starter'] = $choices + ['applied_at' => now()->toIso8601String()];

        $print = $book->print ?? [];
        unset($print['spine_color']);

        $book->forceFill(['cover' => $cover, 'print' => $print])->save();
    }

    private static function points(array $d): string
    {
        return implode(' ', array_map(fn ($pair) => implode(',', $pair), array_chunk($d, 2)));
    }

    private static function hexOr(string $color, string $fallback): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : $fallback;
    }
}
