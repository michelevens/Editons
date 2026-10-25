<?php

namespace App\Console\Commands;

use App\Commerce\Print\LuluClient;
use App\Commerce\Print\PrintJobs;
use App\Jobs\SubmitPrintJob;
use App\Models\PrintJob;
use Illuminate\Console\Command;

/**
 * Lulu print jobs: send the ones still waiting, then read the status of
 * every open one (tracking to the buyer on SHIPPED, PrintJobs::apply).
 *
 * Lulu also offers webhooks; polling needs no public endpoint or secret,
 * and a paperback takes days, so every half hour is plenty.
 */
class PollPrintJobs extends Command
{
    protected $signature = 'lulu:poll {--limit=200}';

    protected $description = 'Send waiting Lulu print jobs and update the status of open ones';

    public function handle(LuluClient $client, PrintJobs $jobs): int
    {
        if (! $client->connected()) {
            $this->info('Lulu is not connected; nothing to do.');

            return self::SUCCESS;
        }

        // Jobs that could not be sent at the time (Lulu down, keys added
        // after the sale), a few tries each.
        $waiting = PrintJob::whereNull('external_id')->whereIn('status', ['pending', 'failed'])
            ->where('attempts', '<', SubmitPrintJob::MAX_ATTEMPTS)
            ->pluck('id');

        foreach ($waiting as $id) {
            SubmitPrintJob::dispatch($id);
        }

        $summary = $jobs->poll((int) $this->option('limit'));

        $this->info(sprintf('Sent %d; checked %d, %d shipped, %d with a problem.', $waiting->count(), $summary['checked'], $summary['shipped'], $summary['problems']));

        return self::SUCCESS;
    }
}
