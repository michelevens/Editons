<?php

namespace App\Editorial;

use App\Billing\CreditLedger;
use App\Editorial\Contracts\EditorialDriver;
use App\Editorial\Contracts\ReadsReviewContext;
use App\Editorial\Contracts\SimulatesReview;
use App\Editorial\Style\StyleCheck;
use App\Editorial\Style\StyleGuides;
use App\Editorial\Style\StyleRules;
use App\Editorial\Style\Suppression;
use App\Inbox\Inbox;
use App\Models\CreditTransaction;
use App\Models\EditorialFinding;
use App\Models\EditorialReview;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Platform\Ai\AiMeter;
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
 *
 * Review options (ADR — Style guides and review options): the book's style
 * guide reaches the agents that read it, its preferred and banned terms are
 * checked here without a model, and the book's dictionary and turned-off
 * rules drop findings before they are stored, whatever the driver said.
 * A selection reviews only that passage of the chapter.
 */
class ReviewRunner
{
    public function __construct(
        private readonly EditorialDriver $driver,
        private readonly CreditLedger $ledger,
    ) {}

    /**
     * @param  ReviewContext|null  $context  null: the book's own style guide, standard options
     * @param  array{offset: int, length: int}|null  $selection  a passage of the chapter's plain text
     * @param  array<string, mixed>  $options  recorded on the review (scope, run_id…)
     */
    public function run(
        ManuscriptSection $section,
        EditorialAgent $agent,
        ?int $userId = null,
        ?ReviewContext $context = null,
        ?array $selection = null,
        array $options = [],
    ): EditorialReview {
        $context ??= $this->contextFor($section);

        $review = EditorialReview::create([
            'manuscript_section_id' => $section->id,
            'user_id' => $userId,
            'agent' => $agent->value,
            'status' => 'running',
            'driver' => $this->driver->name(),
            // Which snapshot this reviewed. Findings anchor into text that may
            // move; recording the version is what makes drift detectable.
            'section_version' => $section->versions()->max('version_number'),
            // Which rules it was given: the guide version, and a digest of
            // the rules with the book's own over them.
            'style_guide_version_id' => $context->styleVersionId,
            'style_digest' => $context->style !== null ? StyleRules::digest($context->style) : null,
            'options' => array_filter($options + [
                'sensitivity' => $context->sensitivity,
                'focus' => $context->focus,
                'selection' => $selection,
            ], fn ($v) => $v !== null) ?: null,
        ]);

        $charge = null;

        try {
            $full = $section->plainText();
            $text = $selection ? mb_substr($full, $selection['offset'], $selection['length']) : $full;

            if (trim($text) === '') {
                // An empty chapter is not an error, and inventing findings for
                // it would be worse than returning none.
                // Not charged: there was nothing to review, and billing for
                // a no-op is the kind of small dishonesty that loses trust.
                return tap($review)->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);
            }

            // Charged before the provider is called, so two reviews started
            // at once cannot both spend the last of a balance — the ledger
            // takes a row lock to make that true.
            //
            // Never for sample output: heuristics are not the AI review the
            // credits pay for, and production ran on them for weeks.
            $charge = $this->simulated() ? null : $this->charge($userId, $agent, $review);

            $result = $this->callDriver($agent, $text, $section, $userId, $context);

            // The guide's own terms, checked without a model. Reported as
            // the Copy Editor's, which is the agent house style belongs to.
            $readsStyle = $context->style !== null && in_array($agent, ReviewContext::STYLE_AGENTS, true);
            $checked = $readsStyle ? StyleCheck::run($text, $context->style) : [];

            $keep = array_merge(
                $this->kept($result->findings, $agent, $context, 'agent'),
                $this->kept($checked, EditorialAgent::Copy, $context, 'style_check'),
            );

            DB::transaction(function () use ($review, $section, $agent, $result, $keep, $selection, $readsStyle) {
                // Supersede the previous open findings from this same agent.
                // Without this, running a review twice leaves the first run's
                // issues beside the second's and the panel double-counts.
                // A selection supersedes only what was found inside it.
                $stale = EditorialFinding::where('manuscript_section_id', $section->id)
                    ->where('status', 'open')
                    ->where(function ($q) use ($agent, $readsStyle) {
                        $q->where('agent', $agent->value);

                        if ($readsStyle) {
                            $q->orWhere('source', 'style_check');
                        }
                    });

                if ($selection) {
                    $stale->whereBetween('from_offset', [$selection['offset'], $selection['offset'] + $selection['length']]);
                }

                $stale->delete();

                foreach ($keep as [$f, $findingAgent, $rule, $term, $source]) {
                    EditorialFinding::create([
                        'editorial_review_id' => $review->id,
                        'manuscript_section_id' => $section->id,
                        'agent' => $findingAgent->value,
                        'severity' => $f->severity,
                        'title' => $f->title,
                        'detail' => $f->detail,
                        'excerpt' => $f->excerpt,
                        'suggestion' => $f->suggestion,
                        'from_offset' => $f->fromOffset === null ? null : $f->fromOffset + ($selection['offset'] ?? 0),
                        'to_offset' => $f->toOffset === null ? null : $f->toOffset + ($selection['offset'] ?? 0),
                        'rule' => $rule,
                        'term' => $term,
                        'source' => $source,
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

            // Give the credits back. Charging for work that did not happen is
            // the one billing bug an author will never forgive, and the
            // refund is idempotent so a retried handler cannot double it.
            if ($charge) {
                $this->ledger->refund($charge, 'Review failed');
            }
        }

        $review->refresh();

        // News for the book's owner when somebody else ran it; a record for
        // whoever did (see Inbox::reviewFinished).
        Inbox::reviewFinished($review);

        return $review;
    }

    /**
     * Ask the driver, and put the call in the AI usage log either way.
     *
     * Sample output is recorded too (simulated, nothing spent), and so are
     * failures: the console's failure rate comes from these rows.
     */
    private function callDriver(EditorialAgent $agent, string $text, ManuscriptSection $section, ?int $userId, ReviewContext $context): ReviewResult
    {
        $started = hrtime(true);
        $row = [
            'feature' => 'editorial_review',
            'provider' => $this->driver->name(),
            'user' => $userId,
            'book' => $section->book_id,
            'simulated' => $this->simulated(),
        ];

        try {
            // A driver that reads options gets them; one that does not is
            // called as it always was.
            $result = $this->driver instanceof ReadsReviewContext
                ? $this->driver->reviewWith($agent, $text, $context)
                : $this->driver->review($agent, $text);
        } catch (Throwable $e) {
            AiMeter::record(...$row, model: null, status: 'failed', latencyMs: AiMeter::since($started), error: $e->getMessage());

            throw $e;
        }

        AiMeter::record(
            ...$row,
            model: $result->model,
            inputTokens: $result->inputTokens,
            outputTokens: $result->outputTokens,
            cost: $result->costUsd,
            latencyMs: AiMeter::since($started),
        );

        return $result;
    }

    /**
     * The options a review of this section uses when none were given: the
     * book's style guide (its own rules over it), standard sensitivity.
     */
    public function contextFor(ManuscriptSection $section, ?string $focus = null, string $sensitivity = 'standard'): ReviewContext
    {
        $effective = app(StyleGuides::class)->effective($section->book);

        return new ReviewContext(
            style: $effective['configured'] ? $effective['rules'] : null,
            focus: $focus,
            sensitivity: $sensitivity,
            disabledRules: $effective['disabled_rules'],
            styleVersionId: $effective['version']?->id,
        );
    }

    /** The driver, for the planner's estimate. */
    public function driver(): EditorialDriver
    {
        return $this->driver;
    }

    /**
     * The findings worth storing, with their rule, word and source: below
     * the sensitivity's threshold, in the book's dictionary or under a rule
     * it turned off, a finding is dropped.
     *
     * @param  array<int, Finding>  $findings
     * @return list<array{0: Finding, 1: EditorialAgent, 2: string, 3: ?string, 4: string}>
     */
    private function kept(array $findings, EditorialAgent $agent, ReviewContext $context, string $source): array
    {
        $rank = array_flip(EditorialFinding::SEVERITIES);
        $floor = $rank[$context->minimumSeverity()] ?? 2;
        $out = [];

        foreach ($findings as $f) {
            $rule = Suppression::ruleFor($agent, $f);
            $term = Suppression::termFor($f);

            if (($rank[$f->severity] ?? 2) > $floor
                || Suppression::suppressed($term, $rule, $context->dictionary(), $context->disabledRules)) {
                continue;
            }

            $out[] = [$f, $agent, $rule, $term === null ? null : mb_substr($term, 0, 120), $source];
        }

        return $out;
    }

    /** Is this driver's output sample output rather than a model's review? */
    public function simulated(): bool
    {
        return $this->driver instanceof SimulatesReview;
    }

    /**
     * Debit the account for one agent run.
     *
     * A run with no user attached is not charged: there is no account to
     * charge, and guessing one would bill the wrong person. That path is
     * seeders and internal tooling, never a request.
     */
    private function charge(?int $userId, EditorialAgent $agent, EditorialReview $review): ?CreditTransaction
    {
        if (! $userId || ! $user = User::find($userId)) {
            return null;
        }

        return $this->ledger->spend(
            user: $user,
            label: $agent->costLabel(),
            subject: $review,
        );
    }
}
