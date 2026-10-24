<?php

namespace App\Console\Commands;

use App\Commerce\Partners\Commissions;
use Illuminate\Console\Command;

/**
 * Partner commissions whose hold is over become payable (pending →
 * approved). Scheduled daily (routes/console.php); a late or repeated run
 * approves only what is due, once.
 */
class ReleasePartnerCommissions extends Command
{
    protected $signature = 'partners:release-commissions';

    protected $description = 'Approve partner commissions whose refund hold has ended';

    public function handle(Commissions $commissions): int
    {
        $count = $commissions->releaseDue();

        $this->info("Approved {$count} commission(s).");

        return self::SUCCESS;
    }
}
