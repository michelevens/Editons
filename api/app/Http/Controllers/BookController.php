<?php

namespace App\Http\Controllers;

use App\Editorial\EditorialAgent;
use App\Models\Book;
use App\Models\ManuscriptSection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BookController extends Controller
{
    /** The author's shelf. */
    public function index(Request $request): Response
    {
        return Inertia::render('Books/Index', [
            'books' => $request->user()->books()
                ->withCount('sections')
                ->orderByDesc('updated_at')
                ->get()
                ->map(fn (Book $b) => [
                    'id' => $b->id,
                    'title' => $b->title,
                    'subtitle' => $b->subtitle,
                    'genre' => $b->genre,
                    'status' => $b->status,
                    'word_count' => $b->word_count,
                    'sections_count' => $b->sections_count,
                    'updated_at' => $b->updated_at->diffForHumans(),
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'author_name' => ['nullable', 'string', 'max:255'],
            'genre' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        $book = DB::transaction(function () use ($request, $data) {
            // Ownership from the session, never from input — see the Book
            // model's note on why user_id is not fillable.
            $book = $request->user()->books()->create($data);

            // A book with an empty binder has nowhere to type, and the editor
            // would render a three-pane shell around nothing. One chapter is
            // the smallest thing that makes the product usable on arrival.
            $book->sections()->create([
                'type' => 'chapter',
                'title' => 'Chapter 1',
                'position' => ManuscriptSection::POSITION_STEP,
            ]);

            return $book;
        });

        return redirect()->route('books.edit', $book);
    }

    /**
     * The writing studio: binder on the left, editor in the centre.
     *
     * Section content is deliberately NOT loaded for the whole tree. A book
     * is potentially 100k+ words of JSON, and sending all of it to render a
     * sidebar would make the page weight scale with the manuscript. The
     * binder needs titles and counts; the editor fetches one section's body.
     */
    public function edit(Request $request, Book $book): Response
    {
        $this->authorize('view', $book);

        $book->load(['rootSections.children']);

        $active = $this->resolveActiveSection($book, $request->integer('section'));

        return Inertia::render('Books/Editor', [
            'book' => [
                'id' => $book->id,
                'title' => $book->title,
                'subtitle' => $book->subtitle,
                'genre' => $book->genre,
                'status' => $book->status,
                'word_count' => $book->word_count,
            ],
            // The panel renders whatever agents exist, so adding one is a
            // single enum case rather than an edit in two languages.
            'agents' => EditorialAgent::catalogue(),
            'binder' => $book->rootSections->map(fn ($s) => $this->binderNode($s)),
            'activeSection' => $active ? [
                'id' => $active->id,
                'title' => $active->title,
                'subtitle' => $active->subtitle,
                'type' => $active->type,
                'content' => $active->content,
                'word_count' => $active->word_count,
                'versions_count' => $active->versions()->count(),
                'updated_at' => $active->updated_at->toIso8601String(),
            ] : null,
        ]);
    }

    public function update(Request $request, Book $book): RedirectResponse
    {
        $this->authorize('update', $book);

        $book->update($request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'author_name' => ['nullable', 'string', 'max:255'],
            'genre' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', 'in:'.implode(',', Book::STATUSES)],
        ]));

        return back();
    }

    public function destroy(Book $book): RedirectResponse
    {
        $this->authorize('delete', $book);

        // Soft delete. A manuscript is someone's unrecoverable work, and a
        // misclick should not be final.
        $book->delete();

        return redirect()->route('books.index');
    }

    /** Which section opens: the requested one, or the first writable node. */
    private function resolveActiveSection(Book $book, ?int $requested): ?ManuscriptSection
    {
        if ($requested) {
            $match = $book->sections()->whereKey($requested)->first();
            if ($match) {
                return $match;
            }
        }

        return $book->sections()
            ->where('type', '!=', 'part')
            ->orderBy('position')
            ->first();
    }

    /** @return array<string, mixed> */
    private function binderNode(ManuscriptSection $s): array
    {
        return [
            'id' => $s->id,
            'type' => $s->type,
            'title' => $s->title,
            'word_count' => $s->word_count,
            'children' => $s->children->map(fn ($c) => $this->binderNode($c))->all(),
        ];
    }
}
