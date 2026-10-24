<?php

namespace App\Console\Commands;

use App\Growth\Invitations\Invitations;
use Illuminate\Console\Command;

/**
 * Mark pending invitations past their date as expired.
 *
 * The link already refuses once the date passes (AuthorInvitation::isLive),
 * so a late run lets nobody in; this writes the state down so the console's
 * counts and filters read the same as the link does.
 */
class ExpireInvitations extends Command
{
    protected $signature = 'invitations:expire';

    protected $description = 'Mark pending author invitations past their expiry date as expired';

    public function handle(Invitations $invitations): int
    {
        $count = $invitations->expireDue();

        $this->info("Expired {$count} invitation".($count === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
