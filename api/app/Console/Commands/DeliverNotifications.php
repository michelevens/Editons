<?php

namespace App\Console\Commands;

use App\Mail\HeldEmails;
use Illuminate\Console\Command;

/**
 * The hourly delivery run (App\Mail\HeldEmails): emails held for quiet
 * hours, daily and weekly digests whose hour has come, and the weekly
 * progress summary for people who opted in. Safe to run more often or
 * twice at once: every send is claimed first.
 */
class DeliverNotifications extends Command
{
    protected $signature = 'notifications:deliver';

    protected $description = 'Send held emails, digests and the opted-in weekly progress summaries that are due';

    public function handle(): int
    {
        $done = HeldEmails::deliver();

        $this->info("Released {$done['released']} held email(s), sent {$done['digests']} digest(s) and {$done['progress']} weekly progress summary(ies).");

        return self::SUCCESS;
    }
}
