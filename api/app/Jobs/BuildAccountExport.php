<?php

namespace App\Jobs;

use App\Models\AccountExport;
use App\Platform\Trust\AccountExporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Build an account's data export on the worker (App\Platform\Trust\AccountExporter).
 *
 * One attempt: the builder settles its own row as failed, and the owner
 * asks again from the console.
 */
class BuildAccountExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly int $exportId) {}

    public function handle(AccountExporter $exporter): void
    {
        $export = AccountExport::find($this->exportId);

        if ($export) {
            $exporter->build($export);
        }
    }

    public function failed(?\Throwable $e): void
    {
        AccountExport::whereKey($this->exportId)->where('status', 'queued')->update([
            'status' => 'failed',
            'error' => mb_substr($e?->getMessage() ?? 'The job stopped.', 0, 500),
            'updated_at' => now(),
        ]);
    }
}
