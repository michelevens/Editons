<?php

namespace App\Covers;

use App\Models\Book;
use App\Publishing\CoverRenderer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The pictures a book's cover layers place: a publisher's logo, a series
 * badge, an author photo.
 *
 * Stored like the book's other images, on the private files disk under
 * covers/book-{id}/images, and listed in `$book->cover['images']` as
 * {id, path, width, height, type, name}. PNG and JPEG only: those are what
 * GD draws. SVG is refused rather than rasterised, since nothing on the
 * server can rasterise one without also running what it may carry (the
 * artwork upload refuses it for the same reason).
 */
class CoverImages
{
    /** Bytes, as the artwork upload allows. */
    public const MAX_BYTES = 12 * 1024 * 1024;

    /** Pictures a book may keep. */
    public const MAX_IMAGES = 20;

    /** Print wants 300 pixels an inch; below it a picture is flagged. */
    public const PRINT_DPI = 300;

    /** @return list<array{id: string, path: string, width: int, height: int, type: string, name: string}> */
    public static function all(Book $book): array
    {
        $images = ($book->cover ?? [])['images'] ?? [];

        return is_array($images) ? array_values(array_filter($images, fn ($i) => is_array($i) && isset($i['id'], $i['path']))) : [];
    }

    /** @return list<string> */
    public static function ids(Book $book): array
    {
        return array_column(self::all($book), 'id');
    }

    /** @return array{id: string, path: string, width: int, height: int, type: string, name: string}|null */
    public static function find(Book $book, string $id): ?array
    {
        foreach (self::all($book) as $image) {
            if ($image['id'] === $id) {
                return $image;
            }
        }

        return null;
    }

    /**
     * Check and store an upload, returning its entry (not yet saved on the
     * book).
     *
     * @return array{id: string, path: string, width: int, height: int, type: string, name: string}
     *
     * @throws ValidationException
     */
    public static function store(Book $book, UploadedFile $file): array
    {
        if (count(self::all($book)) >= self::MAX_IMAGES) {
            self::fail('A cover can keep up to '.self::MAX_IMAGES.' pictures. Delete one you no longer use.');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            self::fail('That picture is larger than 12 MB.');
        }

        // The bytes decide, not the name or the browser's word for it.
        $info = @getimagesize($file->getRealPath());

        if (! $info || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            self::fail('Use a PNG or JPEG picture. SVG and other formats are not accepted.');
        }

        if ($info[0] * $info[1] > CoverRenderer::MAX_PIXELS) {
            self::fail('That picture is too large to use. Use one under 40 megapixels.');
        }

        if ($info[0] < 16 || $info[1] < 16) {
            self::fail('That picture is too small to print.');
        }

        // One that will not decode is refused here, not when the cover is drawn.
        if (! @imagecreatefromstring((string) file_get_contents($file->getRealPath()))) {
            self::fail('That picture could not be read.');
        }

        $type = $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg';
        $id = 'img'.Str::lower(Str::random(10));
        $path = "covers/book-{$book->id}/images/{$id}.{$type}";
        Storage::disk('files')->put($path, (string) file_get_contents($file->getRealPath()));

        return [
            'id' => $id,
            'path' => $path,
            'width' => (int) $info[0],
            'height' => (int) $info[1],
            'type' => $type,
            'name' => Str::limit(trim((string) $file->getClientOriginalName()) ?: 'picture', 80),
        ];
    }

    /** A picture as a GD image, true colour with its alpha, or null. */
    public static function load(string $path)
    {
        static $cache = [];

        if (array_key_exists($path, $cache)) {
            return $cache[$path];
        }

        $disk = Storage::disk('files');
        $image = $disk->exists($path) ? @imagecreatefromstring((string) $disk->get($path)) : false;

        if ($image) {
            imagepalettetotruecolor($image);
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        // A few pictures per render; held for the request, not for ever.
        if (count($cache) > 8) {
            $cache = [];
        }

        return $cache[$path] = $image ?: null;
    }

    /**
     * The resolution a picture prints at in a box $inches wide: its
     * (cropped) pixel width over the width it prints.
     *
     * @param  array<string, mixed>  $layer
     * @param  array<string, mixed>  $image
     */
    public static function dpi(array $layer, array $image, float $inches): float
    {
        $pixels = $image['width'] * ($layer['crop']['width'] ?? 1.0);

        return $inches > 0 ? $pixels / $inches : INF;
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['image' => $message]);
    }
}
