<?php

namespace App\Console\Commands;

use App\Commerce\Preorders;
use Illuminate\Console\Command;

/**
 * Pre-ordered books whose release date has come: licences issued and the
 * buyers emailed their downloads. Scheduled every five minutes
 * (routes/console.php); a late or repeated run releases what is due, once.
 */
class ReleasePreorders extends Command
{
    protected $signature = 'commerce:release-preorders';

    protected $description = 'Deliver storefront pre-orders whose release date has come';

    public function handle(Preorders $preorders): int
    {
        $count = $preorders->releaseDue();

        $this->info("Released {$count} pre-ordered item(s).");

        return self::SUCCESS;
    }
}
