<?php

namespace App\Console\Commands;

use App\Growth\Lifecycle;
use Illuminate\Console\Command;

/**
 * Send the lifecycle emails that are due (App\Growth\Lifecycle): once per
 * account per campaign, only for campaigns switched on, only to people
 * whose preferences allow them. Scheduled hourly.
 */
class RunLifecycleCampaigns extends Command
{
    protected $signature = 'growth:lifecycle';

    protected $description = 'Send due lifecycle emails (each once per account)';

    public function handle(Lifecycle $lifecycle): int
    {
        $result = $lifecycle->run();

        $this->info("Sent {$result['sent']}, skipped {$result['skipped']}, failed {$result['failed']}.");

        return self::SUCCESS;
    }
}
