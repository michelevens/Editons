<?php

namespace App\Publishing;

use App\Covers\CoverFonts;
use App\Covers\CoverImages;
use App\Covers\CoverLayers;
use App\Covers\CoverText;
use App\Models\Book;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The front cover, as an image a store will take.
 *
 * Cover Studio used to draw the cover in the browser and nowhere else, so
 * there was no file: nothing to download, nothing for the EPUB, nothing to
 * upload to a store. This draws it on the server at 1600 × 2560 — the size
 * the stores ask for — from the same settings, in the same bundled faces the
 * PDF uses, and that one image is the preview, the thumbnail, the download
 * and the EPUB cover. What the author sees is what ships.
 *
 * Rendered once per distinct design: the file is named for a hash of the
 * settings, so a change makes a new file and an unchanged cover is served
 * from the bucket.
 *
 * Two ways to set the type. A layout (classic, bold, band, finished) places
 * and fits it automatically. Text layers (App\Covers\CoverLayers) put each
 * box exactly where the author dragged it, set by CoverText — the same
 * algorithm Cover Studio's live canvas runs — so the canvas is the file.
 */
class CoverRenderer
{
    public const WIDTH = 1600;

    public const HEIGHT = 2560;

    /** The shelf and sidebar thumbnail; see ensureThumb(). */
    public const THUMB_WIDTH = 320;

    /** Cover layouts, in the order Cover Studio offers them. */
    public const LAYOUTS = [
        'classic' => 'Classic',
        'bold' => 'Bold',
        'band' => 'Band',
        'finished' => 'Finished cover',
    ];

    /**
     * Bumped when the drawing changes, so every cover is redrawn once.
     * 2: text layers, and the background-only image Cover Studio draws on.
     */
    private const VERSION = 2;

    private const MARGIN = 150;

    /** Decoding is 4 bytes a pixel: 40 MP is 160 MB, and the cap. */
    public const MAX_PIXELS = 40_000_000;

    /** The design a book's cover is drawn from, with defaults applied. */
    public function settings(Book $book): array
    {
        $cover = $book->cover ?? [];

        return [
            'title' => trim((string) ($cover['title'] ?? $book->title)),
            'subtitle' => trim((string) ($cover['subtitle'] ?? $book->subtitle)),
            'author' => trim((string) ($cover['author'] ?? $book->author_name)),
            'font' => ($cover['font'] ?? 'serif') === 'sans' ? 'sans' : 'serif',
            'palette' => (int) ($cover['palette'] ?? 0),
            'layout' => array_key_exists($cover['layout'] ?? '', self::LAYOUTS) ? $cover['layout'] : 'classic',
            'shade' => max(0, min(100, (int) ($cover['shade'] ?? 45))),
            'artwork' => $cover['artwork'] ?? null,
            // Text layers, when the author has customised the text; null
            // keeps the layout. A stored document that no longer validates
            // (a face withdrawn) falls back to the layout, not to an error.
            'layers' => $this->layers($cover),
            // What bound layers show; only layered covers depend on them.
            'texts' => isset($cover['layers']) ? CoverLayers::texts($book) : null,
        ] + $this->extras($book, $cover);
    }

    /**
     * Settings a cover only has once the author uses them, so a cover that
     * does not is drawn, and hashed, exactly as before: the artwork's
     * framing, and the pictures its image layers place.
     *
     * @return array<string, mixed>
     */
    private function extras(Book $book, array $cover): array
    {
        $extras = [];

        if ($framing = self::framing($cover['framing'] ?? null)) {
            $extras['framing'] = $framing;
        }

        $layers = $this->layers($cover);
        $wanted = $layers ? array_column(array_filter($layers['items'], fn ($l) => $l['source'] === 'image'), 'image') : [];

        if ($wanted !== []) {
            $extras['images'] = [];

            foreach (CoverImages::all($book) as $image) {
                if (in_array($image['id'], $wanted, true)) {
                    $extras['images'][$image['id']] = $image;
                }
            }
        }

        return $extras;
    }

    /**
     * Artwork framing, checked: the focal point (fractions of the picture)
     * that stays in view, and a zoom from 1 (the picture just fills the
     * frame) to 4. Null for anything else, or for the default, which is the
     * centre crop covers have always had.
     *
     * @return array{x: float, y: float, zoom: float}|null
     */
    public static function framing(mixed $framing): ?array
    {
        if (! is_array($framing) || ! isset($framing['x'], $framing['y'], $framing['zoom'])
            || ! is_numeric($framing['x']) || ! is_numeric($framing['y']) || ! is_numeric($framing['zoom'])) {
            return null;
        }

        $f = [
            'x' => max(0.0, min(1.0, (float) $framing['x'])),
            'y' => max(0.0, min(1.0, (float) $framing['y'])),
            'zoom' => max(1.0, min(4.0, (float) $framing['zoom'])),
        ];

        return $f['x'] === 0.5 && $f['y'] === 0.5 && $f['zoom'] === 1.0 ? null : $f;
    }

    /** @return array{version: int, items: array<int, array<string, mixed>>}|null */
    private function layers(array $cover): ?array
    {
        if (! isset($cover['layers'])) {
            return null;
        }

        try {
            return CoverLayers::validate($cover['layers']);
        } catch (ValidationException) {
            return null;
        }
    }

    /** A short hash of the design, for the file name and the URL. */
    public function version(Book $book): string
    {
        return substr(md5(self::VERSION.json_encode($this->settings($book))), 0, 12);
    }

    public function path(Book $book): string
    {
        return "covers/book-{$book->id}/front-{$this->version($book)}.jpg";
    }

    /**
     * The stored cover image, drawn first if this design has not been.
     *
     * Earlier renders of the book's cover are removed once the new one is
     * stored, so the bucket holds one front cover per book.
     */
    public function ensure(Book $book): string
    {
        $disk = Storage::disk('files');
        $path = $this->path($book);

        if ($disk->exists($path)) {
            return $path;
        }

        $settings = $this->settings($book);
        $artwork = $settings['artwork'] && $disk->exists($settings['artwork'])
            ? $disk->get($settings['artwork'])
            : null;

        $disk->put($path, $this->render($settings, $artwork));

        foreach ($disk->files("covers/book-{$book->id}") as $file) {
            if ($file !== $path && str_starts_with(basename($file), 'front-')) {
                $disk->delete($file);
            }
        }

        return $path;
    }

    /**
     * The front cover as JPEG bytes for a print wrap, $width pixels wide.
     *
     * A layered cover is drawn at that width, its type set at print
     * resolution; a layout cover is the stored 1600 × 2560 file, which the
     * wrap scales as before.
     */
    public function front(Book $book, int $width): string
    {
        $disk = Storage::disk('files');
        $settings = $this->settings($book);

        if ($settings['layers'] === null || $width <= self::WIDTH) {
            return (string) $disk->get($this->ensure($book));
        }

        $artwork = $settings['artwork'] && $disk->exists($settings['artwork']) ? $disk->get($settings['artwork']) : null;

        return $this->render($settings, $artwork, $width);
    }

    public function thumbPath(Book $book): string
    {
        return "covers/book-{$book->id}/thumb-{$this->version($book)}.jpg";
    }

    /**
     * The shelf-sized cover: the same image, scaled down once and stored.
     *
     * The library draws covers 104px wide and the sidebar 46px, but every
     * one of them was the full 1600 × 2560 file: 12–15 MB of JPEG for one
     * visit to the shelf. 320px wide covers both at twice their CSS size.
     * Named by the same design hash as the full cover, so it changes when
     * the cover does; older thumbnails are removed like older covers.
     */
    public function ensureThumb(Book $book): string
    {
        $disk = Storage::disk('files');
        $path = $this->thumbPath($book);

        if ($disk->exists($path)) {
            return $path;
        }

        $disk->put($path, $this->scale($disk->get($this->ensure($book)), self::THUMB_WIDTH));

        foreach ($disk->files("covers/book-{$book->id}") as $file) {
            if ($file !== $path && str_starts_with(basename($file), 'thumb-')) {
                $disk->delete($file);
            }
        }

        return $path;
    }

    /** JPEG bytes scaled to a width, keeping the proportions. */
    public function scale(string $jpeg, int $width): string
    {
        if (! function_exists('imagecreatefromstring')) {
            throw new RuntimeException('Cover rendering needs the GD extension.');
        }

        $source = imagecreatefromstring($jpeg);

        if (! $source) {
            throw new RuntimeException('The cover could not be read to scale it.');
        }

        $height = (int) round(imagesy($source) * $width / imagesx($source));
        $small = imagescale($source, $width, $height, IMG_BICUBIC);

        ob_start();
        imagejpeg($small, null, 82);

        return (string) ob_get_clean();
    }

    /**
     * Draw a cover and return it as JPEG bytes.
     *
     * $width draws a layered cover at another size (CoverWrap's print
     * resolution): layers are fractions of the cover, so they scale; a
     * layout is drawn at 1600 × 2560 and scaled. $withText false draws the
     * background alone, which Cover Studio's live canvas sets type over.
     */
    public function render(array $settings, ?string $artwork = null, ?int $width = null, bool $withText = true): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('Cover rendering needs the GD extension.');
        }

        // The canvas is 16 MB and decoded artwork can be several times that;
        // PHP's default 128 MB is not enough headroom for both.
        $this->headroom('512M');

        if ($artwork !== null && ($size = @getimagesizefromstring($artwork)) && $size[0] * $size[1] > self::MAX_PIXELS) {
            throw new RuntimeException('That artwork is too large to use. Use an image under 40 megapixels.');
        }

        $layers = $settings['layers'] ?? null;

        if ($layers === null && $withText && $width !== null && $width !== self::WIDTH) {
            return $this->scale($this->render($settings, $artwork), $width);
        }

        $width = $layers !== null ? ($width ?? self::WIDTH) : self::WIDTH;
        $height = (int) round($width * self::HEIGHT / self::WIDTH);

        $palette = AccessibilityAudit::PALETTES[$settings['palette']] ?? AccessibilityAudit::PALETTES[0];
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, true);

        $art = $artwork !== null ? @imagecreatefromstring($artwork) : false;

        if ($art) {
            $this->cover($image, $art, $settings['framing'] ?? null);
        } else {
            $this->gradient($image, $palette['from'], $palette['to']);
        }

        $onArt = (bool) $art;
        $ink = $onArt ? '#ffffff' : $palette['ink'];

        if ($layers !== null || ! $withText) {
            // Layers keep the layout's treatment of the picture: classic
            // and bold darken it under the type; band carries its band as
            // a layer of its own, and a finished cover is left alone.
            if ($onArt && in_array($settings['layout'], ['classic', 'bold'], true)) {
                $this->shade($image, $settings['shade'], top: true, bottom: true);
            }

            if ($withText && $layers !== null) {
                $this->drawItems($image, $layers['items'], $settings['texts'] ?? [], $settings['images'] ?? [], ['width' => $width, 'height' => $height]);
            }
        } else {
            // A finished cover from a designer already carries its type: it
            // is the cover, untouched. Without artwork there is nothing
            // finished, so the classic layout draws instead.
            match ($art ? $settings['layout'] : ($settings['layout'] === 'finished' ? 'classic' : $settings['layout'])) {
                'finished' => null,
                'bold' => $this->bold($image, $settings, $ink, $onArt),
                'band' => $this->band($image, $settings, $palette, $onArt),
                default => $this->classic($image, $settings, $ink, $onArt),
            };
        }

        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }

    // --- Text layers -------------------------------------------------------

    /**
     * The drop shadow under a layer's type: offset right and down, as
     * fractions of the cover's width and height, and its opacity. Cover
     * Studio draws the same (the payload hands it these).
     */
    public const SHADOWS = [
        'soft' => ['dx' => 0.0025, 'dy' => 0.002, 'opacity' => 0.37],
        'strong' => ['dx' => 0.004, 'dy' => 0.003, 'opacity' => 0.75],
    ];

    /**
     * Where a layer sits on a surface, in the surface's pixels.
     *
     * A surface is a panel's model: `width` × `height` pixels, and for the
     * spine `spine` true, when it is the reading frame (its width the
     * spine's length, its height the spine's thickness; CoverText::spine).
     *
     * @param  array<string, mixed>  $layer
     * @param  array{width: float, height: float, spine?: bool}  $surface
     * @param  array<string, string>  $texts
     * @return array<string, mixed> CoverText's layout; an image layer's has only its box
     */
    public static function place(array $layer, array $surface, array $texts = []): array
    {
        $spine = $surface['spine'] ?? false;
        [$w, $h] = [$surface['width'], $surface['height']];

        if ($layer['source'] === 'image') {
            $height = $layer['height'] * ($spine ? $w : $h);
            $top = $spine ? $h / 2 + $layer['y'] * $w - $height / 2 : $layer['y'] * $h;

            return ['box' => ['x' => $layer['x'] * $w, 'y' => $top, 'width' => $layer['width'] * $w, 'height' => $height], 'lines' => []];
        }

        $text = CoverLayers::text($layer, $texts);

        return $spine ? CoverText::spine($layer, $text, $w, $h) : CoverText::layout($layer, $text, $w, $h);
    }

    /**
     * Each visible layer in order, the first at the back: an image layer's
     * picture, or a text layer's background panel, its shadow, then its
     * type, every glyph where CoverText put it.
     *
     * Laid out on the surface's model and drawn at $scale from ($ox, $oy)
     * on $image: the print wrap sets its back and spine at 300 dpi and draws
     * a preview from the same layout scaled, so a line never breaks
     * differently on screen and on paper.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, string>  $texts
     * @param  array<string, array<string, mixed>>  $images  by id
     * @param  array{width: float, height: float, spine?: bool}  $surface
     */
    public function drawItems($image, array $items, array $texts, array $images, array $surface, float $scale = 1.0, float $ox = 0.0, float $oy = 0.0): void
    {
        $width = $surface['width'];
        // Shadows and panels are fractions of the cover; on the spine, of
        // its length.
        $height = ($surface['spine'] ?? false) ? $surface['width'] : $surface['height'];
        $points = 1 / $this->pixelsPerPoint();

        foreach ($items as $layer) {
            if (! $layer['visible']) {
                continue;
            }

            $set = self::place($layer, $surface, $texts);

            if ($layer['source'] === 'image') {
                if (isset($images[$layer['image']]) && ($picture = CoverImages::load($images[$layer['image']]['path']))) {
                    $box = $set['box'];
                    $this->picture($image, $picture, $layer, $ox + $box['x'] * $scale, $oy + $box['y'] * $scale, $box['width'] * $scale, $box['height'] * $scale);
                }

                continue;
            }

            if ($panel = $layer['background']) {
                $box = $set['box'];
                $pad = $panel['padding'] * $width;

                imagefilledrectangle(
                    $image,
                    (int) round($ox + ($box['x'] - $pad) * $scale),
                    (int) round($oy + ($box['y'] - $pad) * $scale),
                    (int) round($ox + ($box['x'] + $box['width'] + $pad) * $scale) - 1,
                    (int) round($oy + ($box['y'] + $box['height'] + $pad) * $scale) - 1,
                    $this->color($image, $panel['color'], $this->alpha($panel['opacity'])),
                );
            }

            $size = $set['size'] * $scale * $points;

            if ($shadow = self::SHADOWS[$layer['shadow']] ?? null) {
                $dark = $this->color($image, '#000000', $this->alpha($shadow['opacity'] * $layer['opacity']));
                $this->glyphs($image, $set['lines'], $size, $dark, $shadow['dx'] * $width, $shadow['dy'] * $height, $scale, $ox, $oy);
            }

            $this->glyphs($image, $set['lines'], $size, $this->color($image, $layer['color'], $this->alpha($layer['opacity'])), 0, 0, $scale, $ox, $oy);
        }
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    private function glyphs($image, array $lines, float $points, int $color, float $dx, float $dy, float $scale, float $ox, float $oy): void
    {
        foreach ($lines as $line) {
            $y = (int) round($oy + ($line['baseline'] + $dy) * $scale);

            foreach ($line['glyphs'] as $glyph) {
                if (trim($glyph['char']) === '') {
                    continue;
                }

                imagettftext($image, $points, 0, (int) round($ox + ($glyph['x'] + $dx) * $scale), $y, $color, CoverFonts::file($glyph['face']), $glyph['char']);
            }
        }
    }

    /**
     * A picture into a box: its crop (fractions of the picture, or all of
     * it) resampled to the box, its own transparency kept, at the layer's
     * opacity. The box is the layer's: "keep aspect" is the editor sizing
     * the box to the crop, so nothing here distorts what was not asked for.
     *
     * @param  array<string, mixed>  $layer
     */
    public function picture($image, $picture, array $layer, float $x, float $y, float $w, float $h): void
    {
        $opacity = max(0.0, min(1.0, (float) $layer['opacity']));

        if ($opacity <= 0) {
            return;
        }

        $crop = $layer['crop'] ?? ['x' => 0.0, 'y' => 0.0, 'width' => 1.0, 'height' => 1.0];
        [$pw, $ph] = [imagesx($picture), imagesy($picture)];
        $sx = (int) round($crop['x'] * $pw);
        $sy = (int) round($crop['y'] * $ph);
        $sw = max(1, (int) round($crop['width'] * $pw));
        $sh = max(1, (int) round($crop['height'] * $ph));
        $dx = (int) round($x);
        $dy = (int) round($y);
        $dw = max(1, (int) round($x + $w) - $dx);
        $dh = max(1, (int) round($y + $h) - $dy);

        $tile = imagecreatetruecolor($dw, $dh);
        imagealphablending($tile, false);
        imagesavealpha($tile, true);
        imagefill($tile, 0, 0, imagecolorallocatealpha($tile, 0, 0, 0, 127));
        imagecopyresampled($tile, $picture, 0, 0, $sx, $sy, $dw, $dh, $sw, $sh);

        if ($opacity < 1) {
            // GD has no "draw at 50%" that keeps a PNG's own transparency,
            // so each pixel's alpha is scaled, as the browser composites.
            for ($py = 0; $py < $dh; $py++) {
                for ($px = 0; $px < $dw; $px++) {
                    $c = imagecolorat($tile, $px, $py);
                    $alpha = (int) round(127 - (127 - (($c >> 24) & 0x7F)) * $opacity);
                    imagesetpixel($tile, $px, $py, ($c & 0xFFFFFF) | ($alpha << 24));
                }
            }
        }

        imagealphablending($image, true);
        imagecopy($image, $tile, $dx, $dy, 0, 0, $dw, $dh);
        imagedestroy($tile);
    }

    /** GD's 0 (opaque) – 127 (clear) from an opacity 0–1. */
    private function alpha(float $opacity): int
    {
        return (int) round(127 * (1 - max(0, min(1, $opacity))));
    }

    /**
     * Pixels per point of GD's type size.
     *
     * libgd sets type at 96 dpi, so a 72pt em is 96 pixels; layers are
     * sized in pixels. Measured once from a face's own advances rather than
     * assumed, in case a build of GD sets another resolution.
     */
    private function pixelsPerPoint(): float
    {
        static $ratio = null;

        if ($ratio !== null) {
            return $ratio;
        }

        $face = 'eb-garamond.regular';
        $file = CoverFonts::file($face);
        $metrics = CoverFonts::metrics($face);
        $eleven = imagettfbbox(100, 0, $file, str_repeat('H', 11));
        $one = imagettfbbox(100, 0, $file, 'H');
        $measured = (($eleven[2] - $one[2]) / 10) / ($metrics['adv'][ord('H')] / $metrics['upm'] * 100);

        return $ratio = abs($measured - 4 / 3) < 0.03 ? 4 / 3 : $measured;
    }

    /**
     * The current layout as layers: the same text, where the layout puts
     * it, at the size it fitted, in the same face and colour. "Customise
     * text" starts the author from what they see.
     *
     * A finished cover's artwork already carries its type, so it starts
     * with none.
     *
     * @return array{version: int, items: array<int, array<string, mixed>>}
     */
    public function layersFromLayout(Book $book): array
    {
        $book = $book->replicate();
        $book->cover = array_diff_key($book->cover ?? [], ['layers' => true]);
        $s = $this->settings($book);
        $disk = Storage::disk('files');
        $onArt = $s['artwork'] && $disk->exists($s['artwork']) && @imagecreatefromstring((string) $disk->get($s['artwork']));
        $palette = AccessibilityAudit::PALETTES[$s['palette']] ?? AccessibilityAudit::PALETTES[0];
        $layout = $onArt ? $s['layout'] : ($s['layout'] === 'finished' ? 'classic' : $s['layout']);

        if ($layout === 'finished') {
            return ['version' => CoverLayers::VERSION, 'items' => []];
        }

        $family = $s['font'] === 'sans' ? 'source-sans' : 'eb-garamond';
        $ink = $onArt ? '#ffffff' : $palette['ink'];
        $shadow = $onArt && $layout !== 'band' ? 'soft' : 'none';
        $r = $this->pixelsPerPoint();
        $width = self::WIDTH - 2 * self::MARGIN;
        $items = [];

        // A layer from a layout block: its first baseline in pixels, its
        // size and spacing in GD points (the layouts' units).
        $block = function (string $source, string $weight, bool $italic, float $points, int $baseline, float $leading, string $align, int $boxWidth, string $text, bool $uppercase = false, float $tracking = 0) use ($family, $ink, $shadow, $r) {
            $px = $points * $r;
            $face = CoverFonts::metrics(CoverFonts::faceKey($family, $weight, $italic));
            $layer = CoverLayers::make($source, [
                'id' => $source,
                'x' => $align === 'left' ? self::MARGIN / self::WIDTH : (self::WIDTH - $boxWidth) / 2 / self::WIDTH,
                'y' => max(0, ($baseline - $face['asc'] / $face['upm'] * $px) / self::HEIGHT),
                'width' => $boxWidth / self::WIDTH,
                'align' => $align,
                'font' => $family, 'weight' => $weight, 'italic' => $italic,
                'size' => $px / self::HEIGHT,
                'color' => $ink,
                'uppercase' => $uppercase,
                // The layouts space letters and lines in points.
                'tracking' => $tracking / $r,
                'leading' => $leading / $r,
                'shadow' => $shadow,
            ]);

            // No kerning in layers, so a line the layout just fitted can
            // measure a hair wider: widen the box rather than break it.
            $widest = 0.0;

            foreach (CoverText::layout(array_merge($layer, ['x' => 0.0, 'width' => 1.0]), $text, self::WIDTH, self::HEIGHT)['lines'] as $line) {
                $widest = max($widest, $line['width']);
            }

            if ($widest > $boxWidth) {
                $grown = min(self::WIDTH - ($align === 'left' ? self::MARGIN : 0), $widest + 2);
                $layer['width'] = $grown / self::WIDTH;
                $layer['x'] = $align === 'left' ? $layer['x'] : (self::WIDTH - $grown) / 2 / self::WIDTH;
            }

            return $layer;
        };

        $last = fn (int $first, int $count, float $points, float $leading) => $first + ($count - 1) * (int) round($points * $leading);
        $titleWeight = $family === 'source-sans' ? 'semibold' : 'regular';
        $title = $s['title'] ?: 'Untitled';

        if ($layout === 'band') {
            $titleFace = $this->face($s['font'], $s['font'] === 'sans' ? 'bold' : 'regular');
            $italic = $this->face($s['font'], 'italic');
            [$size, $lines] = $this->fit($title, $titleFace, 170, 3, $width);
            [$subSize, $subLines] = $s['subtitle'] !== '' ? $this->fit($s['subtitle'], $italic, 62, 2, $width) : [0, []];

            $authorBaseline = self::HEIGHT - 170;
            $cursor = $s['author'] !== '' ? $authorBaseline - 64 - 90 : self::HEIGHT - 170;
            $subFirst = null;

            if ($subLines !== []) {
                $subFirst = $cursor - (int) round((count($subLines) - 1) * $subSize * 1.3);
                $cursor = $subFirst - (int) round($subSize * 0.95) - 40;
            }

            $titleFirst = $cursor - (int) round($size * 0.28) - (int) round((count($lines) - 1) * $size * 1.08);
            $top = min((int) (self::HEIGHT * 0.58), $titleFirst - (int) round($size * 0.8) - 110);

            $items[] = CoverLayers::make('custom', [
                'id' => 'band', 'text' => '', 'x' => 0, 'y' => $top / self::HEIGHT, 'width' => 1,
                'height' => (self::HEIGHT - $top) / self::HEIGHT, 'color' => $ink,
                'background' => ['color' => $onArt ? '#141414' : $palette['from'], 'opacity' => $onArt ? 1 - 8 / 127 : 1, 'padding' => 0],
            ]);
            $items[] = $block('title', $titleWeight, false, $size, $titleFirst, 1.08, 'center', $width, $title);

            if ($subFirst !== null) {
                $items[] = $block('subtitle', 'regular', true, $subSize, $subFirst, 1.3, 'center', $width, $s['subtitle']);
            }
        } elseif ($layout === 'bold') {
            [$size, $lines] = $this->fit(mb_strtoupper($title), $this->face($s['font'], 'bold'), 230, 5, $width);
            $first = 300 + (int) ($size * 0.8);
            $items[] = $block('title', 'semibold', false, $size, $first, 1.0, 'left', $width, $title, true);

            if ($s['subtitle'] !== '') {
                [$subSize] = $this->fit($s['subtitle'], $this->face($s['font'], 'regular'), 72, 3, $width);
                $items[] = $block('subtitle', 'regular', false, $subSize, $this->below($last($first, count($lines), $size, 1.0), $size, $subSize, 0.6), 1.3, 'left', $width, $s['subtitle']);
            }
        } else {
            [$size, $lines] = $this->fit($title, $this->face($s['font'], $s['font'] === 'sans' ? 'bold' : 'regular'), 210, 4, $width);
            $items[] = $block('title', $titleWeight, false, $size, 560, 1.08, 'center', $width, $title);
            $y = $last(560, count($lines), $size, 1.08);
            $lastSize = $size;

            if ($s['subtitle'] !== '') {
                [$subSize, $subLines] = $this->fit($s['subtitle'], $this->face($s['font'], 'italic'), 70, 3, (int) ($width * 0.9));
                $subFirst = $this->below($y, $size, $subSize, 0.55);
                $items[] = $block('subtitle', 'regular', true, $subSize, $subFirst, 1.3, 'center', (int) ($width * 0.9), $s['subtitle']);
                $y = $last($subFirst, count($subLines), $subSize, 1.3);
                $lastSize = $subSize;
            }

            $ruleY = $y + (int) ($lastSize * 0.45) + 60;
            $items[] = CoverLayers::make('custom', [
                'id' => 'rule', 'text' => '', 'x' => (self::WIDTH / 2 - 90) / self::WIDTH, 'y' => $ruleY / self::HEIGHT,
                'width' => 181 / self::WIDTH, 'height' => 5 / self::HEIGHT, 'color' => $ink,
                'background' => ['color' => $ink, 'opacity' => 1 - 40 / 127, 'padding' => 0],
            ]);
        }

        if ($s['author'] !== '') {
            [$authorSize] = $this->fit(mb_strtoupper($s['author']), $this->face($s['font'], $s['font'] === 'sans' ? 'bold' : 'regular'), 64, 1, $width, 0.2);
            $baseline = match ($layout) {
                'bold' => self::HEIGHT - 200,
                'band' => self::HEIGHT - 170,
                default => self::HEIGHT - 250,
            };
            $items[] = $block('author', $titleWeight, false, $authorSize, $baseline, 1.0, $layout === 'bold' ? 'left' : 'center', $width, $s['author'], true, 0.2);
        }

        // Through the validator, so a conversion can never store what an
        // edit would be refused.
        return CoverLayers::validate(['version' => CoverLayers::VERSION, 'items' => $items]);
    }

    /** The background alone, for Cover Studio's live canvas. */
    public function backgroundPath(Book $book): string
    {
        $s = $this->settings($book);
        $hash = substr(md5(self::VERSION.'|bg|'.json_encode([$s['palette'], $s['layout'], $s['shade'], $s['artwork']])
            .(isset($s['framing']) ? json_encode($s['framing']) : '')), 0, 12);

        return "covers/book-{$book->id}/background-{$hash}.jpg";
    }

    public function ensureBackground(Book $book): string
    {
        $disk = Storage::disk('files');
        $path = $this->backgroundPath($book);

        if ($disk->exists($path)) {
            return $path;
        }

        $settings = $this->settings($book);
        $artwork = $settings['artwork'] && $disk->exists($settings['artwork']) ? $disk->get($settings['artwork']) : null;

        // The live canvas is at most a few hundred pixels wide; half the
        // full size is plenty and loads at once.
        $disk->put($path, $this->scale($this->render($settings, $artwork, withText: false), 800));

        foreach ($disk->files("covers/book-{$book->id}") as $file) {
            if ($file !== $path && str_starts_with(basename($file), 'background-')) {
                $disk->delete($file);
            }
        }

        return $path;
    }

    // --- Layouts ----------------------------------------------------------

    /** Centred title in the upper third, a rule, the author at the foot. */
    private function classic($image, array $s, string $ink, bool $onArt): void
    {
        if ($onArt) {
            $this->shade($image, $s['shade'], top: true, bottom: true);
        }

        $width = self::WIDTH - 2 * self::MARGIN;
        $title = $this->face($s['font'], $s['font'] === 'sans' ? 'bold' : 'regular');

        [$size, $lines] = $this->fit($s['title'] ?: 'Untitled', $title, 210, 4, $width);
        $y = 560;
        $y = $this->lines($image, $lines, $title, $size, $ink, $y, 'center', 1.08, 0, $onArt);

        if ($s['subtitle'] !== '') {
            $italic = $this->face($s['font'], 'italic');
            [$subSize, $subLines] = $this->fit($s['subtitle'], $italic, 70, 3, (int) ($width * 0.9));
            $y = $this->lines($image, $subLines, $italic, $subSize, $ink, $this->below($y, $size, $subSize, 0.55), 'center', 1.3, 0, $onArt);
            $last = $subSize;
        }

        $rule = $this->color($image, $ink, 40);
        $ruleY = $y + (int) (($last ?? $size) * 0.45) + 60;
        imagefilledrectangle($image, (int) (self::WIDTH / 2 - 90), $ruleY, (int) (self::WIDTH / 2 + 90), $ruleY + 4, $rule);

        if ($s['author'] !== '') {
            $this->author($image, $s, $ink, self::HEIGHT - 250, 'center', $onArt);
        }
    }

    /** Large capitals at the top left, the author at the bottom left. */
    private function bold($image, array $s, string $ink, bool $onArt): void
    {
        if ($onArt) {
            $this->shade($image, $s['shade'], top: true, bottom: true);
        }

        $width = self::WIDTH - 2 * self::MARGIN;
        $title = $this->face($s['font'], 'bold');

        [$size, $lines] = $this->fit(mb_strtoupper($s['title'] ?: 'Untitled'), $title, 230, 5, $width);
        $y = $this->lines($image, $lines, $title, $size, $ink, 300 + (int) ($size * 0.8), 'left', 1.0, 0, $onArt);

        if ($s['subtitle'] !== '') {
            $regular = $this->face($s['font'], 'regular');
            [$subSize, $subLines] = $this->fit($s['subtitle'], $regular, 72, 3, $width);
            $this->lines($image, $subLines, $regular, $subSize, $ink, $this->below($y, $size, $subSize, 0.6), 'left', 1.3, 0, $onArt);
        }

        if ($s['author'] !== '') {
            $this->author($image, $s, $ink, self::HEIGHT - 200, 'left', $onArt);
        }
    }

    /**
     * Artwork or colour above, a solid band below holding the type.
     *
     * Laid out from the foot up, so the band is as tall as its type needs:
     * a fixed band put a long subtitle on top of the author's name.
     */
    private function band($image, array $s, array $palette, bool $onArt): void
    {
        // Over artwork the band is near-black; over a palette it is the
        // palette's deep end, with its own ink, which the palettes are
        // chosen to pass contrast with.
        $fill = $onArt ? '#141414' : $palette['from'];
        $ink = $onArt ? '#ffffff' : $palette['ink'];

        $width = self::WIDTH - 2 * self::MARGIN;
        $titleFace = $this->face($s['font'], $s['font'] === 'sans' ? 'bold' : 'regular');
        $italic = $this->face($s['font'], 'italic');

        [$size, $lines] = $this->fit($s['title'] ?: 'Untitled', $titleFace, 170, 3, $width);
        [$subSize, $subLines] = $s['subtitle'] !== ''
            ? $this->fit($s['subtitle'], $italic, 62, 2, $width)
            : [0, []];

        $authorBaseline = self::HEIGHT - 170;
        $cursor = $s['author'] !== '' ? $authorBaseline - 64 - 90 : self::HEIGHT - 170;

        $subFirst = null;

        if ($subLines !== []) {
            $subFirst = $cursor - (int) round((count($subLines) - 1) * $subSize * 1.3);
            $cursor = $subFirst - (int) round($subSize * 0.95) - 40;
        }

        $titleLast = $cursor - (int) round($size * 0.28);
        $titleFirst = $titleLast - (int) round((count($lines) - 1) * $size * 1.08);
        $top = min((int) (self::HEIGHT * 0.58), $titleFirst - (int) round($size * 0.8) - 110);

        imagefilledrectangle($image, 0, $top, self::WIDTH, self::HEIGHT, $this->color($image, $fill, $onArt ? 8 : 0));

        $this->lines($image, $lines, $titleFace, $size, $ink, $titleFirst, 'center', 1.08, 0, false);

        if ($subFirst !== null) {
            $this->lines($image, $subLines, $italic, $subSize, $ink, $subFirst, 'center', 1.3, 0, false);
        }

        if ($s['author'] !== '') {
            $this->author($image, $s, $ink, $authorBaseline, 'center', false);
        }
    }

    // --- Drawing ----------------------------------------------------------

    /** The author's name in spaced capitals, on one line, shrunk to fit. */
    private function author($image, array $s, string $ink, int $baseline, string $align, bool $shadow): void
    {
        $face = $this->face($s['font'], $s['font'] === 'sans' ? 'bold' : 'regular');
        [$size, $lines] = $this->fit(mb_strtoupper($s['author']), $face, 64, 1, self::WIDTH - 2 * self::MARGIN, 0.2);
        $this->lines($image, $lines, $face, $size, $ink, $baseline, $align, 1, 0.2, $shadow);
    }

    /**
     * Draw lines from a first baseline; return the last baseline.
     *
     * @param  array<int, string>  $lines
     */
    private function lines($image, array $lines, string $face, float $size, string $ink, int $baseline, string $align, float $leading, float $tracking, bool $shadow): int
    {
        $color = $this->color($image, $ink);
        $dark = $this->color($image, '#000000', 80);
        $y = $baseline;

        foreach ($lines as $i => $line) {
            if ($i > 0) {
                $y += (int) round($size * $leading);
            }

            $width = $this->measure($line, $face, $size, $tracking);
            $x = match ($align) {
                'left' => self::MARGIN,
                default => (int) ((self::WIDTH - $width) / 2),
            };

            // A soft shadow only over photography, where contrast cannot be
            // guaranteed. The palettes are chosen to pass without one.
            if ($shadow) {
                $this->text($image, $line, $face, $size, $dark, $x + 4, $y + 5, $tracking);
            }

            $this->text($image, $line, $face, $size, $color, $x, $y, $tracking);
        }

        return $y;
    }

    /**
     * The first baseline of a block under one whose last baseline is $y:
     * the descent of the line above, a gap, and the ascent of the next.
     */
    private function below(int $y, float $sizeAbove, float $sizeBelow, float $gap): int
    {
        return $y + (int) round($sizeAbove * 0.28 + $sizeAbove * $gap * 0.5 + $sizeBelow * 0.95);
    }

    private function text($image, string $text, string $face, float $size, int $color, int $x, int $y, float $tracking): void
    {
        if ($tracking <= 0) {
            imagettftext($image, $size, 0, $x, $y, $color, $face, $text);

            return;
        }

        foreach (mb_str_split($text) as $char) {
            imagettftext($image, $size, 0, $x, $y, $color, $face, $char);
            $x += (int) round($this->advance($char, $face, $size) + $size * $tracking);
        }
    }

    private function measure(string $text, string $face, float $size, float $tracking = 0): float
    {
        if ($tracking <= 0) {
            $box = imagettfbbox($size, 0, $face, $text);

            return abs($box[2] - $box[0]);
        }

        $width = 0;

        foreach (mb_str_split($text) as $char) {
            $width += $this->advance($char, $face, $size) + $size * $tracking;
        }

        return $width - $size * $tracking;
    }

    private function advance(string $char, string $face, float $size): float
    {
        // Measured between two letters, so the advance includes the
        // character's side bearings, and a space (which has no ink) has a
        // width at all. A bare bounding box gave "WIT HOUT".
        return $this->measure("H{$char}H", $face, $size) - $this->measure('HH', $face, $size);
    }

    /**
     * The largest size, from $start down, at which the text fits in
     * $maxLines lines of $width.
     *
     * @return array{0: float, 1: array<int, string>}
     */
    private function fit(string $text, string $face, float $start, int $maxLines, int $width, float $tracking = 0): array
    {
        for ($size = $start; $size > 24; $size *= 0.94) {
            $lines = $this->wrap($text, $face, $size, $width, $tracking);

            if ($lines !== null && count($lines) <= $maxLines) {
                return [$size, $lines];
            }
        }

        return [24, $this->wrap($text, $face, 24, $width, $tracking) ?? [$text]];
    }

    /**
     * Words into lines no wider than $width, balanced so the last line is not
     * a single stranded word; null if one word alone is too wide.
     *
     * @return array<int, string>|null
     */
    private function wrap(string $text, string $face, float $size, int $width, float $tracking): ?array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $line = '';

        foreach ($words as $word) {
            if ($this->measure($word, $face, $size, $tracking) > $width) {
                return null;
            }

            $candidate = $line === '' ? $word : "{$line} {$word}";

            if ($this->measure($candidate, $face, $size, $tracking) <= $width) {
                $line = $candidate;
            } else {
                $lines[] = $line;
                $line = $word;
            }
        }

        if ($line !== '') {
            $lines[] = $line;
        }

        // Pull a word down onto a lone last word while the line above
        // still fits: "Building an Independent / Practice" becomes two
        // even lines.
        $count = count($lines);

        if ($count >= 2 && ! str_contains($lines[$count - 1], ' ')) {
            $above = explode(' ', $lines[$count - 2]);

            if (count($above) > 1) {
                $moved = array_pop($above).' '.$lines[$count - 1];

                if ($this->measure($moved, $face, $size, $tracking) <= $width) {
                    $lines[$count - 2] = implode(' ', $above);
                    $lines[$count - 1] = $moved;
                }
            }
        }

        return $lines;
    }

    /**
     * Artwork scaled to fill the cover, cropped from the centre; or, with a
     * framing, zoomed and cropped around its focal point, kept inside the
     * picture.
     *
     * @param  array{x: float, y: float, zoom: float}|null  $framing
     */
    public function cover($image, $art, ?array $framing = null): void
    {
        $w = imagesx($art);
        $h = imagesy($art);
        [$width, $height] = [imagesx($image), imagesy($image)];

        if ($framing !== null) {
            [$sx, $sy, $cropW, $cropH] = self::frame($w, $h, $width, $height, $framing);
            imagecopyresampled($image, $art, 0, 0, (int) round($sx), (int) round($sy), $width, $height, (int) round($cropW), (int) round($cropH));

            return;
        }

        $scale = max($width / $w, $height / $h);
        $cropW = (int) round($width / $scale);
        $cropH = (int) round($height / $scale);

        imagecopyresampled(
            $image, $art, 0, 0,
            (int) (($w - $cropW) / 2), (int) (($h - $cropH) / 2),
            $width, $height, $cropW, $cropH,
        );
    }

    /**
     * The part of a $w × $h picture a $width × $height frame shows at a
     * framing: the crop's left, top, width and height in the picture's
     * pixels.
     *
     * @param  array{x: float, y: float, zoom: float}  $framing
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public static function frame(int $w, int $h, float $width, float $height, array $framing): array
    {
        $scale = max($width / $w, $height / $h) * $framing['zoom'];
        $cropW = min($w, $width / $scale);
        $cropH = min($h, $height / $scale);
        $sx = max(0.0, min($w - $cropW, $framing['x'] * $w - $cropW / 2));
        $sy = max(0.0, min($h - $cropH, $framing['y'] * $h - $cropH / 2));

        return [$sx, $sy, $cropW, $cropH];
    }

    private function gradient($image, string $from, string $to): void
    {
        [$r1, $g1, $b1] = $this->rgb($from);
        [$r2, $g2, $b2] = $this->rgb($to);

        [$width, $height] = [imagesx($image), imagesy($image)];

        for ($y = 0; $y < $height; $y++) {
            $t = $y / ($height - 1);
            $color = imagecolorallocate(
                $image,
                (int) round($r1 + ($r2 - $r1) * $t),
                (int) round($g1 + ($g2 - $g1) * $t),
                (int) round($b1 + ($b2 - $b1) * $t),
            );
            imageline($image, 0, $y, $width, $y, $color);
        }
    }

    /**
     * Darken the artwork where the type sits, so it reads on any picture.
     *
     * $strength is 0-100. The top fades out by the middle; the foot fades in
     * over the bottom fifth, under the author's name.
     */
    private function shade($image, int $strength, bool $top, bool $bottom): void
    {
        if ($strength <= 0) {
            return;
        }

        $max = $strength / 100;
        [$width, $height] = [imagesx($image), imagesy($image)];

        for ($y = 0; $y < $height; $y++) {
            $t = $y / $height;
            $amount = 0.0;

            if ($top && $t < 0.6) {
                $amount = max($amount, $max * (1 - $t / 0.6));
            }

            if ($bottom && $t > 0.78) {
                $amount = max($amount, $max * 0.85 * (($t - 0.78) / 0.22));
            }

            if ($amount > 0) {
                $alpha = (int) round(127 * (1 - min(1, $amount)));
                imageline($image, 0, $y, $width, $y, imagecolorallocatealpha($image, 0, 0, 0, $alpha));
            }
        }
    }

    /** A colour from hex, with GD's 0 (opaque) - 127 (clear) alpha. */
    private function color($image, string $hex, int $alpha = 0): int
    {
        [$r, $g, $b] = $this->rgb($hex);

        return imagecolorallocatealpha($image, $r, $g, $b, $alpha);
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    /** Raise the memory limit to at least $limit, never lower it. */
    public function headroom(string $limit): void
    {
        $current = ini_get('memory_limit');

        if ($current === '-1') {
            return;
        }

        $bytes = fn (string $v) => (int) $v * match (strtoupper(substr($v, -1))) {
            'G' => 1024 ** 3, 'M' => 1024 ** 2, 'K' => 1024, default => 1,
        };

        if ($bytes($current) < $bytes($limit)) {
            ini_set('memory_limit', $limit);
        }
    }

    /** The bundled face for a family and style, as a path GD can open. */
    private function face(string $family, string $style): string
    {
        $file = match ([$family, $style]) {
            ['sans', 'bold'] => 'SourceSans3-Semibold',
            ['sans', 'italic'] => 'SourceSans3-It',
            ['sans', 'regular'] => 'SourceSans3-Regular',
            ['serif', 'bold'] => 'EBGaramond-SemiBold',
            ['serif', 'italic'] => 'EBGaramond-Italic',
            default => 'EBGaramond-Regular',
        };

        return resource_path("fonts/{$file}.ttf");
    }
}
