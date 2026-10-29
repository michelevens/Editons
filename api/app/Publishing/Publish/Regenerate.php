<?php

namespace App\Publishing\Publish;

use App\Billing\Entitlements;
use App\Jobs\GenerateExport;
use App\Jobs\MakeCoverWrap;
use App\Models\Book;
use App\Models\BookExport;
use App\Models\User;
use App\Platform\House\House;
use App\Publishing\Exporter;
use App\Publishing\PrintSpecs;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * "Regenerate files": every file the chosen channels need that is missing
 * or older than the manuscript (Freshness), queued in one click.
 *
 * The EPUB and the print interior render at once, each on its own worker
 * (GenerateExport, as Export & Publish does); the cover wraps follow the
 * interior in a chain (MakeCoverWrap), so their spines use its typeset
 * page count. The plan that decides which formats may be exported is the
 * book's account's (House::accountFor), as in the studio.
 */
class Regenerate
{
    /** Formats the shelf can regenerate: what goes to readers, stores and printers. */
    public const FORMATS = ['pdf', 'epub', 'pdf_interior', 'cover_wrap'];

    public function __construct(
        private readonly Exporter $exporter,
        private readonly Entitlements $entitlements,
    ) {}

    /**
     * @return list<array{format: string, service: ?string, label: string, result: string, message: string}>
     */
    public function stale(Book $book, User $actor): array
    {
        $files = array_values(array_filter(
            Freshness::files($book, PublishPlan::wantsEbook($book), PublishPlan::services($book)),
            fn ($file) => ! $file['fresh'],
        ));

        return $this->queue($book, $actor, $files);
    }

    /**
     * The shelf's "Regenerate stale files" (ADR-00XX — shelf views): of the
     * formats chosen, every Full-book file of this book that is out of date
     * (Freshness), and with $missing also those never made. A cover wrap is
     * made for each print service the book's Publish plan chose, or the one
     * its last wrap was for. Each file considered is reported, fresh ones
     * included, so a summary can say why nothing was queued.
     *
     * @param  list<string>  $formats  of FORMATS
     * @return list<array{format: string, service: ?string, label: string, result: string, message: string}>
     */
    public function formats(Book $book, User $actor, array $formats, bool $missing = false): array
    {
        $changed = Freshness::lastChange($book);
        $files = [];
        $report = [];

        foreach (array_values(array_intersect(self::FORMATS, $formats)) as $format) {
            if ($format !== 'cover_wrap') {
                $files[] = Freshness::file($book, $format, null, $changed);

                continue;
            }

            $services = PublishPlan::services($book) ?: array_filter([BookExport::where('book_id', $book->id)->fullBook()
                ->where('format', 'cover_wrap')->latest('id')->first(['options'])?->options['service'] ?? null]);

            if ($services === []) {
                $report[] = ['format' => 'cover_wrap', 'service' => null, 'label' => Freshness::FILES['cover_wrap'], 'result' => 'skipped',
                    'message' => 'No print service chosen in Publish, and no cover wrap made before.'];
            }

            foreach (array_values(array_unique($services)) as $service) {
                $files[] = ['service' => $service] + Freshness::file($book, 'cover_wrap', $service, $changed);
            }
        }

        $todo = [];

        foreach ($files as $file) {
            $label = $file['label'].(isset($file['service']) ? ' ('.PrintSpecs::label($file['service']).')' : '');

            if ($file['fresh']) {
                $report[] = ['format' => $file['format'], 'service' => $file['service'] ?? null, 'label' => $label, 'result' => 'fresh', 'message' => 'Up to date.'];
            } elseif ($file['export'] === null && ! $missing) {
                $report[] = ['format' => $file['format'], 'service' => $file['service'] ?? null, 'label' => $label, 'result' => 'skipped', 'message' => 'Never made.'];
            } else {
                $todo[] = $file;
            }
        }

        return [...$report, ...$this->queue($book, $actor, $todo)];
    }

    /**
     * Queue the given stale files: an EPUB or PDF on its own worker, the
     * interior and then each cover wrap in a chain.
     *
     * @param  list<array<string, mixed>>  $files  from Freshness
     * @return list<array{format: string, service: ?string, label: string, result: string, message: string, export_id?: int}>
     */
    private function queue(Book $book, User $actor, array $files): array
    {
        if ($files === []) {
            return [];
        }

        $results = [];
        $wraps = [];
        $interior = null;

        foreach ($files as $file) {
            $label = $file['label'].(isset($file['service']) ? ' ('.PrintSpecs::label($file['service']).')' : '');

            try {
                $this->entitlements->assertCanExport(House::accountFor($book, $actor), in_array($file['format'], ['pdf_interior', 'cover_wrap'], true) ? 'pdf' : $file['format']);
            } catch (Throwable $e) {
                $results[] = ['format' => $file['format'], 'service' => $file['service'] ?? null, 'label' => $label, 'result' => 'refused', 'message' => $e->getMessage()];

                continue;
            }

            if ($file['format'] === 'cover_wrap') {
                $wraps[] = $file;

                continue;
            }

            try {
                $export = $this->exporter->start($book, $file['format'], $actor->id);
            } catch (Throwable $e) {
                $results[] = ['format' => $file['format'], 'service' => null, 'label' => $label, 'result' => 'refused', 'message' => $e->getMessage()];

                continue;
            }

            if ($file['format'] === 'pdf_interior') {
                $interior = $export;
            } elseif ($export->wasRecentlyCreated) {
                $this->dispatch($export);
            }

            $results[] = ['format' => $file['format'], 'service' => null, 'label' => $label,
                'result' => $export->wasRecentlyCreated ? 'queued' : 'already', 'message' => $export->wasRecentlyCreated ? 'Queued.' : 'Already being made.',
                'export_id' => $export->id];
        }

        $chain = array_map(fn ($file) => new MakeCoverWrap($book->id, $actor->id, [
            'service' => $file['service'],
            'paper' => $book->printSettings()['paper'],
        ]), $wraps);

        foreach ($wraps as $file) {
            $results[] = ['format' => 'cover_wrap', 'service' => $file['service'], 'label' => $file['label'].' ('.PrintSpecs::label($file['service']).')',
                'result' => 'queued', 'message' => $interior ? 'Queued after the print interior.' : 'Queued.'];
        }

        // The interior first, then each wrap; a new interior row is only
        // ever handed to one worker.
        $first = $interior?->wasRecentlyCreated ? [new GenerateExport($interior->id)] : [];

        if ($first !== [] || $chain !== []) {
            try {
                Bus::chain([...$first, ...$chain])->dispatch();
            } catch (Throwable $e) {
                if ($interior?->wasRecentlyCreated) {
                    BookExport::whereKey($interior->id)->where('status', 'generating')->update(['status' => 'failed']);
                }

                throw $e;
            }
        }

        return $results;
    }

    private function dispatch(BookExport $export): void
    {
        try {
            GenerateExport::dispatch($export->id);
        } catch (Throwable $e) {
            // A row nothing will render must not say "generating".
            BookExport::whereKey($export->id)->where('status', 'generating')->update(['status' => 'failed']);

            throw $e;
        }
    }
}
