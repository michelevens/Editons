<?php

namespace App\Covers;

use App\Models\Book;
use App\Publishing\CoverRenderer;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A cover's own backgrounds: any solid colour or gradient, and one picture
 * across the whole print wrap (ADR — Cover backgrounds and series
 * templates).
 *
 * Stored in `$book->cover['backgrounds']`, one per scope:
 *
 *  - front: the front cover, in place of the preset palette's gradient.
 *  - back, spine: that panel of the print wrap, in place of the colour the
 *    wrap takes from the front's spine-side edge.
 *  - wrap: one background continuous across back, spine and front, bleed
 *    included. When set it is the ground of all three panels; the front the
 *    stores get shows the front's share of it.
 *
 * A background is one of
 *
 *  - {kind: solid, color}
 *  - {kind: linear, angle, stops}: the angle in degrees as CSS has it (0 is
 *    toward the top, 90 toward the right, 180 toward the bottom), the
 *    gradient line as long as the box's projection on it, so the first and
 *    last stops reach the corners exactly as `linear-gradient()` does.
 *  - {kind: radial, x, y, stops}: a circle centred at (x, y), fractions of
 *    the box, its last stop at the farthest corner (CSS's `circle
 *    farthest-corner`).
 *  - {kind: image, image, x, y, zoom} (wrap only): an uploaded picture
 *    (CoverImages) filling the sheet around a focal point at a zoom, framed
 *    exactly as the front's artwork is (CoverRenderer::frame).
 *
 * Stops are 2 to 4 {color, at}, `at` from 0 to 1 and never decreasing,
 * mixed in sRGB as browsers mix them, so Cover Studio's swatches and CSS
 * previews agree with the file. Nothing here is set until the author
 * chooses one: the six palettes remain, and a cover without a background
 * draws, and hashes, exactly as before.
 */
class CoverBackground
{
    public const SCOPES = ['front', 'back', 'spine', 'wrap'];

    public const KINDS = ['solid', 'linear', 'radial', 'image'];

    public const MAX_STOPS = 4;

    /**
     * Check one background and return it normalised.
     *
     * @param  list<string>|null  $images  the book's picture ids, for an image background
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function validate(mixed $background, string $scope, ?array $images = null): array
    {
        if (! in_array($scope, self::SCOPES, true)) {
            throw new \InvalidArgumentException("No background scope {$scope}.");
        }

        $key = "backgrounds.{$scope}";

        if (! is_array($background) || ! in_array($background['kind'] ?? null, self::KINDS, true)) {
            self::fail("{$key}.kind", 'Choose a colour, a gradient or a picture.');
        }

        $kind = $background['kind'];
        $allowed = match ($kind) {
            'solid' => ['kind', 'color'],
            'linear' => ['kind', 'angle', 'stops'],
            'radial' => ['kind', 'x', 'y', 'stops'],
            'image' => ['kind', 'image', 'x', 'y', 'zoom'],
        };

        if ($unknown = array_diff(array_keys($background), $allowed)) {
            self::fail($key, 'Unknown background setting: '.implode(', ', $unknown).'.');
        }

        if ($kind === 'image' && $scope !== 'wrap') {
            self::fail("{$key}.kind", 'A picture background spans the whole wrap; use the artwork for the front alone.');
        }

        $hex = ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'];
        $rules = match ($kind) {
            'solid' => ['color' => $hex],
            'linear' => ['angle' => ['required', 'numeric', 'min:0', 'max:360']] + self::stopRules($hex),
            'radial' => ['x' => ['required', 'numeric', 'min:0', 'max:1'], 'y' => ['required', 'numeric', 'min:0', 'max:1']] + self::stopRules($hex),
            'image' => [
                'image' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,40}$/'],
                'x' => ['required', 'numeric', 'min:0', 'max:1'],
                'y' => ['required', 'numeric', 'min:0', 'max:1'],
                'zoom' => ['required', 'numeric', 'min:1', 'max:4'],
            ],
        };

        $validator = Validator::make($background, $rules, [
            'color.regex' => 'A colour must be a hex value like #1e3a5f.',
            'stops.*.color.regex' => 'A colour must be a hex value like #1e3a5f.',
            'stops.min' => 'A gradient has 2 to 4 colours.',
            'stops.max' => 'A gradient has 2 to 4 colours.',
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages(collect($validator->errors()->messages())
                ->mapWithKeys(fn ($messages, $field) => ["{$key}.{$field}" => $messages])->all());
        }

        if (isset($background['stops'])) {
            if (! array_is_list($background['stops'])) {
                self::fail("{$key}.stops", 'The gradient’s colours must be a list.');
            }

            foreach ($background['stops'] as $i => $stop) {
                if (array_diff(array_keys($stop), ['color', 'at']) !== []) {
                    self::fail("{$key}.stops.{$i}", 'A gradient stop is a colour and a position.');
                }

                if ($i > 0 && (float) $stop['at'] < (float) $background['stops'][$i - 1]['at']) {
                    self::fail("{$key}.stops.{$i}.at", 'Gradient stops must run in order.');
                }
            }
        }

        if ($kind === 'image' && $images !== null && ! in_array($background['image'], $images, true)) {
            self::fail("{$key}.image", 'That picture is not one this book has. Upload it again.');
        }

        return self::normalise($background);
    }

    /** @return array<string, array<int, string>> */
    private static function stopRules(array $hex): array
    {
        return [
            'stops' => ['required', 'array', 'min:2', 'max:'.self::MAX_STOPS],
            'stops.*' => ['required', 'array'],
            'stops.*.color' => $hex,
            'stops.*.at' => ['required', 'numeric', 'min:0', 'max:1'],
        ];
    }

    /** @return array<string, mixed> */
    private static function normalise(array $b): array
    {
        $stops = fn () => array_map(fn ($s) => ['color' => strtolower($s['color']), 'at' => (float) $s['at']], $b['stops']);

        return match ($b['kind']) {
            'solid' => ['kind' => 'solid', 'color' => strtolower($b['color'])],
            'linear' => ['kind' => 'linear', 'angle' => (float) $b['angle'], 'stops' => $stops()],
            'radial' => ['kind' => 'radial', 'x' => (float) $b['x'], 'y' => (float) $b['y'], 'stops' => $stops()],
            'image' => ['kind' => 'image', 'image' => (string) $b['image'], 'x' => (float) $b['x'], 'y' => (float) $b['y'], 'zoom' => (float) $b['zoom']],
        };
    }

    /**
     * A book's stored backgrounds, each checked; one that no longer
     * validates (its picture deleted) is left out, so the panel draws as it
     * would without it.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(Book $book): array
    {
        $stored = ($book->cover ?? [])['backgrounds'] ?? null;

        if (! is_array($stored)) {
            return [];
        }

        $images = CoverImages::ids($book);
        $out = [];

        foreach (self::SCOPES as $scope) {
            if (! isset($stored[$scope])) {
                continue;
            }

            try {
                $out[$scope] = self::validate($stored[$scope], $scope, $images);
            } catch (ValidationException) {
                // Drawn as if unset.
            }
        }

        return $out;
    }

    /** One scope's background, or null. @return array<string, mixed>|null */
    public static function for(Book $book, string $scope): ?array
    {
        return self::all($book)[$scope] ?? null;
    }

    // --- Colour -------------------------------------------------------------

    /**
     * The colour at fraction (u, v) of the box the background spans, as
     * 0–255 RGB. An image background answers null: a picture has no one
     * colour; callers sample its pixels instead.
     *
     * @return array{0: int, 1: int, 2: int}|null
     */
    public static function colorAt(array $bg, float $u, float $v, float $width = 1.0, float $height = 1.0): ?array
    {
        return match ($bg['kind']) {
            'solid' => self::rgb($bg['color']),
            'linear' => self::mix($bg['stops'], self::linearT($bg['angle'], $u * $width, $v * $height, $width, $height)),
            'radial' => self::mix($bg['stops'], self::radialT($bg['x'], $bg['y'], $u * $width, $v * $height, $width, $height)),
            default => null,
        };
    }

    /** The first stop's colour (a solid's only one), as hex: the band, the "deep end". */
    public static function firstColor(array $bg): ?string
    {
        return match ($bg['kind']) {
            'solid' => $bg['color'],
            'linear', 'radial' => $bg['stops'][0]['color'],
            default => null,
        };
    }

    /**
     * Where a point (px, py) of a $w × $h box falls on a linear gradient at
     * $angle, as CSS defines it: 0 at the start line, 1 at the end.
     */
    public static function linearT(float $angle, float $px, float $py, float $w, float $h): float
    {
        $a = deg2rad($angle);
        [$dx, $dy] = [sin($a), -cos($a)];
        $length = abs($w * $dx) + abs($h * $dy);

        return $length <= 0 ? 0.0 : (($px - $w / 2) * $dx + ($py - $h / 2) * $dy) / $length + 0.5;
    }

    /** Distance from the centre over the distance to the farthest corner. */
    public static function radialT(float $cx, float $cy, float $px, float $py, float $w, float $h): float
    {
        [$x, $y] = [$cx * $w, $cy * $h];
        $r = max(hypot($x, $y), hypot($w - $x, $y), hypot($x, $h - $y), hypot($w - $x, $h - $y));

        return $r <= 0 ? 0.0 : hypot($px - $x, $py - $y) / $r;
    }

    /**
     * The colour at t along the stops: the first before it, the last after,
     * linear in sRGB between.
     *
     * @param  list<array{color: string, at: float}>  $stops
     * @return array{0: int, 1: int, 2: int}
     */
    public static function mix(array $stops, float $t): array
    {
        $first = $stops[0];
        $last = $stops[count($stops) - 1];

        if ($t <= $first['at']) {
            return self::rgb($first['color']);
        }

        if ($t >= $last['at']) {
            return self::rgb($last['color']);
        }

        for ($i = 1; $i < count($stops); $i++) {
            [$a, $b] = [$stops[$i - 1], $stops[$i]];

            if ($t <= $b['at']) {
                $f = $b['at'] - $a['at'] <= 0 ? 1.0 : ($t - $a['at']) / ($b['at'] - $a['at']);
                [$ca, $cb] = [self::rgb($a['color']), self::rgb($b['color'])];

                return [
                    (int) round($ca[0] + ($cb[0] - $ca[0]) * $f),
                    (int) round($ca[1] + ($cb[1] - $ca[1]) * $f),
                    (int) round($ca[2] + ($cb[2] - $ca[2]) * $f),
                ];
            }
        }

        return self::rgb($last['color']);
    }

    // --- Drawing ------------------------------------------------------------

    /**
     * Paint a background over the region ($rx, $ry, $rw, $rh) of $image,
     * the gradient (or picture) spanning the frame ($fx, $fy, $fw, $fh) —
     * the region itself, or, for the front's share of a wrap background,
     * the whole sheet as it falls around the front.
     *
     * Gradients are drawn as bands across the gradient line (rings for a
     * radial), one per pixel of its length up to 1024: steps of at most a
     * quarter of a colour level on any gradient a cover has, and a few
     * hundred polygons rather than millions of pixels.
     *
     * @param  array<string, mixed>  $bg
     * @param  array<string, array<string, mixed>>  $images  by id, for an image background
     */
    public static function paint($image, array $bg, float $rx, float $ry, float $rw, float $rh, ?array $frame = null, array $images = []): void
    {
        [$fx, $fy, $fw, $fh] = $frame ?? [$rx, $ry, $rw, $rh];
        $x0 = (int) floor($rx);
        $y0 = (int) floor($ry);
        $x1 = (int) ceil($rx + $rw) - 1;
        $y1 = (int) ceil($ry + $rh) - 1;

        if ($x1 < $x0 || $y1 < $y0) {
            return;
        }

        imagesetclip($image, max(0, $x0), max(0, $y0), min(imagesx($image) - 1, $x1), min(imagesy($image) - 1, $y1));
        imagealphablending($image, true);

        try {
            match ($bg['kind']) {
                'solid' => imagefilledrectangle($image, $x0, $y0, $x1, $y1, self::allocate($image, self::rgb($bg['color']))),
                'linear' => self::paintLinear($image, $bg, $fx, $fy, $fw, $fh, [$x0, $y0, $x1, $y1]),
                'radial' => self::paintRadial($image, $bg, $fx, $fy, $fw, $fh, [$x0, $y0, $x1, $y1]),
                'image' => self::paintImage($image, $bg, $fx, $fy, $fw, $fh, $images),
            };
        } finally {
            imagesetclip($image, 0, 0, imagesx($image) - 1, imagesy($image) - 1);
        }
    }

    private static function paintLinear($image, array $bg, float $fx, float $fy, float $fw, float $fh, array $clip): void
    {
        $a = deg2rad($bg['angle']);
        [$dx, $dy] = [sin($a), -cos($a)];
        // Along the bands, perpendicular to the gradient line.
        [$ex, $ey] = [-$dy, $dx];
        $length = abs($fw * $dx) + abs($fh * $dy);
        [$cx, $cy] = [$fx + $fw / 2, $fy + $fh / 2];
        $reach = $fw + $fh + abs($clip[2] - $clip[0]) + abs($clip[3] - $clip[1]) + 4;
        $point = fn (float $t) => [$cx + ($t - 0.5) * $length * $dx, $cy + ($t - 0.5) * $length * $dy];

        $band = function (float $t0, float $t1, array $rgb) use ($image, $point, $ex, $ey, $reach) {
            [$ax, $ay] = $point($t0);
            [$bx, $by] = $point($t1);
            imagefilledpolygon($image, [
                (int) round($ax - $ex * $reach), (int) round($ay - $ey * $reach),
                (int) round($ax + $ex * $reach), (int) round($ay + $ey * $reach),
                (int) round($bx + $ex * $reach), (int) round($by + $ey * $reach),
                (int) round($bx - $ex * $reach), (int) round($by - $ey * $reach),
            ], self::allocate($image, $rgb));
        };

        // Beyond either end, the end colours; then the bands, each a hair
        // wider than its step so no seam shows between them.
        $span = $reach / max(1.0, $length);
        $band(-$span, 0.0, self::mix($bg['stops'], 0.0));
        $band(1.0, 1.0 + $span, self::mix($bg['stops'], 1.0));
        $steps = (int) max(2, min(1024, ceil($length)));

        for ($i = 0; $i < $steps; $i++) {
            $band($i / $steps, ($i + 1.5) / $steps, self::mix($bg['stops'], ($i + 0.5) / $steps));
        }
    }

    private static function paintRadial($image, array $bg, float $fx, float $fy, float $fw, float $fh, array $clip): void
    {
        // Filled discs from the outside in would touch every pixel once per
        // ring. A radial has no edges to keep sharp, so it is computed
        // pixel by pixel on a small grid over the region (at most 320 a
        // side) and resampled up, which smooths it as the eye expects.
        [$x0, $y0, $x1, $y1] = $clip;
        [$rw, $rh] = [$x1 - $x0 + 1, $y1 - $y0 + 1];
        $s = min(1.0, 320 / max($rw, $rh));
        [$gw, $gh] = [max(2, (int) ceil($rw * $s)), max(2, (int) ceil($rh * $s))];
        $grid = imagecreatetruecolor($gw, $gh);

        for ($j = 0; $j < $gh; $j++) {
            $py = $y0 + ($j + 0.5) * $rh / $gh - $fy;

            for ($i = 0; $i < $gw; $i++) {
                $px = $x0 + ($i + 0.5) * $rw / $gw - $fx;
                [$r, $g, $b] = self::mix($bg['stops'], self::radialT($bg['x'], $bg['y'], $px, $py, $fw, $fh));
                imagesetpixel($grid, $i, $j, ($r << 16) | ($g << 8) | $b);
            }
        }

        imagecopyresampled($image, $grid, $x0, $y0, 0, 0, $rw, $rh, $gw, $gh);
        imagedestroy($grid);
    }

    private static function paintImage($image, array $bg, float $fx, float $fy, float $fw, float $fh, array $images): void
    {
        $entry = $images[$bg['image']] ?? null;
        $picture = $entry ? CoverImages::load($entry['path']) : null;

        if (! $picture) {
            return;
        }

        [$sx, $sy, $cw, $ch] = CoverRenderer::frame(imagesx($picture), imagesy($picture), $fw, $fh, ['x' => $bg['x'], 'y' => $bg['y'], 'zoom' => $bg['zoom']]);
        imagealphablending($image, true);
        imagecopyresampled($image, $picture, (int) round($fx), (int) round($fy), (int) round($sx), (int) round($sy),
            (int) round($fw), (int) round($fh), (int) round($cw), (int) round($ch));
    }

    /**
     * The resolution a wrap picture prints at over a sheet $inches wide: the
     * picture's pixels across the part of it the frame shows.
     */
    public static function dpi(array $bg, array $entry, float $sheetWidth, float $sheetHeight): float
    {
        [, , $cw] = CoverRenderer::frame((int) $entry['width'], (int) $entry['height'], $sheetWidth * 1000, $sheetHeight * 1000, ['x' => $bg['x'], 'y' => $bg['y'], 'zoom' => $bg['zoom']]);

        return $sheetWidth > 0 ? $cw / $sheetWidth : INF;
    }

    /** "#rrggbb" as [r, g, b]. @return array{0: int, 1: int, 2: int} */
    public static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    public static function hex(array $rgb): string
    {
        return sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
    }

    private static function allocate($image, array $rgb): int
    {
        return imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
    }

    private static function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
