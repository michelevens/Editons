<?php

namespace App\Http\Controllers;

use App\Editorial\EditorialAgent;
use App\Editorial\ReviewPlanner;
use App\Editorial\Style\StyleGuides;
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
 *
 * Triage (ADR — Style guides and review options): filter by status, agent,
 * severity, chapter and rule; sort; accept or dismiss many at once (each
 * accept still goes through SuggestionApplier, a versioned write the author
 * asked for, never a silent rewrite); and "never flag this again", which
 * adds the word to the book's dictionary or turns the rule off for the
 * book, with an undo.
 */
class AiEditorController extends Controller
{
    public const SORTS = ['severity', 'chapter', 'agent', 'newest'];

    public function index(Request $request, Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        $order = app(ReviewPlanner::class)->readingOrder($book);
        $sections = $book->sections()->pluck('title', 'id');
        // Chapter order for sorting: reading order, then anything else.
        $position = array_flip($order->pluck('id')->all());

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

        $severity = $request->string('severity')->toString();

        if (in_array($severity, EditorialFinding::SEVERITIES, true)) {
            $query->where('severity', $severity);
        } else {
            $severity = null;
        }

        $section = $request->integer('section') ?: null;

        if ($section) {
            $query->where('manuscript_section_id', $section);
        }

        $rule = $request->string('rule')->toString() ?: null;

        if ($rule) {
            $query->where('rule', $rule);
        }

        $sort = in_array($request->string('sort')->toString(), self::SORTS, true) ? $request->string('sort')->toString() : 'severity';
        $rank = array_flip(EditorialFinding::SEVERITIES);

        $findings = $query->get()->sortBy(fn (EditorialFinding $f) => match ($sort) {
            'chapter' => [$position[$f->manuscript_section_id] ?? PHP_INT_MAX, $f->from_offset ?? 0, $rank[$f->severity] ?? 3],
            'agent' => [$f->agent->value, $rank[$f->severity] ?? 3, $position[$f->manuscript_section_id] ?? PHP_INT_MAX],
            'newest' => [-$f->id],
            default => [$rank[$f->severity] ?? 3, $position[$f->manuscript_section_id] ?? PHP_INT_MAX, $f->from_offset ?? 0],
        })->values();

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
                'severity' => collect(EditorialFinding::SEVERITIES)->mapWithKeys(fn ($s) => [$s => $open->where('severity', $s)->count()]),
            ],
            // Every chapter in reading order, with what is open in it, for
            // the chapter filter and the review scope.
            'sections' => $order->map(fn ($s) => ['id' => $s->id, 'title' => $s->title, 'open' => $open->where('manuscript_section_id', $s->id)->count()])->values(),
            'rules' => $open->whereNotNull('rule')->groupBy('rule')
                ->map(fn ($group, $key) => ['rule' => $key, 'label' => $group->first()->title, 'open' => $group->count()])
                ->sortByDesc('open')->values(),
            'filters' => ['agent' => $agent ?: null, 'status' => $status, 'severity' => $severity, 'section' => $section, 'rule' => $rule, 'sort' => $sort],
            'findings' => $findings
                ->map(fn (EditorialFinding $finding) => [
                    'id' => $finding->id,
                    'agent' => $finding->agent->value,
                    'agent_label' => $finding->agent->displayLabel(),
                    'severity' => $finding->severity,
                    'title' => $finding->title,
                    'detail' => $finding->detail,
                    'excerpt' => $finding->excerpt,
                    'suggestion' => $finding->suggestion,
                    'status' => $finding->status,
                    'rule' => $finding->rule,
                    'term' => $finding->term,
                    'source' => $finding->source,
                    'section_id' => $finding->manuscript_section_id,
                    'section_title' => $sections[$finding->manuscript_section_id] ?? 'Unknown',
                ])
                ->all(),
        ]);
    }

    /**
     * Accept or dismiss many findings at once, after the author confirmed
     * it. Each is decided as one would be: an accept with a replacement goes
     * through SuggestionApplier (its own version, undoable), and one that
     * cannot be applied (the passage moved, or occurs twice) stays open and
     * is reported. Only open findings of this book are touched.
     */
    public function bulk(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'action' => ['required', 'in:accept,dismiss'],
            'confirmed' => ['accepted'],
        ]);

        $findings = EditorialFinding::whereIn('id', $data['ids'])
            ->whereIn('manuscript_section_id', $book->sections()->select('id'))
            ->get();
        $applier = app(SuggestionApplier::class);
        $user = $request->user();
        $done = 0;
        $skipped = [];

        foreach ($findings as $finding) {
            if ($finding->status !== 'open') {
                $skipped[] = ['id' => $finding->id, 'message' => __('Already decided.')];

                continue;
            }

            $appliedVersion = null;

            if ($data['action'] === 'accept' && $finding->suggestion) {
                try {
                    $section = $applier->apply($finding, $user->id);
                    $appliedVersion = $section->versions()->where('reason', 'ai_suggestion')->value('id');
                } catch (RuntimeException $e) {
                    $skipped[] = ['id' => $finding->id, 'message' => $e->getMessage()];

                    continue;
                }
            }

            $finding->update([
                'status' => $data['action'] === 'accept' ? 'accepted' : 'dismissed',
                'dismissal' => $data['action'] === 'dismiss' ? 'bulk' : null,
                'resolved_by' => $user->id,
                'resolved_at' => now(),
                'applied_version_id' => $appliedVersion,
            ]);
            $done++;
        }

        // Ids that are not this book's are not reported back one by one:
        // whether a finding exists elsewhere is not this book's business.
        return response()->json(['done' => $done, 'skipped' => $skipped, 'not_found' => count($data['ids']) - $findings->count()]);
    }

    /**
     * "Never flag this again": the finding's word into the book's
     * dictionary, or its rule turned off for the book. Its open findings
     * of the same word or rule are dismissed; the response says how to
     * undo it.
     */
    public function neverFlag(Request $request, EditorialFinding $finding, StyleGuides $guides): JsonResponse
    {
        $this->authorize('update', $finding->section);
        $data = $request->validate(['mode' => ['required', 'in:word,rule']]);
        $book = $finding->section->book;

        if ($data['mode'] === 'word') {
            abort_unless($finding->term, 422, 'This finding is not about one word.');
            $count = $guides->neverFlagWord($book, $request->user(), $finding->term);

            return response()->json(['mode' => 'word', 'value' => $finding->term, 'dismissed' => $count]);
        }

        abort_unless($finding->rule, 422, 'This finding has no rule to turn off.');
        $count = $guides->disableRule($book, $request->user(), $finding->rule);

        return response()->json(['mode' => 'rule', 'value' => $finding->rule, 'dismissed' => $count]);
    }

    /** Undo "never flag this again": the word or rule back, its findings reopened. */
    public function unsuppress(Request $request, Book $book, StyleGuides $guides): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'in:word,rule'],
            'value' => ['required', 'string', 'max:120'],
        ]);

        $count = $data['mode'] === 'word'
            ? $guides->unflagWord($book, $request->user(), $data['value'])
            : $guides->enableRule($book, $request->user(), $data['value']);

        return response()->json(['reopened' => $count]);
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
            'dismissal' => null,
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
