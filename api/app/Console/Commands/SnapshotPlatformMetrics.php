<?php

namespace App\Console\Commands;

use App\Platform\PlatformMetrics;
use Illuminate\Console\Command;

/**
 * Record today's platform figures, for the console's trend lines.
 *
 * Scheduled daily (routes/console.php); safe to re-run, since a second run
 * on the same day replaces that day's row.
 */
class SnapshotPlatformMetrics extends Command
{
    protected $signature = 'platform:snapshot-metrics';

    protected $description = 'Record today\'s platform metrics snapshot';

    public function handle(PlatformMetrics $metrics): int
    {
        $row = $metrics->snapshot();

        $this->info("Snapshot for {$row->day->toDateString()}: {$row->figures['accounts_total']} accounts, {$row->figures['active_writers_7d']} active writers (7 days).");

        return self::SUCCESS;
    }
}
