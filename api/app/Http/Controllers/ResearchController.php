<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\Citation;
use App\Models\ManuscriptSection;
use App\Models\ResearchSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The research library and its citations.
 *
 * Verification is a separate, recorded act from adding a source, for the
 * same reason accepting an editorial finding is separate from receiving it:
 * the system must be able to say who vouched for a piece of evidence and
 * when, not merely that it is present.
 */
class ResearchController extends Controller
{
    /** Everything in a book's library. */
    public function index(Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        return response()->json(['sources' => $this->sourcesFor($book)]);
    }

    public function store(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'kind' => ['required', 'in:'.implode(',', ResearchSource::KINDS)],
            'title' => ['required', 'string', 'max:255'],
            'authors' => ['nullable', 'string', 'max:255'],
            'publisher' => ['nullable', 'string', 'max:255'],
            'published' => ['nullable', 'string', 'max:255'],
            'container' => ['nullable', 'string', 'max:255'],
            'volume' => ['nullable', 'string', 'max:40'],
            'issue' => ['nullable', 'string', 'max:40'],
            'pages' => ['nullable', 'string', 'max:40'],
            'edition' => ['nullable', 'string', 'max:40'],
            'url' => ['nullable', 'url', 'max:2000'],
            'doi' => ['nullable', 'string', 'max:255'],
            'isbn' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        // Ownership from the route model and the session, never from input.
        $source = $book->sources()->create($data);
        $source->added_by = $request->user()->id;
        $source->save();

        return response()->json(['sources' => $this->sourcesFor($book)], 201);
    }

    /**
     * Record that a human checked this source — or disputed it.
     *
     * Deliberately not part of store(): a source cannot arrive pre-approved,
     * because the approval is the thing that carries evidentiary weight.
     */
    public function verify(Request $request, ResearchSource $source): JsonResponse
    {
        $this->authorize('update', $source->book);

        $data = $request->validate([
            'verification' => ['required', 'in:'.implode(',', ResearchSource::VERIFICATIONS)],
        ]);

        $source->verification = $data['verification'];
        $source->verified_by = $request->user()->id;
        $source->verified_at = now();
        $source->save();

        return response()->json(['sources' => $this->sourcesFor($source->book)]);
    }

    public function destroy(ResearchSource $source): JsonResponse
    {
        $this->authorize('update', $source->book);

        $book = $source->book;

        // Detach the citations BEFORE deleting, and do both atomically.
        //
        // The foreign key's nullOnDelete never fires here: this is a soft
        // delete, so the row survives and the citations keep pointing at it.
        // Without this they would go on reading "supported" while their
        // evidence is gone — a bibliography that quietly looks complete,
        // which is the exact failure the verification machinery exists to
        // prevent. Stated here because the FK makes it look handled.
        DB::transaction(function () use ($source) {
            $source->citations()->update([
                'research_source_id' => null,
                'status' => 'needs_source',
            ]);

            $source->delete();
        });

        return response()->json(['sources' => $this->sourcesFor($book->refresh())]);
    }

    // --- Citations --------------------------------------------------------

    public function citations(ManuscriptSection $section): JsonResponse
    {
        $this->authorize('view', $section);

        return response()->json(['citations' => $this->citationsFor($section)]);
    }

    public function storeCitation(Request $request, ManuscriptSection $section): JsonResponse
    {
        $this->authorize('update', $section);

        $data = $request->validate([
            'claim' => ['required', 'string', 'max:2000'],
            'research_source_id' => ['nullable', 'integer'],
            'locator' => ['nullable', 'string', 'max:255'],
            'excerpt' => ['nullable', 'string', 'max:2000'],
            'from_offset' => ['nullable', 'integer', 'min:0'],
            'to_offset' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', 'in:'.implode(',', Citation::STATUSES)],
        ]);

        // A source from another author's book must not become evidence here.
        if (! empty($data['research_source_id'])) {
            $owned = $section->book->sources()
                ->whereKey($data['research_source_id'])
                ->exists();

            abort_unless($owned, 422, 'That source does not belong to this book.');
        }

        // Mirrors the database CHECK constraint so the author gets a usable
        // message instead of a 500 from a constraint violation.
        if ($data['status'] !== 'needs_source' && empty($data['research_source_id'])) {
            abort(422, __('A supported or contradicted claim must name a source.'));
        }

        $section->citations()->create($data + ['created_by' => $request->user()->id]);

        return response()->json(['citations' => $this->citationsFor($section)], 201);
    }

    public function destroyCitation(Citation $citation): JsonResponse
    {
        $this->authorize('update', $citation->section);

        $section = $citation->section;
        $citation->delete();

        return response()->json(['citations' => $this->citationsFor($section)]);
    }

    /** @return array<int, array<string, mixed>> */
    private function sourcesFor(Book $book): array
    {
        return $book->sources()
            ->withCount('citations')
            ->orderBy('title')
            ->get()
            ->map(fn (ResearchSource $s) => [
                'id' => $s->id,
                'kind' => $s->kind,
                'title' => $s->title,
                'authors' => $s->authors,
                'publisher' => $s->publisher,
                'published' => $s->published,
                'container' => $s->container,
                'volume' => $s->volume,
                'issue' => $s->issue,
                'pages' => $s->pages,
                'edition' => $s->edition,
                'url' => $s->url,
                'doi' => $s->doi,
                'isbn' => $s->isbn,
                'notes' => $s->notes,
                'reference' => $s->reference(),
                'verification' => $s->verification,
                'usable_as_evidence' => $s->isUsableAsEvidence(),
                'citations_count' => $s->citations_count,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function citationsFor(ManuscriptSection $section): array
    {
        $text = $section->plainText();

        return $section->citations()
            ->with('source')
            ->orderBy('from_offset')
            ->get()
            ->map(fn (Citation $c) => [
                'id' => $c->id,
                'claim' => $c->claim,
                'locator' => $c->locator,
                'excerpt' => $c->excerpt,
                'status' => $c->status,
                'source' => $c->source ? [
                    'id' => $c->source->id,
                    'reference' => $c->source->reference(),
                    'verification' => $c->source->verification,
                ] : null,
                // Both computed, not stored: the manuscript moves, and a
                // source's standing can change after the citation is made.
                'stale' => $c->isStale($text),
                'rests_on_unverified_source' => $c->restsOnUnverifiedSource(),
            ])
            ->all();
    }
}
