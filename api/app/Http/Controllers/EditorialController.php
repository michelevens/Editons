<?php

namespace App\Http\Controllers;

use App\Billing\Entitlements;
use App\Editorial\BookDirector;
use App\Editorial\DriverFactory;
use App\Editorial\EditorialAgent;
use App\Editorial\ManuscriptMemory;
use App\Editorial\ReviewRunner;
use App\Editorial\SuggestionApplier;
use App\Journey\Coach;
use App\Models\Book;
use App\Models\EditorialFinding;
use App\Models\ManuscriptSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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
        ]);

        $agent = EditorialAgent::tryFrom($data['agent']);
        abort_unless($agent, 422, 'Unknown editorial agent.');

        // Both checks before any work starts, so an author is told up front
        // rather than watching a review begin and then fail. The spend itself
        // re-checks the balance under a lock.
        $this->entitlements->assertCanRunAgent($request->user(), $agent);

        // Sample output is free, so an empty balance does not stop it.
        if (! $this->runner->simulated()) {
            $this->entitlements->assertCanAfford($request->user(), $agent->costLabel());
        }

        $review = $this->runner->run($section, $agent, $request->user()->id);

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
                'agent_label' => $f->agent->label(),
                'severity' => $f->severity,
                'title' => $f->title,
                'detail' => $f->detail,
                'excerpt' => $f->excerpt,
                'suggestion' => $f->suggestion,
                'status' => $f->status,
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
