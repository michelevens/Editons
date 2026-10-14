<?php

namespace App\Http\Controllers;

use App\Billing\Entitlements;
use App\Editorial\EditorialAgent;
use App\Editorial\ReviewRunner;
use App\Editorial\SuggestionApplier;
use App\Models\EditorialFinding;
use App\Models\ManuscriptSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EditorialController extends Controller
{
    public function __construct(
        private readonly ReviewRunner $runner,
        private readonly Entitlements $entitlements,
    ) {}

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
        $this->entitlements->assertCanAfford($request->user(), $agent->costLabel());

        $review = $this->runner->run($section, $agent, $request->user()->id);

        return response()->json([
            'review' => [
                'id' => $review->id,
                'agent' => $review->agent->value,
                'status' => $review->status,
                'error' => $review->error,
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

        return response()->json(['findings' => $this->findingsFor($section)]);
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

        // Accepting a suggestion must actually change the manuscript.
        // Recording the decision and leaving the prose alone would make the
        // panel agree with the author while the document disagreed.
        $applied = null;

        if ($data['status'] === 'accepted' && $finding->suggestion) {
            try {
                app(SuggestionApplier::class)->apply($finding, $request->user()->id);
                $applied = true;
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
            ])
            ->all();
    }
}
