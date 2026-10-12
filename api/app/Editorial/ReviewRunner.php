<?php

namespace App\Editorial;

use App\Editorial\Contracts\EditorialDriver;
use App\Models\EditorialFinding;
use App\Models\EditorialReview;
use App\Models\ManuscriptSection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs one agent over one section and stores the result.
 *
 * Synchronous for now. ARCHITECTURE.md calls for a background job queue, and
 * that is right once a real provider is answering in seconds — but queueing
 * work that completes in milliseconds would add a worker process, a failed-job
 * table and a polling UI to buy nothing. The seam is the driver, not the
 * dispatch: moving this into a queued job later changes this class and
 * nothing above it.
 */
class ReviewRunner
{
    public function __construct(private readonly EditorialDriver $driver) {}

    public function run(
        ManuscriptSection $section,
        EditorialAgent $agent,
        ?int $userId = null,
    ): EditorialReview {
        $review = EditorialReview::create([
            'manuscript_section_id' => $section->id,
            'user_id' => $userId,
            'agent' => $agent->value,
            'status' => 'running',
            'driver' => $this->driver->name(),
            // Which snapshot this reviewed. Findings anchor into text that may
            // move; recording the version is what makes drift detectable.
            'section_version' => $section->versions()->max('version_number'),
        ]);

        try {
            $text = $section->plainText();

            if (trim($text) === '') {
                // An empty chapter is not an error, and inventing findings for
                // it would be worse than returning none.
                return tap($review)->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);
            }

            $result = $this->driver->review($agent, $text);

            DB::transaction(function () use ($review, $section, $agent, $result) {
                // Supersede the previous open findings from this same agent.
                // Without this, running a review twice leaves the first run's
                // issues beside the second's and the panel double-counts.
                EditorialFinding::where('manuscript_section_id', $section->id)
                    ->where('agent', $agent->value)
                    ->where('status', 'open')
                    ->delete();

                foreach ($result->findings as $f) {
                    EditorialFinding::create([
                        'editorial_review_id' => $review->id,
                        'manuscript_section_id' => $section->id,
                        'agent' => $agent->value,
                        'severity' => $f->severity,
                        'title' => $f->title,
                        'detail' => $f->detail,
                        'excerpt' => $f->excerpt,
                        'suggestion' => $f->suggestion,
                        'from_offset' => $f->fromOffset,
                        'to_offset' => $f->toOffset,
                    ]);
                }

                $review->update([
                    'status' => 'completed',
                    'model' => $result->model,
                    'input_tokens' => $result->inputTokens,
                    'output_tokens' => $result->outputTokens,
                    'cost_usd' => $result->costUsd,
                    'completed_at' => now(),
                ]);
            });
        } catch (Throwable $e) {
            // Record the failure rather than swallowing it. A review that
            // silently produced nothing is indistinguishable from a clean
            // chapter, which is the worst possible reading.
            $review->update([
                'status' => 'failed',
                'error' => mb_substr($e->getMessage(), 0, 500),
                'completed_at' => now(),
            ]);
        }

        return $review->refresh();
    }
}
