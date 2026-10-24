<?php

namespace App\Console\Commands;

use App\Growth\Broadcasts;
use Illuminate\Console\Command;

/**
 * Start the console's broadcasts whose time has come (App\Growth\Broadcasts).
 * Scheduled every minute; each broadcast is claimed before it starts, so an
 * overlapping run starts nothing twice.
 */
class SendDueBroadcasts extends Command
{
    protected $signature = 'growth:send-broadcasts';

    protected $description = 'Start scheduled broadcasts that are due';

    public function handle(Broadcasts $broadcasts): int
    {
        $started = $broadcasts->dispatchDue();

        $this->info("Started {$started} broadcast".($started === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
