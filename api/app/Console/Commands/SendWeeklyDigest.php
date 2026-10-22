<?php

namespace App\Console\Commands;

use App\Mail\Notify;
use App\Mail\Support\WeeklyProgress;
use App\Mail\Writing\WeeklyDigestMail;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Send last week's writing digest.
 *
 * Not scheduled. To turn it on, add to routes/console.php:
 *
 *     Schedule::command('emails:weekly-digest')->weeklyOn(1, '8:00');
 *
 * "Last week" is the previous Monday-to-Sunday. People who switched the
 * digest off are skipped by Notify; people with no books are skipped here;
 * people who wrote nothing are skipped unless --include-quiet is passed,
 * because an email that says "you did nothing" every Monday is the fastest
 * way to get all our mail marked as spam.
 */
class SendWeeklyDigest extends Command
{
    protected $signature = 'emails:weekly-digest
        {--user= : Send only to this user id}
        {--include-quiet : Also send to people who wrote nothing last week}
        {--dry-run : Count who would get it, send nothing}';

    protected $description = 'Email each author a summary of what they wrote last week';

    public function handle(): int
    {
        $start = now()->subWeek()->startOfWeek();
        $end = $start->copy()->endOfWeek();

        $sent = 0;
        $skipped = 0;

        User::query()
            ->when($this->option('user'), fn ($q, $id) => $q->whereKey($id))
            ->whereHas('books')
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($start, $end, &$sent, &$skipped) {
                foreach ($users as $user) {
                    $summary = WeeklyProgress::for($user, $start, $end);

                    $quiet = $summary === null
                        || ($summary['words'] === 0 && $summary['active_days'] === 0);

                    if ($summary === null || ($quiet && ! $this->option('include-quiet'))) {
                        $skipped++;

                        continue;
                    }

                    if ($this->option('dry-run')) {
                        $sent++;

                        continue;
                    }

                    Notify::send($user, new WeeklyDigestMail($user, $start, $end, $summary))
                        ? $sent++
                        : $skipped++;
                }
            });

        $this->info(($this->option('dry-run') ? 'Would send' : 'Sent')." {$sent} digest(s) for {$start->toDateString()} – {$end->toDateString()}; skipped {$skipped}.");

        return self::SUCCESS;
    }
}
