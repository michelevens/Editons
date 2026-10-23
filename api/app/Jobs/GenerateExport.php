<?php

namespace App\Jobs;

use App\Mail\Notify;
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
 * job that dies is recorded as failed (see failed()). A worker killed
 * outright never reaches failed(); `exports:expire` settles those rows.
 */
class GenerateExport implements ShouldQueue
{
    use Queueable;

    /** Formats rendered by this job rather than in the request. */
    public const FORMATS = ['pdf', 'epub'];

    /** One attempt: a render that failed once will fail the same way again. */
    public int $tries = 1;

    /**
     * Long enough for the longest book.
     *
     * The worker's --timeout must exceed it, and the queue's retry_after
     * must exceed that (config/queue.php), or a second worker takes the job
     * back while the first is still rendering.
     */
    public int $timeout = 1800;

    public function __construct(public readonly int $exportId) {}

    public function handle(Exporter $exporter): void
    {
        $export = BookExport::find($this->exportId);

        // Already settled: by the sweep, or by a worker before this one.
        if (! $export || $export->status !== 'generating') {
            return;
        }

        // Books are soft-deleted, so the row outlives a book deleted while
        // the job waited. Nothing to render and nobody to tell: settle the
        // row quietly rather than fail the job over an expected event.
        if (! $export->book) {
            BookExport::whereKey($export->id)->where('status', 'generating')->update(['status' => 'failed']);

            return;
        }

        // Started: the sweep measures silence from here, not from the
        // moment the job joined the queue.
        $export->touch();

        // run() marks the row failed itself before rethrowing.
        $exporter->run($export);
    }

    /** A worker killed by the timeout never reaches run()'s catch. */
    public function failed(?Throwable $e): void
    {
        $updated = BookExport::whereKey($this->exportId)->where('status', 'generating')->update(['status' => 'failed']);

        if ($updated && ($export = BookExport::find($this->exportId)) && $export->book) {
            Notify::exportFailed($export, __('The export took too long and was stopped.'));
        }
    }
}
