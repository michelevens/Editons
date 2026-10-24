<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Platform\AccountAdmin;
use Illuminate\Console\Command;

/**
 * Put accounts whose complimentary plan has ended back on Free.
 *
 * The limits already treat an ended plan as Free (User::effectivePlan), so a
 * late run gives nothing away; this writes the change down, with its audit
 * row, so the account page and billing read the same as the limits do.
 */
class ExpireComplimentaryPlans extends Command
{
    protected $signature = 'platform:expire-complimentary';

    protected $description = 'End complimentary plans whose end date has passed';

    public function handle(AccountAdmin $accounts): int
    {
        $count = 0;

        User::whereNotNull('complimentary_since')
            ->whereNotNull('complimentary_until')
            ->where('complimentary_until', '<=', now())
            ->each(function (User $user) use ($accounts, &$count) {
                $accounts->clearComplimentary($user, 'End date reached', null, 'plan.complimentary_expired');
                $count++;
            });

        $this->info("Ended {$count} complimentary plan".($count === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
