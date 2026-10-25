<?php

namespace App\Jobs;

use App\Models\Book;
use App\Publishing\Exporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Make one print service's cover wrap, after the print interior.
 *
 * "Regenerate files" (App\Publishing\Publish\Regenerate) chains these
 * behind the interior's render, so each wrap reuses the interior's typeset
 * page count instead of typesetting the book again. Only one cover wrap of
 * a book renders at a time (Exporter::start), so a wrap that finds another
 * service's wrap still rendering waits and tries again.
 */
class MakeCoverWrap implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $timeout = 1800;

    /** @param  array<string, mixed>  $options  service and paper */
    public function __construct(
        public readonly int $bookId,
        public readonly ?int $userId,
        public readonly array $options,
    ) {}

    public function backoff(): int
    {
        return 120;
    }

    public function handle(Exporter $exporter): void
    {
        $book = Book::find($this->bookId);

        if (! $book) {
            return;
        }

        try {
            $export = $exporter->start($book, 'cover_wrap', $this->userId, $this->options);
        } catch (RuntimeException $e) {
            // Another service's cover is rendering: come back for this one.
            if (str_contains($e->getMessage(), 'is being made')) {
                $this->release($this->backoff());

                return;
            }

            report($e);

            return;
        }

        if (! $export->wasRecentlyCreated) {
            return;
        }

        try {
            (new GenerateExport($export->id))->handle($exporter);
        } catch (Throwable $e) {
            // run() has already marked the row failed and told the author.
            report($e);
        }
    }
}
