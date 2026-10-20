<?php

namespace App\Publishing;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pictures inserted into a manuscript: figures, photographs, diagrams.
 *
 * Stored on the private files disk, like covers, so they survive deploys and
 * are never public. The manuscript holds only the app's own URL for each
 * (/books/{id}/images/{name}); every export resolves that back to the bytes
 * and embeds them, so a PDF or EPUB never depends on a link that only works
 * while signed in.
 */
class BookImages
{
    /** Same ceiling as cover artwork: decoded size, not file size, is the risk. */
    public const MAX_PIXELS = 40_000_000;

    public const MIMES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];

    /** @return array{src: string, name: string, width: int, height: int} */
    public function store(int $bookId, UploadedFile $file): array
    {
        $size = @getimagesize($file->getRealPath());

        if (! $size || $size[0] * $size[1] > self::MAX_PIXELS) {
            throw ValidationException::withMessages([
                'image' => 'That image is too large to use. Use one under 40 megapixels.',
            ]);
        }

        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
        $extension = array_key_exists($extension, self::MIMES) ? $extension : 'png';
        $name = Str::lower(Str::random(24)).'.'.$extension;

        Storage::disk('files')->putFileAs($this->directory($bookId), $file, $name);

        return [
            'src' => $this->src($bookId, $name),
            'name' => $name,
            'width' => (int) $size[0],
            'height' => (int) $size[1],
        ];
    }

    public function src(int $bookId, string $name): string
    {
        return "/books/{$bookId}/images/{$name}";
    }

    public function path(int $bookId, string $name): string
    {
        return $this->directory($bookId).'/'.$name;
    }

    public static function validName(string $name): bool
    {
        return (bool) preg_match('/^[a-z0-9]{8,64}\.(jpg|jpeg|png|webp|gif)$/', $name);
    }

    /**
     * The stored image an src points at, or null for anything else (an
     * external URL, another book's image, a missing file).
     *
     * @return array{name: string, mime: string, data: string}|null
     */
    public function resolve(string $src, ?int $bookId = null): ?array
    {
        if (! preg_match('#^(?:https?://[^/]+)?/books/(\d+)/images/([a-z0-9]+\.[a-z]+)$#', $src, $m)
            || ! self::validName($m[2])
            || ($bookId !== null && (int) $m[1] !== $bookId)) {
            return null;
        }

        $path = $this->path((int) $m[1], $m[2]);
        $disk = Storage::disk('files');

        if (! $disk->exists($path)) {
            return null;
        }

        $extension = pathinfo($m[2], PATHINFO_EXTENSION);

        return ['name' => $m[2], 'mime' => self::MIMES[$extension] ?? 'image/png', 'data' => $disk->get($path)];
    }

    /**
     * Rewrites every stored-image src in a block of HTML.
     *
     * @param  callable(array{name: string, mime: string, data: string}): string  $replace
     */
    public function rewrite(string $html, int $bookId, callable $replace): string
    {
        return preg_replace_callback('#src="(/books/\d+/images/[a-z0-9]+\.[a-z]+)"#', function ($m) use ($bookId, $replace) {
            $image = $this->resolve($m[1], $bookId);

            return $image ? 'src="'.$replace($image).'"' : $m[0];
        }, $html) ?? $html;
    }

    /** As data URIs, for formats that are one self-contained file. */
    public function inline(string $html, int $bookId): string
    {
        return $this->rewrite($html, $bookId, fn ($image) => 'data:'.$image['mime'].';base64,'.base64_encode($image['data']));
    }

    private function directory(int $bookId): string
    {
        return "images/book-{$bookId}";
    }
}
