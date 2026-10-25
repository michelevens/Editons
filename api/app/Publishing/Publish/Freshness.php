<?php

namespace App\Publishing\Publish;

use App\Models\Book;
use App\Models\BookExport;
use App\Publishing\PrintPages;
use Illuminate\Support\Carbon;

/**
 * Are the files a store will receive still the book as it is now?
 *
 * A file is fresh when it was made after the manuscript last changed (a
 * section edited, added or moved to the bin). A print interior or cover
 * wrap must also still match the fingerprint of everything that moves
 * pages (PrintPages: layout, publishing details, title, series, sections),
 * so a margin changed after the interior was typeset makes it stale too.
 *
 * What it cannot see: a cover redesigned after the EPUB was made (the
 * cover has no change time of its own). The Publish screen says so.
 */
class Freshness
{
    /** The files the stores take, and what each is for. */
    public const FILES = [
        'epub' => 'EPUB (ebook stores and the storefront)',
        'pdf_interior' => 'Print interior PDF',
        'cover_wrap' => 'Print cover wrap PDF',
    ];

    /** When the manuscript last changed, or null for a book with no sections. */
    public static function lastChange(Book $book): ?Carbon
    {
        $row = $book->sections()->withTrashed()
            ->selectRaw('max(updated_at) as updated, max(deleted_at) as deleted')
            ->first();

        $times = array_filter([$row?->updated, $row?->deleted]);

        return $times === [] ? null : Carbon::parse(max($times));
    }

    /**
     * The latest ready export of a format, and whether it is fresh. A cover
     * wrap is for one print service (options.service), so it is asked for
     * by service.
     *
     * @return array{format: string, label: string, export: ?BookExport, fresh: bool, reason: ?string}
     */
    public static function file(Book $book, string $format, ?string $service = null, ?Carbon $changed = null, ?string $fingerprint = null): array
    {
        $query = BookExport::where('book_id', $book->id)
            ->where('format', $format)
            ->where('status', 'ready')
            ->latest('id');

        if ($service !== null) {
            $query->where('options->service', $service);
        }

        $export = $query->first();
        $label = self::FILES[$format] ?? (BookExport::FORMATS[$format][0] ?? $format);

        if (! $export) {
            return ['format' => $format, 'label' => $label, 'export' => null, 'fresh' => false, 'reason' => 'Not generated yet.'];
        }

        $changed ??= self::lastChange($book);

        if ($changed && $export->created_at->lt($changed)) {
            return ['format' => $format, 'label' => $label, 'export' => $export, 'fresh' => false,
                'reason' => 'Made '.$export->created_at->diffForHumans().'; the manuscript changed after it.'];
        }

        if (in_array($format, PrintPages::RECORDING, true)) {
            $fingerprint ??= PrintPages::fingerprint($book);

            if (($export->options['fingerprint'] ?? null) !== $fingerprint) {
                return ['format' => $format, 'label' => $label, 'export' => $export, 'fresh' => false,
                    'reason' => 'The layout or publishing details changed after it was made.'];
            }
        }

        return ['format' => $format, 'label' => $label, 'export' => $export, 'fresh' => true, 'reason' => null];
    }

    /**
     * Every file the chosen channels need.
     *
     * @param  list<string>  $services  print services whose cover wraps are needed
     * @return list<array<string, mixed>>
     */
    public static function files(Book $book, bool $ebook, array $services): array
    {
        $changed = self::lastChange($book);
        $fingerprint = $services !== [] ? PrintPages::fingerprint($book) : null;
        $files = [];

        if ($ebook) {
            $files[] = self::file($book, 'epub', null, $changed);
        }

        if ($services !== []) {
            $files[] = self::file($book, 'pdf_interior', null, $changed, $fingerprint);

            foreach (array_values(array_unique($services)) as $service) {
                $files[] = ['service' => $service] + self::file($book, 'cover_wrap', $service, $changed, $fingerprint);
            }
        }

        return $files;
    }
}
