<?php

namespace App\Http\Controllers;

use App\Editorial\EditorialAgent;
use App\Editorial\ReviewRunner;
use App\Models\EditorialFinding;
use App\Models\ManuscriptSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EditorialController extends Controller
{
    public function __construct(private readonly ReviewRunner $runner) {}

    /** Run one agent over a section. */
    public function review(Request $request, ManuscriptSection $section): JsonResponse
    {
        $this->authorize('update', $section);

        $data = $request->validate([
            'agent' => ['required', 'string'],
        ]);

        $agent = EditorialAgent::tryFrom($data['agent']);
        abort_unless($agent, 422, 'Unknown editorial agent.');

        $review = $this->runner->run($section, $agent, $request->user()->id);

        return response()->json([
            'review' => [
                'id' => $review->id,
                'agent' => $review->agent->value,
                'status' => $review->status,
                'error' => $review->error,
            ],
            'findings' => $this->findingsFor($section),
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

        $finding->update([
            'status' => $data['status'],
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
        ]);

        return response()->json(['findings' => $this->findingsFor($finding->section)]);
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
