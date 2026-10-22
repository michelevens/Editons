<?php

namespace App\Console\Commands;

use App\Models\BookExport;
use App\Publishing\Exporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Settle export rows that can no longer keep their promise.
 *
 * Scheduled in routes/console.php. Two kinds:
 *
 * - `generating` rows with no news for longer than the job could run and no
 *   job waiting for them: the process rendering them died without saying
 *   so. They become `failed`, and the Publish screen stops waiting.
 * - with --missing, `ready` rows whose file is no longer in storage (written
 *   to a container's disk before exports went to the bucket). They become
 *   `expired`, which the screen shows with a button to generate again,
 *   rather than a download that can only fail.
 */
class ExpireExports extends Command
{
    protected $signature = 'exports:expire
        {--missing : Also mark ready exports whose file has gone as expired}
        {--dry-run : Count what would change, change nothing}';

    protected $description = 'Fail exports a dead worker left generating, and expire ready exports whose file is gone';

    public function handle(Exporter $exporter): int
    {
        if ($this->option('dry-run')) {
            $this->info(BookExport::where('status', 'generating')
                ->where('updated_at', '<', now()->subSeconds(BookExport::STALE_AFTER))
                ->count().' generating export(s) past the job timeout (some may still be queued).');
        } else {
            $this->info($exporter->expireStale().' stale export(s) marked failed.');
        }

        if (! $this->option('missing')) {
            return self::SUCCESS;
        }

        $expired = 0;

        BookExport::where('status', 'ready')->lazyById(200)->each(function (BookExport $export) use (&$expired) {
            if ($export->path && Storage::disk('files')->exists($export->path)) {
                return;
            }

            $expired++;

            if (! $this->option('dry-run')) {
                BookExport::whereKey($export->id)->where('status', 'ready')->update(['status' => 'expired']);
            }
        });

        $this->info($expired.' ready export(s) with no file '.($this->option('dry-run') ? 'would be marked' : 'marked').' expired.');

        return self::SUCCESS;
    }
}
