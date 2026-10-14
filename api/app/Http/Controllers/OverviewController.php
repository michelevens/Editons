<?php

namespace App\Http\Controllers;

use App\Editorial\ConsistencyIndex;
use App\Models\Book;
use App\Models\Citation;
use App\Models\EditorialFinding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The book's home screen.
 *
 * Every number here is counted from the database. The prototype's version
 * reads the same shapes from its in-memory state, and the point of the screen
 * is that an author can trust it — a progress checklist that reported
 * something other than the real state would be worse than no checklist.
 */
class OverviewController extends Controller
{
    public function __construct(private readonly ConsistencyIndex $consistency) {}

    public function show(Book $book): Response
    {
        $this->authorize('view', $book);

        $sectionIds = $book->sections()->pluck('id');

        $openFindings = EditorialFinding::whereIn('manuscript_section_id', $sectionIds)
            ->where('status', 'open')
            ->get();

        $citations = Citation::whereIn('manuscript_section_id', $sectionIds)->get();
        $chapters = $book->sections()->where('type', '!=', 'part')->get();

        return Inertia::render('Books/Overview', [
            'book' => [
                'id' => $book->id,
                'title' => $book->title,
                'subtitle' => $book->subtitle,
                'genre' => $book->genre,
                'status' => $book->status,
                'word_count' => $book->word_count,
                'target_words' => $book->target_words,
                'updated_at' => $book->updated_at->diffForHumans(),
                'cover' => $book->coverFor(),
            ],
            'stats' => [
                'words' => $book->word_count,
                'chapters' => $chapters->count(),
                'approved' => $chapters->where('status', 'approved')->count(),
                'open_findings' => $openFindings->count(),
                'agents_with_findings' => $openFindings->pluck('agent')->unique()->count(),
                'critical' => $openFindings->where('severity', 'critical')->count(),
                'sources' => $book->sources()->count(),
                'unverified_sources' => $book->sources()->where('verification', '!=', 'verified')->count(),
                'claims_needing_source' => $citations->where('status', 'needs_source')->count(),
                'consistency_issues' => count($this->consistency->scan($book)),
            ],
            // The checklist is derived, never stored. A stored flag drifts from
            // the thing it claims about the moment anything else changes.
            'checklist' => $this->checklist($book, $chapters, $openFindings, $citations),
        ]);
    }

    public function update(Request $request, Book $book): RedirectResponse
    {
        $this->authorize('update', $book);

        $book->update($request->validate([
            'target_words' => ['nullable', 'integer', 'min:1000', 'max:2000000'],
        ]));

        return back();
    }

    /**
     * Steps toward a finished book, each with where it is fixed.
     *
     * Only steps whose state this application can actually observe. The
     * prototype also tracks a cover, publishing metadata and store
     * submissions; none of those have an engine here, and a checklist row
     * that can never turn green is worse than an absent one.
     *
     * @return array<int, array<string, mixed>>
     */
    private function checklist($book, $chapters, $findings, $citations): array
    {
        $written = $chapters->where('word_count', '>', 0);

        return [
            [
                'label' => 'Draft every chapter',
                'done' => $chapters->isNotEmpty() && $written->count() === $chapters->count(),
                'detail' => "{$written->count()} of {$chapters->count()} have words",
                'route' => 'manuscript',
            ],
            [
                'label' => 'Clear critical findings',
                'done' => $findings->where('severity', 'critical')->isEmpty(),
                'detail' => $findings->where('severity', 'critical')->count().' critical open',
                'route' => 'ai',
            ],
            [
                'label' => 'Source every claim',
                'done' => $citations->where('status', 'needs_source')->isEmpty(),
                'detail' => $citations->where('status', 'needs_source')->count().' awaiting a source',
                'route' => 'research',
            ],
            [
                'label' => 'Verify every source',
                'done' => $book->sources()->where('verification', '!=', 'verified')->doesntExist(),
                'detail' => $book->sources()->where('verification', '!=', 'verified')->count().' unverified',
                'route' => 'research',
            ],
            [
                'label' => 'Settle the vocabulary',
                'done' => count($this->consistency->scan($book)) === 0,
                'detail' => count($this->consistency->scan($book)).' disagreements across chapters',
                'route' => 'terms',
            ],
            [
                'label' => 'Approve every chapter',
                'done' => $chapters->isNotEmpty()
                    && $chapters->where('status', 'approved')->count() === $chapters->count(),
                'detail' => $chapters->where('status', 'approved')->count()." of {$chapters->count()} approved",
                'route' => 'workflow',
            ],
        ];
    }
}
