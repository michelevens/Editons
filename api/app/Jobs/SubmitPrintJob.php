<?php

namespace App\Jobs;

use App\Commerce\Print\LuluClient;
use App\Commerce\Print\PrintJobs;
use App\Models\PrintJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Send one paid paperback to Lulu, away from the webhook that paid for it.
 *
 * PrintJobs::submit is idempotent (a job with a Lulu id is never sent
 * again), so a retry is safe. A failure is written on the job and retried
 * by `lulu:poll` up to MAX_ATTEMPTS times; without Lulu keys nothing is
 * sent and the job waits.
 */
class SubmitPrintJob implements ShouldQueue
{
    use Queueable;

    public const MAX_ATTEMPTS = 5;

    public int $tries = 1;

    public function __construct(public readonly int $printJobId) {}

    public function handle(LuluClient $client, PrintJobs $jobs): void
    {
        $job = PrintJob::find($this->printJobId);

        if (! $job || ! $client->connected() || $job->attempts >= self::MAX_ATTEMPTS) {
            return;
        }

        $jobs->submit($job);
    }
}
