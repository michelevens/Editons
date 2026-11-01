<?php

namespace App\Jobs;

use App\Ai\BookDrafter;
use App\Billing\InsufficientCredits;
use App\Editorial\ProviderUnavailable;
use App\Models\DraftRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * "Write my book", on the worker (ADR — AI writes the book).
 *
 * One section at a time, each saved, charged and recorded on its own, so
 * whatever finished survives a crash, a pause, a cancel or a budget. Before
 * each section the run looks again at what can stop it — the author's
 * pause or cancel, the kill switch, the budget, the balance — and stops
 * cleanly, saying why and what is left. When every section is done the
 * consistency pass leaves its findings for the author.
 *
 * Idempotent: a done or skipped section is never written again, so a
 * retried or resumed job pays for nothing twice.
 */
class WriteBook implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 7200;

    public function __construct(public readonly int $runId) {}

    public function handle(BookDrafter $drafter): void
    {
        $run = DraftRun::find($this->runId);

        if (! $run || ! $run->active()) {
            return;
        }

        $run->update(['status' => 'running', 'started_at' => $run->started_at ?? now()]);

        foreach ($run->chapters()->get() as $chapter) {
            $run->refresh();

            // Paused or cancelled by the author: stop before the next one.
            if ($run->status !== 'running') {
                return;
            }

            $chapter->refresh();

            if (! in_array($chapter->status, ['queued', 'writing'], true)) {
                continue;
            }

            if ($reason = $drafter->stopReason($run, $chapter)) {
                $run->update(['status' => 'stopped', 'stop_reason' => $reason, 'finished_at' => now()]);

                return;
            }

            try {
                $drafter->writeChapter($run, $chapter);
            } catch (ProviderUnavailable $e) {
                $run->update(['status' => 'stopped', 'stop_reason' => $drafter->authorMessage($e), 'finished_at' => now()]);

                return;
            } catch (InsufficientCredits) {
                $run->update(['status' => 'stopped', 'stop_reason' => __('Stopped: the credit balance ran out. :done of :total sections written; :left left (about :credits credits).', $drafter->remainingSummary($run)), 'finished_at' => now()]);

                return;
            }
        }

        $run->refresh();

        if ($run->status !== 'running') {
            return;
        }

        $drafter->consistency($run);

        $run->update(['status' => 'completed', 'finished_at' => now()]);
    }

    public function failed(?Throwable $e): void
    {
        DraftRun::whereKey($this->runId)->whereIn('status', DraftRun::ACTIVE)->update([
            'status' => 'failed',
            'stop_reason' => __('The draft stopped unexpectedly. What was written is kept; resume to continue.'),
            'finished_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
