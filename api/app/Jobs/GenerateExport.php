<?php

namespace App\Jobs;

use App\Models\BookExport;
use App\Publishing\Exporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Render a PDF or EPUB away from the request.
 *
 * Laying out a long book takes minutes: a 75,000-word exam-prep book with
 * typeset formulas needs about nine for its PDF. Inside the request that ran
 * past every web server's time limit, and the export row was left saying
 * "generating" for good, because the process died before it could say
 * anything else. Here it runs on a worker, the screen polls the row, and a
 * job that dies is recorded as failed (see failed()).
 */
class GenerateExport implements ShouldQueue
{
    use Queueable;

    /** Formats rendered by this job rather than in the request. */
    public const FORMATS = ['pdf', 'epub'];

    /** One attempt: a render that failed once will fail the same way again. */
    public int $tries = 1;

    /** Long enough for the longest book; the worker's --timeout must exceed it. */
    public int $timeout = 1800;

    public function __construct(public readonly int $exportId) {}

    public function handle(Exporter $exporter): void
    {
        $export = BookExport::find($this->exportId);

        // Deleted with its book while it waited, or already settled.
        if (! $export || $export->status !== 'generating') {
            return;
        }

        // run() marks the row failed itself before rethrowing.
        $exporter->run($export);
    }

    /** A worker killed by the timeout never reaches run()'s catch. */
    public function failed(?Throwable $e): void
    {
        BookExport::whereKey($this->exportId)->where('status', 'generating')->update(['status' => 'failed']);
    }
}
