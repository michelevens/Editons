<?php

namespace App\Jobs;

use App\Billing\CreditLedger;
use App\Editorial\Contracts\ReadsReviewContext;
use App\Editorial\ReviewRunner;
use App\Models\CreditTransaction;
use App\Models\EditorialReview;
use App\Models\EditorialRun;
use App\Models\ManuscriptSection;
use App\Platform\Ai\AiControls;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A range or whole-book review, on the worker (ADR — Style guides and
 * review options).
 *
 * One section at a time, each through ReviewRunner exactly as a single
 * review: charged, stored and recorded on its own, so whatever finished
 * survives a crash, a timeout or a cancel. Before each section the run
 * looks again at what can stop it — a cancel, the kill switch, the credit
 * balance, the monthly AI cap — and stops cleanly, saying why, rather than
 * starting a section it cannot pay for.
 *
 * Idempotent: a section is done once its review for this run exists. A
 * retried job skips it, so nothing is reviewed or billed twice — even when
 * the worker died between the review and the progress update.
 */
class RunEditorialReview implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 3600;

    public function __construct(public readonly int $runId) {}

    public function handle(ReviewRunner $runner, CreditLedger $ledger, AiControls $controls): void
    {
        $run = EditorialRun::find($this->runId);

        if (! $run || ! $run->active()) {
            return;
        }

        $run->update(['status' => 'running', 'started_at' => $run->started_at ?? now()]);
        $context = $run->reviewContext();
        $user = $run->user;
        $simulated = $runner->simulated();
        $driver = $runner->driver();

        foreach ($run->section_ids as $sectionId) {
            $run->refresh();

            if ($run->status === 'cancelled') {
                $run->update(['finished_at' => now()]);

                return;
            }

            if (in_array($sectionId, $run->done_ids ?? [], true)) {
                continue;
            }

            $section = ManuscriptSection::where('book_id', $run->book_id)->find($sectionId);

            // Done already by a run that died before saying so.
            $review = $section ? $this->reviewed($run, $sectionId) : null;

            if ($section && ! $review) {
                if ($reason = $this->stopReason($run, $section, $context, $ledger, $controls, $simulated, $driver)) {
                    $run->update(['status' => 'stopped', 'stop_reason' => $reason, 'finished_at' => now()]);

                    return;
                }

                $review = $runner->run($section, $run->agent, $run->user_id, $context, null, [
                    'scope' => $run->scope, 'run_id' => $run->uuid,
                ]);
            }

            $this->record($run, $sectionId, $review);
        }

        $run->update(['status' => 'completed', 'finished_at' => now()]);
    }

    public function failed(?Throwable $e): void
    {
        EditorialRun::whereKey($this->runId)->whereIn('status', EditorialRun::ACTIVE)->update([
            'status' => 'failed',
            'stop_reason' => mb_substr($e?->getMessage() ?? 'The run stopped.', 0, 500),
            'finished_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function reviewed(EditorialRun $run, int $sectionId): ?EditorialReview
    {
        return EditorialReview::where('manuscript_section_id', $sectionId)
            ->where('options->run_id', $run->uuid)
            ->whereIn('status', ['completed', 'failed'])
            ->latest('id')
            ->first();
    }

    /** Why the next section must not start, or null. */
    private function stopReason(EditorialRun $run, ManuscriptSection $section, $context, CreditLedger $ledger, AiControls $controls, bool $simulated, $driver): ?string
    {
        if ($refusal = $controls->refusal('editorial_review', $run->user)) {
            return $refusal;
        }

        if ($simulated || ! $run->user) {
            return null;
        }

        $characters = mb_strlen($section->plainText());

        if ($characters === 0) {
            return null;
        }

        if (! $ledger->canAfford($run->user, $run->agent->costLabel())) {
            return 'Stopped: the credit balance ran out. '.count($run->done_ids ?? []).' of '.$run->total.' sections were reviewed.';
        }

        $cap = $controls->capFor($run->user);

        if ($cap !== null && $driver instanceof ReadsReviewContext) {
            $usd = $driver->estimate($run->agent, $characters, $context)['usd'] ?? 0;

            if ($controls->spentThisMonth($run->user) + $usd > $cap) {
                return 'Stopped: the next section would take the account past its AI limit for this month. '.count($run->done_ids ?? []).' of '.$run->total.' sections were reviewed.';
            }
        }

        return null;
    }

    /** Credits this review cost, net of any refund. */
    private function charged(EditorialReview $review): int
    {
        $spends = CreditTransaction::where('subject_type', $review->getMorphClass())->where('subject_id', $review->id)->where('kind', 'spend');
        $spent = -(int) (clone $spends)->sum('amount');
        $refunded = (int) CreditTransaction::whereIn('reverses_id', (clone $spends)->select('id'))->sum('amount');

        return max(0, $spent - $refunded);
    }

    private function record(EditorialRun $run, int $sectionId, ?EditorialReview $review): void
    {
        DB::transaction(function () use ($run, $sectionId, $review) {
            $row = EditorialRun::whereKey($run->id)->lockForUpdate()->first();

            if (in_array($sectionId, $row->done_ids ?? [], true)) {
                return;
            }

            $charged = $review ? $this->charged($review) : 0;

            $row->update([
                'done_ids' => [...($row->done_ids ?? []), $sectionId],
                'findings' => $row->findings + ($review ? $review->findings()->count() : 0),
                'failed' => $row->failed + ($review?->status === 'failed' ? 1 : 0),
                'cost_usd' => (float) $row->cost_usd + (float) ($review?->cost_usd ?? 0),
                'credits_spent' => $row->credits_spent + $charged,
            ]);
        });
    }
}
