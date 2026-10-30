<?php

namespace App\Http\Controllers;

use App\Editorial\ConsistencyIndex;
use App\I18n\Locales;
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
            // Where the book stands at each store (Publish in three steps).
            'channels' => $book->channels()->orderBy('id')->get()->map(fn ($c) => [
                'channel' => $c->channel,
                'label' => $c->label(),
                'status' => $c->status,
                'external_id' => $c->external_id,
                'submitted_at' => $c->submitted_at?->toFormattedDateString(),
            ])->all(),
            'store' => ($product = $book->product()->first()) ? [
                'state' => $product->isTakenDown() ? 'taken_down' : ($product->isPublished() ? 'live' : 'draft'),
                'paperback' => $product->paperback_price_cents !== null,
            ] : null,
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
        $disagreements = count($this->consistency->scan($book));
        $unverified = $book->sources()->where('verification', '!=', 'verified')->count();

        return [
            [
                'label' => Locales::t('overview.check_draft'),
                'done' => $chapters->isNotEmpty() && $written->count() === $chapters->count(),
                'detail' => Locales::t('overview.check_draft_detail', ['written' => $written->count(), 'total' => $chapters->count()]),
                'route' => 'manuscript',
            ],
            [
                'label' => Locales::t('overview.check_critical'),
                'done' => $findings->where('severity', 'critical')->isEmpty(),
                'detail' => Locales::t('overview.check_critical_detail', ['count' => $findings->where('severity', 'critical')->count()]),
                'route' => 'ai',
            ],
            [
                'label' => Locales::t('overview.check_claims'),
                'done' => $citations->where('status', 'needs_source')->isEmpty(),
                'detail' => Locales::t('overview.check_claims_detail', ['count' => $citations->where('status', 'needs_source')->count()]),
                'route' => 'research',
            ],
            [
                'label' => Locales::t('overview.check_sources'),
                'done' => $unverified === 0,
                'detail' => Locales::t('overview.check_sources_detail', ['count' => $unverified]),
                'route' => 'research',
            ],
            [
                'label' => Locales::t('overview.check_vocabulary'),
                'done' => $disagreements === 0,
                'detail' => Locales::t('overview.check_vocabulary_detail', ['count' => $disagreements]),
                'route' => 'terms',
            ],
            [
                'label' => Locales::t('overview.check_approve'),
                'done' => $chapters->isNotEmpty()
                    && $chapters->where('status', 'approved')->count() === $chapters->count(),
                'detail' => Locales::t('overview.check_approve_detail', ['approved' => $chapters->where('status', 'approved')->count(), 'total' => $chapters->count()]),
                'route' => 'workflow',
            ],
        ];
    }
}
