<?php

namespace App\Console\Commands;

use App\Platform\Trust\DataHealth;
use Illuminate\Console\Command;

/**
 * Run the data checks and store the result for the console (Data health).
 *
 * Scheduled daily (routes/console.php). Reads only: fixes are applied from
 * the console, by the owner, after a dry run.
 */
class RunDataHealth extends Command
{
    protected $signature = 'platform:data-health';

    protected $description = 'Run the data health checks and store the results';

    public function handle(DataHealth $health): int
    {
        $run = $health->run('schedule');

        foreach ($run->results as $check) {
            $this->line(sprintf('%-9s %-28s %s', $check['status'], $check['key'], $check['count'] ?? '?'));
        }

        return self::SUCCESS;
    }
}
