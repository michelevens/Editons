<?php

namespace App\Console\Commands;

use App\Billing\StaleHolds;
use Illuminate\Console\Command;

/**
 * Give back credits held by AI work a dead process left running (ADR — AI
 * writer round 2; App\Billing\StaleHolds). Scheduled in routes/console.php.
 */
class SweepStaleHolds extends Command
{
    protected $signature = 'ai:sweep-stale';

    protected $description = 'Fail AI reviews and research runs a dead process left running, and refund their credits';

    public function handle(StaleHolds $holds): int
    {
        $counts = $holds->sweep();

        $this->info("{$counts['reviews']} review(s), {$counts['research']} research run(s) and {$counts['runs']} queued run(s) failed and refunded.");

        return self::SUCCESS;
    }
}
