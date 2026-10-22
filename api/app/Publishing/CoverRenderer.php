<?php

namespace App\Publishing;

use App\Models\Book;
use Illuminate\Support\Facades\Storage;
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
 */
class CoverRenderer
{
    public const WIDTH = 1600;

    public const HEIGHT = 2560;

    /** Cover layouts, in the order Cover Studio offers them. */
    public const LAYOUTS = [
        'classic' => 'Classic',
        'bold' => 'Bold',
        'band' => 'Band',
        'finished' => 'Finished cover',
    ];

    /** Bumped when the drawing changes, so every cover is redrawn once. */
    private const VERSION = 1;

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
        ];
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

    /** Draw a cover and return it as JPEG bytes. */
    public function render(array $settings, ?string $artwork = null): string
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

        $palette = AccessibilityAudit::PALETTES[$settings['palette']] ?? AccessibilityAudit::PALETTES[0];
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagealphablending($image, true);

        $art = $artwork !== null ? @imagecreatefromstring($artwork) : false;

        if ($art) {
            $this->cover($image, $art);
        } else {
            $this->gradient($image, $palette['from'], $palette['to']);
        }

        $onArt = (bool) $art;
        $ink = $onArt ? '#ffffff' : $palette['ink'];

        // A finished cover from a designer already carries its type: it is
        // the cover, untouched. Without artwork there is nothing finished, so
        // the classic layout draws instead.
        match ($art ? $settings['layout'] : ($settings['layout'] === 'finished' ? 'classic' : $settings['layout'])) {
            'finished' => null,
            'bold' => $this->bold($image, $settings, $ink, $onArt),
            'band' => $this->band($image, $settings, $palette, $onArt),
            default => $this->classic($image, $settings, $ink, $onArt),
        };

        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
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

    /** Artwork scaled to fill the cover, cropped from the centre. */
    private function cover($image, $art): void
    {
        $w = imagesx($art);
        $h = imagesy($art);
        $scale = max(self::WIDTH / $w, self::HEIGHT / $h);
        $cropW = (int) round(self::WIDTH / $scale);
        $cropH = (int) round(self::HEIGHT / $scale);

        imagecopyresampled(
            $image, $art, 0, 0,
            (int) (($w - $cropW) / 2), (int) (($h - $cropH) / 2),
            self::WIDTH, self::HEIGHT, $cropW, $cropH,
        );
    }

    private function gradient($image, string $from, string $to): void
    {
        [$r1, $g1, $b1] = $this->rgb($from);
        [$r2, $g2, $b2] = $this->rgb($to);

        for ($y = 0; $y < self::HEIGHT; $y++) {
            $t = $y / (self::HEIGHT - 1);
            $color = imagecolorallocate(
                $image,
                (int) round($r1 + ($r2 - $r1) * $t),
                (int) round($g1 + ($g2 - $g1) * $t),
                (int) round($b1 + ($b2 - $b1) * $t),
            );
            imageline($image, 0, $y, self::WIDTH, $y, $color);
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

        for ($y = 0; $y < self::HEIGHT; $y++) {
            $t = $y / self::HEIGHT;
            $amount = 0.0;

            if ($top && $t < 0.6) {
                $amount = max($amount, $max * (1 - $t / 0.6));
            }

            if ($bottom && $t > 0.78) {
                $amount = max($amount, $max * 0.85 * (($t - 0.78) / 0.22));
            }

            if ($amount > 0) {
                $alpha = (int) round(127 * (1 - min(1, $amount)));
                imageline($image, 0, $y, self::WIDTH, $y, imagecolorallocatealpha($image, 0, 0, 0, $alpha));
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
    private function headroom(string $limit): void
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
