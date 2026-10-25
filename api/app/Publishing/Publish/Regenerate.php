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
                'result' => $export->wasRecentlyCreated ? 'queued' : 'already', 'message' => $export->wasRecentlyCreated ? 'Queued.' : 'Already being made.'];
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
