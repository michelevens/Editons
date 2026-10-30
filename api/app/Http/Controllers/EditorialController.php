<?php

namespace App\Http\Controllers;

use App\Billing\Entitlements;
use App\Editorial\BookDirector;
use App\Editorial\DriverFactory;
use App\Editorial\EditorialAgent;
use App\Editorial\ManuscriptMemory;
use App\Editorial\ReviewContext;
use App\Editorial\ReviewPlanner;
use App\Editorial\ReviewRunner;
use App\Editorial\SuggestionApplier;
use App\Jobs\RunEditorialReview;
use App\Models\EditorialReview;
use App\Models\EditorialRun;
use Illuminate\Database\UniqueConstraintViolationException;
use App\Journey\Coach;
use App\Models\Book;
use App\Models\EditorialFinding;
use App\Models\ManuscriptSection;
use App\Platform\Ai\AiControls;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class EditorialController extends Controller
{
    public function __construct(
        private readonly ReviewRunner $runner,
        private readonly Entitlements $entitlements,
        private readonly BookDirector $director,
        private readonly Coach $coach,
    ) {}

    /**
     * Review the whole book.
     *
     * The thing the seven per-section agents cannot do. Each of them takes
     * one chapter's text, so the Developmental Editor — whose job is
     * "structure, pacing and argument" — judged a chapter against a
     * hardcoded word range and could not see the nineteen beside it.
     *
     * Free, and deliberately so. config/billing.php prices book_review at 40
     * credits and nothing has ever spent it; charging that for heuristics
     * over an index would be charging for arithmetic. The priced path stays
     * reserved for a provider reading the actual prose.
     *
     * Findings are returned rather than stored: they belong to the book as it
     * is right now, and a structural observation saved against a chapter
     * would outlive the shape that produced it.
     */
    public function direct(Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        // Cached by the book's content: the review is deterministic, and
        // recomputing it parsed every chapter on each panel open (3.9 s on
        // a 150k-word book). Any edit changes the fingerprint, so a stale
        // review is never served; old entries simply expire.
        $key = 'director:'.$book->id.':'.ManuscriptMemory::fingerprint($book);

        $payload = Cache::remember($key, now()->addDay(), function () use ($book) {
            $memory = ManuscriptMemory::of($book);

            return [
                'findings' => array_map(fn ($f) => [
                    'severity' => $f->severity,
                    'title' => $f->title,
                    'detail' => $f->detail,
                ], $this->director->review($book)),
                // Said plainly, because an empty list has two very different
                // meanings and the panel must not show the wrong one.
                'enough_to_review' => $memory->isSubstantial(),
            ];
        });

        return response()->json($payload);
    }

    /** Run one agent over a section. */
    public function review(Request $request, ManuscriptSection $section): JsonResponse
    {
        $this->authorize('update', $section);

        $data = $request->validate([
            'agent' => ['required', 'string'],
            // Optional review options (ADR — Style guides and review options).
            'focus' => ['nullable', 'string', 'max:'.ReviewContext::MAX_FOCUS],
            'sensitivity' => ['nullable', 'string', 'in:'.implode(',', ReviewContext::SENSITIVITIES)],
        ]);

        $agent = EditorialAgent::tryFrom($data['agent']);
        abort_unless($agent, 422, 'Unknown editorial agent.');

        // Every check before any work starts, so an author is told up front
        // rather than watching a review begin and then fail. The spend itself
        // re-checks the balance under a lock. The kill switch comes first:
        // a paused review charges nothing and calls nobody.
        app(AiControls::class)->assertAllowed('editorial_review', $request->user());
        $this->entitlements->assertCanRunAgent($request->user(), $agent);

        // Sample output is free, so an empty balance does not stop it.
        if (! $this->runner->simulated()) {
            $this->entitlements->assertCanAfford($request->user(), $agent->costLabel());
        }

        $focus = trim((string) ($data['focus'] ?? ''));
        $context = $this->runner->contextFor($section, $focus === '' ? null : $focus, $data['sensitivity'] ?? 'standard');
        $review = $this->runner->run($section, $agent, $request->user()->id, $context, options: ['scope' => 'chapter']);

        return response()->json([
            'review' => [
                'id' => $review->id,
                'agent' => $review->agent->value,
                'status' => $review->status,
                'error' => $review->error,
                // Heuristics, not a model: the panel labels the result as
                // sample output, and nothing was charged.
                'simulated' => $this->runner->simulated(),
            ],
            'findings' => $this->findingsFor($section),
            // Returned so the credit strip updates with the result rather
            // than needing a second request to notice the spend.
            'billing' => $this->entitlements->summaryFor($request->user()->fresh()),
        ]);
    }

    /**
     * What a review would cover and cost, before it runs: sections, tokens,
     * USD from the gateway's prices, credits, and whether it needs approval
     * (ReviewPlanner). Nothing is spent or sent.
     */
    public function estimate(Request $request, Book $book, ReviewPlanner $planner): JsonResponse
    {
        $this->authorize('update', $book);
        $data = $request->validate(ReviewPlanner::rules());

        return response()->json(['estimate' => $planner->estimate($planner->plan($book, $data), $request->user())]);
    }

    /**
     * Run one agent over a selection, a chapter, a range or the whole book.
     *
     * Every refusal before any work: the kill switch, the plan, the credit
     * balance and the monthly AI cap against the estimate, and approval for
     * a large whole-book run. A selection or one chapter (up to
     * QUEUE_ABOVE sections) runs here; a range or the whole book is queued
     * (RunEditorialReview) and the response is the run to poll: a real
     * provider over forty chapters would outlive the web request while
     * spending credits.
     */
    public function reviewBook(Request $request, Book $book, ReviewPlanner $planner): JsonResponse
    {
        $this->authorize('update', $book);
        $data = $request->validate(ReviewPlanner::rules());
        $user = $request->user();

        app(AiControls::class)->assertAllowed('editorial_review', $user);
        $plan = $planner->plan($book, $data);
        $this->entitlements->assertCanRunAgent($user, $plan['agent']);
        $estimate = $planner->estimate($plan, $user);

        if ($estimate['needs_approval'] && ! ($data['approved'] ?? false)) {
            return response()->json(['message' => __('A whole-book review of this size needs your approval of the estimate first.'), 'needs_approval' => true, 'estimate' => $estimate], 409);
        }

        $planner->enforce($estimate, (bool) ($data['approved'] ?? false));

        if (in_array($plan['scope'], ['range', 'book'], true) || count($plan['items']) > self::QUEUE_ABOVE) {
            return $this->queue($book, $plan, $estimate, $user);
        }

        $runId = (string) Str::uuid();
        $results = [];

        foreach ($plan['items'] as $item) {
            $review = $this->runner->run($item['section'], $plan['agent'], $user->id, $plan['context'], $item['selection'], ['scope' => $plan['scope'], 'run_id' => $runId]);

            $results[] = [
                'section_id' => $item['section']->id,
                'title' => $item['section']->title,
                'status' => $review->status,
                'error' => $review->error,
                'findings' => $review->findings()->count(),
            ];
        }

        return response()->json([
            'run_id' => $runId,
            'agent' => $plan['agent']->value,
            'scope' => $plan['scope'],
            'reviews' => $results,
            'findings' => array_sum(array_column($results, 'findings')),
            'failed' => count(array_filter($results, fn ($r) => $r['status'] === 'failed')),
            'simulated' => $this->runner->simulated(),
            'estimate' => $estimate,
            'billing' => $this->entitlements->summaryFor($user->fresh()),
        ]);
    }

    /** Runs over more sections than this go to the queue. */
    public const QUEUE_ABOVE = 3;

    /** Queue a run; one active whole-book run per book. */
    private function queue(Book $book, array $plan, array $estimate, $user): JsonResponse
    {
        if ($plan['scope'] === 'book' && ($active = EditorialRun::where('book_id', $book->id)->where('scope', 'book')->whereIn('status', EditorialRun::ACTIVE)->first())) {
            return response()->json(['message' => __('A whole-book review of this book is already running.'), 'run' => $active->progress()], 409);
        }

        try {
            $run = EditorialRun::create([
                'uuid' => (string) Str::uuid(),
                'book_id' => $book->id,
                'user_id' => $user->id,
                'agent' => $plan['agent']->value,
                'scope' => $plan['scope'],
                'status' => 'queued',
                'section_ids' => array_map(fn ($i) => $i['section']->id, $plan['items']),
                'done_ids' => [],
                'total' => count($plan['items']),
                'context' => EditorialRun::freeze($plan['context']),
                'estimate' => $estimate,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two requests at once: the index holds the line.
            return response()->json(['message' => __('A whole-book review of this book is already running.')], 409);
        }

        RunEditorialReview::dispatch($run->id);

        return response()->json([
            'queued' => true,
            'run' => $run->fresh()->progress(),
            'simulated' => $this->runner->simulated(),
            'estimate' => $estimate,
        ], 202);
    }

    /**
     * A queued run's progress: sections done of total, findings and cost so
     * far, and each finished section. Only for someone who may open the
     * book.
     */
    public function run(EditorialRun $run): JsonResponse
    {
        $this->authorize('view', $run->book);

        $titles = ManuscriptSection::whereIn('id', $run->section_ids)->pluck('title', 'id');

        return response()->json([
            'run' => $run->progress(),
            'reviews' => EditorialReview::where('options->run_id', $run->uuid)->orderBy('id')->withCount('findings')->get()
                ->map(fn (EditorialReview $r) => ['section_id' => $r->manuscript_section_id, 'title' => $titles[$r->manuscript_section_id] ?? null, 'status' => $r->status, 'findings' => $r->findings_count])
                ->all(),
        ]);
    }

    /** The book's active run, if any, for a screen opened mid-run. */
    public function activeRun(Book $book): JsonResponse
    {
        $this->authorize('view', $book);
        $run = EditorialRun::where('book_id', $book->id)->whereIn('status', EditorialRun::ACTIVE)->latest('id')->first();

        return response()->json(['run' => $run?->progress()]);
    }

    /**
     * Cancel: the section under review finishes (it is already paid for and
     * its findings are kept); no other starts.
     */
    public function cancelRun(Request $request, EditorialRun $run): JsonResponse
    {
        $this->authorize('update', $run->book);

        $updated = EditorialRun::whereKey($run->id)->whereIn('status', EditorialRun::ACTIVE)->update([
            'status' => 'cancelled',
            'stop_reason' => 'Cancelled by '.$request->user()->name.'.',
            'finished_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['cancelled' => $updated > 0, 'run' => $run->fresh()->progress()]);
    }

    /** Everything currently open or resolved for a section. */
    public function findings(ManuscriptSection $section): JsonResponse
    {
        $this->authorize('view', $section);

        return response()->json([
            'findings' => $this->findingsFor($section),
            /*
             * Coaching for the chapter the author is actually in.
             *
             * Coach::forSection() was written and tested and nothing called
             * it: the book-level notes shipped to the guided screen while the
             * per-chapter ones, which are the only kind useful *while
             * writing*, reached nobody.
             *
             * Carried on the findings response rather than its own endpoint,
             * because the editor already fetches this every time a chapter
             * opens. A second request would make the same trip for data this
             * one can hand over for nothing.
             *
             * Still gated on the assistance level, so "I write" stays silent.
             */
            'coaching' => $section->book->assistance === 'coach'
                ? $this->coach->forSection($section)
                : [],
        ]);
    }

    /**
     * Accept a finding's suggested replacement, or dismiss it.
     *
     * Accepting records the decision; it does NOT rewrite the manuscript
     * here. The client applies the replacement in the editor and the normal
     * save path persists it, so an AI edit travels through exactly the same
     * versioned write as anything the author types — and is just as
     * reversible.
     */
    public function resolve(Request $request, EditorialFinding $finding): JsonResponse
    {
        $this->authorize('update', $finding->section);

        $data = $request->validate([
            'status' => ['required', 'in:accepted,dismissed'],
        ]);

        // Only an open finding is decided here. Re-marking an applied fix
        // "dismissed" left the AI's wording in the book while the record
        // said the author had turned it down. Taking a fix back out is the
        // reopen endpoint's undo, which restores the text.
        if ($finding->status !== 'open') {
            return response()->json([
                'message' => $finding->status === 'accepted'
                    ? 'This fix is already applied. Undo it to take it back out.'
                    : 'This finding was already dismissed. Reopen it first.',
                'findings' => $this->findingsFor($finding->section),
            ], 409);
        }

        // Accepting a suggestion must actually change the manuscript.
        // Recording the decision and leaving the prose alone would make the
        // panel agree with the author while the document disagreed.
        $applied = null;
        $appliedVersion = null;

        if ($data['status'] === 'accepted' && $finding->suggestion) {
            try {
                $section = app(SuggestionApplier::class)->apply($finding, $request->user()->id);
                $applied = true;

                // The snapshot the fix replaced, for a later undo.
                $appliedVersion = $section->versions()
                    ->where('reason', 'ai_suggestion')
                    ->value('id');
            } catch (\RuntimeException $e) {
                // The passage moved or is ambiguous. Say so and leave the
                // finding open, rather than closing it over an edit that did
                // not happen.
                return response()->json([
                    'message' => $e->getMessage(),
                    'findings' => $this->findingsFor($finding->section),
                ], 409);
            }
        }

        $finding->update([
            'status' => $data['status'],
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
            'applied_version_id' => $appliedVersion,
        ]);

        $section = $finding->section->fresh();

        return response()->json([
            'findings' => $this->findingsFor($section),
            'applied' => $applied,
            // The client needs the rewritten chapter, or the editor goes on
            // showing the text as it was before the fix.
            'content' => $applied ? $section->content : null,
            'word_count' => $applied ? $section->word_count : null,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function findingsFor(ManuscriptSection $section): array
    {
        $text = $section->plainText();

        return EditorialFinding::where('manuscript_section_id', $section->id)
            ->with('review:id,driver')
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderBy('from_offset')
            ->get()
            ->map(fn (EditorialFinding $f) => [
                'id' => $f->id,
                'agent' => $f->agent->value,
                'agent_label' => $f->agent->displayLabel(),
                'severity' => $f->severity,
                'title' => $f->title,
                'detail' => $f->detail,
                'excerpt' => $f->excerpt,
                'suggestion' => $f->suggestion,
                'status' => $f->status,
                'rule' => $f->rule,
                'term' => $f->term,
                'source' => $f->source,
                // Computed, not stored: the document moves after a review
                // runs, and an author should see which findings no longer
                // match their text rather than chasing deleted sentences.
                'stale' => $f->isStale($text),
                // Produced by a driver that runs heuristics, not a model.
                'simulated' => DriverFactory::simulated($f->review?->driver),
            ])
            ->all();
    }
}
