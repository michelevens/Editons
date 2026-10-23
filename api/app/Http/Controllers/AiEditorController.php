<?php

namespace App\Http\Controllers;

use App\Editorial\EditorialAgent;
use App\Editorial\SuggestionApplier;
use App\Models\Book;
use App\Models\EditorialFinding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Every finding in the book, in one table.
 *
 * The studio's panel answers "what is wrong with this chapter". This answers
 * "what is left before the book is done", which is a different question and
 * the reason it is a screen rather than a wider panel: an author clearing
 * findings works across chapters, not down one.
 */
class AiEditorController extends Controller
{
    public function index(Request $request, Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        $sections = $book->sections()->pluck('title', 'id');

        $query = EditorialFinding::whereIn('manuscript_section_id', $sections->keys());

        if ($agent = $request->string('agent')->toString()) {
            $query->where('agent', $agent);
        }

        // Default to open findings. Resolved ones are history, and loading
        // them by default buries the work that is left.
        $status = $request->string('status')->toString() ?: 'open';

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $findings = $query
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderBy('manuscript_section_id')
            ->get();

        // Counts come from the UNFILTERED set, so a filter chip can say how
        // much is behind it rather than making the author click to find out.
        $all = EditorialFinding::whereIn('manuscript_section_id', $sections->keys())->get();
        $open = $all->where('status', 'open');

        return response()->json([
            'agents' => collect(EditorialAgent::catalogue())
                ->map(fn (array $agent) => $agent + [
                    'open' => $open->where('agent', $agent['value'])->count(),
                ])
                ->all(),
            'counts' => [
                'open' => $open->count(),
                'accepted' => $all->where('status', 'accepted')->count(),
                'dismissed' => $all->where('status', 'dismissed')->count(),
                'critical' => $open->where('severity', 'critical')->count(),
                'all' => $all->count(),
            ],
            'filters' => ['agent' => $agent ?: null, 'status' => $status],
            'findings' => $findings
                ->map(fn (EditorialFinding $finding) => [
                    'id' => $finding->id,
                    'agent' => $finding->agent->value,
                    'agent_label' => $finding->agent->label(),
                    'severity' => $finding->severity,
                    'title' => $finding->title,
                    'detail' => $finding->detail,
                    'excerpt' => $finding->excerpt,
                    'suggestion' => $finding->suggestion,
                    'status' => $finding->status,
                    'section_id' => $finding->manuscript_section_id,
                    'section_title' => $sections[$finding->manuscript_section_id] ?? 'Unknown',
                ])
                ->all(),
        ]);
    }

    /**
     * Reopen a finding an author resolved.
     *
     * The prototype offers this and it is worth keeping: dismissing a finding
     * is a judgement, and judgements get revisited. Reopening is a separate
     * act from resolving, so the resolution record is cleared rather than
     * left pointing at a decision that no longer stands.
     */
    public function reopen(Request $request, EditorialFinding $finding): JsonResponse
    {
        $this->authorize('update', $finding->section);

        // An applied fix is not reopened by relabelling it: the AI's wording
        // would stay in the book while the finding said "open". It comes
        // back out first, through a real undo, or the finding stays applied.
        $section = null;

        if ($finding->status === 'accepted' && $finding->suggestion) {
            try {
                $section = app(SuggestionApplier::class)->undo($finding, $request->user()->id);
            } catch (RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 409);
            }
        }

        $finding->update([
            'status' => 'open',
            'resolved_by' => null,
            'resolved_at' => null,
            'applied_version_id' => null,
        ]);

        return response()->json([
            'finding' => ['id' => $finding->id, 'status' => 'open'],
            // The restored chapter, so an open editor can show it.
            'undone' => $section !== null,
            'content' => $section?->content,
            'word_count' => $section?->word_count,
        ]);
    }
}
