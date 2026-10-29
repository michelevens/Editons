<?php

namespace App\Publishing\Publish;

use App\Models\Book;
use App\Models\BookExport;
use App\Models\ManuscriptSection;
use App\Publishing\CoverWrap;
use App\Publishing\PrintPages;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
 * cover has no change time of its own). The Publish screen says so. A
 * cover wrap records the design it was drawn from, so it can.
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
        // Only a Full-book export goes to a store or a printer: a Sample
        // or a review copy is never offered (ExportOptions).
        $query = BookExport::where('book_id', $book->id)
            ->fullBook()
            ->where('format', $format)
            ->where('status', 'ready')
            ->latest('id');

        if ($service !== null) {
            $query->where('options->service', $service);
        }

        $export = $query->first();

        return self::verdict($book, $format, $export, $export ? ($changed ?? self::lastChange($book)) : $changed, $fingerprint);
    }

    /**
     * Whether $export is still the book as it is now: the one comparison
     * file() and many() both make, so the shelf never disagrees with the
     * Publish screen.
     *
     * @return array{format: string, label: string, export: ?BookExport, fresh: bool, reason: ?string}
     */
    public static function verdict(Book $book, string $format, ?BookExport $export, ?Carbon $changed, ?string $fingerprint = null): array
    {
        $label = self::FILES[$format] ?? (BookExport::FORMATS[$format][0] ?? $format);

        if (! $export) {
            return ['format' => $format, 'label' => $label, 'export' => null, 'fresh' => false, 'reason' => 'Not generated yet.'];
        }

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

        // A cover wrap also records the cover it was drawn from
        // (CoverWrap::fingerprint): the front, back and spine design, and
        // the renderer's version, so a redesigned cover, or one made by an
        // older renderer, is made again.
        if ($format === 'cover_wrap' && ($export->options['cover'] ?? null) !== app(CoverWrap::class)->fingerprint($book)) {
            return ['format' => $format, 'label' => $label, 'export' => $export, 'fresh' => false,
                'reason' => 'The cover design changed after it was made.'];
        }

        return ['format' => $format, 'label' => $label, 'export' => $export, 'fresh' => true, 'reason' => null];
    }

    /**
     * The latest ready Full-book file of each format for many books, and
     * whether each is fresh, in a fixed number of queries whatever the
     * number of books (the shelf's "Last export" column). A cover wrap is
     * the latest for any service. "rendering" says one is being made now.
     *
     * @param  Collection<int, Book>  $books
     * @param  list<string>  $formats
     * @return array<int, array<string, array{at: ?string, fresh: bool, reason: ?string, rendering: bool, service: ?string}>>
     */
    public static function many(Collection $books, array $formats): array
    {
        $ids = $books->pluck('id')->all();

        if ($ids === [] || $formats === []) {
            return [];
        }

        // Newest first, so the first of each (book, format) is the latest.
        $exports = BookExport::whereIn('book_id', $ids)
            ->fullBook()
            ->whereIn('format', $formats)
            ->whereIn('status', ['ready', 'generating'])
            ->orderByDesc('id')
            ->get(['id', 'book_id', 'format', 'status', 'created_at', 'options']);

        $ready = $exports->where('status', 'ready')->groupBy(fn ($e) => $e->book_id.'|'.$e->format)->map->first();
        $rendering = $exports->where('status', 'generating')->groupBy(fn ($e) => $e->book_id.'|'.$e->format);

        $changed = ManuscriptSection::withTrashed()->whereIn('book_id', $ids)
            ->groupBy('book_id')
            ->selectRaw('book_id, max(updated_at) as updated, max(deleted_at) as deleted')
            ->get()
            ->mapWithKeys(function ($row) {
                $times = array_filter([$row->updated, $row->deleted]);

                return [$row->book_id => $times === [] ? null : Carbon::parse(max($times))];
            });

        $aggregates = array_intersect($formats, PrintPages::RECORDING) !== [] ? PrintPages::sectionsAggregate($ids) : [];
        $out = [];

        foreach ($books as $book) {
            $fingerprint = isset($aggregates[$book->id]) ? PrintPages::fingerprint($book, $aggregates[$book->id]) : null;

            foreach ($formats as $format) {
                $export = $ready[$book->id.'|'.$format] ?? null;
                $verdict = self::verdict($book, $format, $export, $changed[$book->id] ?? null, $fingerprint);

                $out[$book->id][$format] = [
                    'at' => $export?->created_at?->toIso8601String(),
                    'fresh' => $verdict['fresh'],
                    'reason' => $verdict['reason'],
                    'rendering' => isset($rendering[$book->id.'|'.$format]),
                    'service' => $export?->options['service'] ?? null,
                ];
            }
        }

        return $out;
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
