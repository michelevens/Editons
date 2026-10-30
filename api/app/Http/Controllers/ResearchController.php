<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\Citation;
use App\Models\ManuscriptSection;
use App\Models\ResearchSource;
use App\Research\Import\Entry;
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
    /** Sort key => the order it means. Title is the default, as before. */
    public const SORTS = ['title', 'author', 'year', 'added', 'standing'];

    /**
     * A book's library: everything by default, or what a search, filter or
     * sort asks for (q, verification, kind, tag, collection, sort), with the
     * facets — counts per standing, kind, tag and collection — the filter
     * bar needs. The facets always count the whole library, so a chip never
     * says 0 just because another filter is on.
     */
    public function index(Request $request, Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'verification' => ['nullable', 'in:'.implode(',', ResearchSource::VERIFICATIONS)],
            'kind' => ['nullable', 'in:'.implode(',', ResearchSource::KINDS)],
            'tag' => ['nullable', 'string', 'max:40'],
            'collection' => ['nullable', 'string', 'max:80'],
            'sort' => ['nullable', 'in:'.implode(',', self::SORTS)],
        ]);

        return response()->json([
            'sources' => self::sourcesFor($book, $filters),
            'facets' => self::facets($book),
        ]);
    }

    /**
     * Tags and collection: how the author files a source. Deliberately not
     * the verification, which keeps its own recorded endpoint.
     */
    public function organise(Request $request, ResearchSource $source): JsonResponse
    {
        $this->authorize('update', $source->book);

        $data = $request->validate([
            'tags' => ['sometimes', 'array', 'max:'.ResearchSource::MAX_TAGS],
            'tags.*' => ['nullable', 'string', 'max:40'],
            'collection' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);

        if (array_key_exists('tags', $data)) {
            $source->tags = self::cleanTags($data['tags']);
        }

        if (array_key_exists('collection', $data)) {
            $collection = Entry::text((string) $data['collection']);
            $source->collection = $collection === '' ? null : $collection;
        }

        $source->save();

        return response()->json([
            'sources' => self::sourcesFor($source->book),
            'facets' => self::facets($source->book),
        ]);
    }

    /**
     * Plain text, no duplicates (case-insensitively), the first spelling kept.
     *
     * @param  array<int, mixed>  $tags
     * @return list<string>
     */
    public static function cleanTags(array $tags): array
    {
        $out = [];

        foreach ($tags as $tag) {
            $tag = mb_substr(Entry::text((string) $tag), 0, 40);
            $key = mb_strtolower($tag);

            if ($tag !== '' && ! isset($out[$key])) {
                $out[$key] = $tag;
            }
        }

        return array_slice(array_values($out), 0, ResearchSource::MAX_TAGS);
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
            'tags' => ['sometimes', 'array', 'max:'.ResearchSource::MAX_TAGS],
            'tags.*' => ['nullable', 'string', 'max:40'],
            'collection' => ['nullable', 'string', 'max:80'],
            // Set when the form was filled from a DOI lookup. Provenance
            // only — it never touches verification.
            'origin' => ['nullable', 'in:crossref'],
        ]);

        $origin = $data['origin'] ?? null;
        unset($data['origin']);

        if (isset($data['tags'])) {
            $data['tags'] = self::cleanTags($data['tags']);
        }

        // Ownership from the route model and the session, never from input.
        $source = $book->sources()->create($data);
        $source->added_by = $request->user()->id;
        $source->origin = $origin;
        $source->save();

        return response()->json(['sources' => self::sourcesFor($book)], 201);
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

        return response()->json(['sources' => self::sourcesFor($source->book)]);
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

        return response()->json(['sources' => self::sourcesFor($book->refresh())]);
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

    /**
     * @param  array<string, string|null>  $filters
     * @return array<int, array<string, mixed>>
     */
    public static function sourcesFor(Book $book, array $filters = []): array
    {
        $query = $book->sources()->withCount('citations');

        if (($q = trim((string) ($filters['q'] ?? ''))) !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(fn ($w) => $w
                ->where('title', 'ilike', $like)
                ->orWhere('authors', 'ilike', $like)
                ->orWhere('notes', 'ilike', $like)
                ->orWhere('url', 'ilike', $like)
                ->orWhere('doi', 'ilike', $like)
                ->orWhere('container', 'ilike', $like));
        }

        foreach (['verification', 'kind', 'collection'] as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        if (! empty($filters['tag'])) {
            $query->whereJsonContains('tags', $filters['tag']);
        }

        match ($filters['sort'] ?? 'title') {
            'author' => $query->orderByRaw('authors IS NULL, lower(authors)')->orderBy('title'),
            'year' => $query->orderByRaw('published IS NULL, published DESC')->orderBy('title'),
            'added' => $query->orderByDesc('created_at')->orderByDesc('id'),
            'standing' => $query->orderByRaw("CASE verification WHEN 'unverified' THEN 0 WHEN 'disputed' THEN 1 ELSE 2 END")->orderBy('title'),
            default => $query->orderBy('title'),
        };

        return $query
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
                'tags' => $s->tags ?? [],
                'collection' => $s->collection,
                'origin' => $s->origin,
            ])
            ->all();
    }

    /** @return array<string, mixed> counts over the whole library */
    public static function facets(Book $book): array
    {
        $rows = $book->sources()->get(['verification', 'kind', 'tags', 'collection']);

        $tags = $rows->flatMap(fn (ResearchSource $s) => $s->tags ?? [])->countBy();
        $collections = $rows->pluck('collection')->filter()->countBy();

        return [
            'total' => $rows->count(),
            'verification' => (object) $rows->countBy('verification')->all(),
            'kind' => (object) $rows->countBy('kind')->all(),
            'tags' => $tags->sortKeys()->map(fn ($n, $name) => ['name' => $name, 'count' => $n])->values()->all(),
            'collections' => $collections->sortKeys()->map(fn ($n, $name) => ['name' => $name, 'count' => $n])->values()->all(),
        ];
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
