<?php

namespace App\Billing;

use App\Models\CreditTransaction;
use App\Models\EditorialReview;
use App\Models\EditorialRun;
use App\Models\ResearchRun;

/**
 * Credits taken for AI work that never finished go back (ADR — AI writer
 * round 2).
 *
 * A review is charged before the provider is called, and a research run
 * holds the most it may take. Both are given back when the work fails —
 * but only if the code that catches the failure gets to run. A request
 * killed at its time limit, or a worker that crashed, ran no catch: the
 * walkthrough of book 833 lost 33 credits on each "Fact-check with web
 * search" that way (a research hold of 25 and a review of 8).
 *
 * Whatever such a process leaves behind is still "running". Past
 * `billing.metered.stale_after_minutes` it cannot be (no AI call takes that
 * long), so it is failed here, saying why, and every credit it took is
 * refunded through the ledger's one-refund-per-spend rule: a sweep that
 * races a late finish, or runs twice, gives nothing back twice.
 */
class StaleHolds
{
    public function __construct(private readonly CreditLedger $ledger) {}

    /** @return array{reviews: int, research: int, runs: int} */
    public function sweep(): array
    {
        $cutoff = now()->subMinutes(max(1, (int) config('billing.metered.stale_after_minutes', 45)));
        $counts = ['reviews' => 0, 'research' => 0, 'runs' => 0];

        EditorialReview::where('status', 'running')->where('updated_at', '<', $cutoff)->lazyById(100)
            ->each(function (EditorialReview $review) use (&$counts) {
                $counts['reviews'] += $this->abandonReview($review) ? 1 : 0;
            });

        ResearchRun::where('status', 'running')->where('updated_at', '<', $cutoff)->lazyById(100)
            ->each(function (ResearchRun $run) use (&$counts) {
                $counts['research'] += $this->abandonResearch($run) ? 1 : 0;
            });

        $counts['runs'] = EditorialRun::whereIn('status', EditorialRun::ACTIVE)->where('updated_at', '<', $cutoff)->update([
            'status' => 'failed',
            'stop_reason' => __('The review stopped unexpectedly. Credits for any unfinished section were returned.'),
            'finished_at' => now(),
            'updated_at' => now(),
        ]);

        return $counts;
    }

    /**
     * Fail a review that is still running and refund what it was charged.
     * False when it had already finished (nothing to do).
     */
    public function abandonReview(EditorialReview $review, ?string $reason = null): bool
    {
        $updated = EditorialReview::whereKey($review->id)->where('status', 'running')->update([
            'status' => 'failed',
            'error' => $reason ?? __('The review stopped before it finished. Nothing was charged.'),
            'completed_at' => now(),
            'updated_at' => now(),
        ]);

        // Refunded even when another hand failed it first (the refund is
        // idempotent, and a failed review must never stay charged), but
        // never once it completed: finished work keeps its charge.
        if (EditorialReview::whereKey($review->id)->value('status') === 'failed') {
            $this->refundSubject($review, 'Review stopped');
        }

        return $updated > 0;
    }

    /** Fail a research run that is still running and release its hold. */
    public function abandonResearch(ResearchRun $run): bool
    {
        $updated = ResearchRun::whereKey($run->id)->where('status', 'running')->update([
            'status' => 'failed',
            'error' => __('AI research stopped before it finished. Nothing was charged.'),
            'updated_at' => now(),
        ]);

        if (ResearchRun::whereKey($run->id)->value('status') === 'failed') {
            $this->refundSubject($run, 'Research stopped');
        }

        return $updated > 0;
    }

    /** Refund every spend recorded against this piece of work. */
    private function refundSubject($subject, string $reason): void
    {
        CreditTransaction::where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->where('kind', 'spend')
            ->get()
            ->each(fn (CreditTransaction $spend) => $this->ledger->refund($spend, $reason));
    }
}
