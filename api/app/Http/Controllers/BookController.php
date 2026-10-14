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
    /**
     * The author's shelf.
     *
     * Counts come from the unfiltered set so the filter chips can show how
     * many are behind each one — a chip reading "Archived" with no number is
     * a guess the author has to click to resolve.
     */
    public function index(Request $request): Response
    {
        $filter = $request->string('filter')->toString() ?: 'active';

        // Owned AND shared. A collaborator who accepted an invitation and
        // then found an empty shelf would have no way into the book.
        $all = $request->user()->accessibleBooks()
            ->withCount('sections')
            ->orderByDesc('updated_at')
            ->get();

        $visible = match ($filter) {
            'archived' => $all->where('status', 'archived'),
            'published' => $all->where('status', 'published'),
            'all' => $all,
            default => $all->where('status', '!=', 'archived'),
        };

        return Inertia::render('Books/Index', [
            'filter' => $filter,
            'counts' => [
                'active' => $all->where('status', '!=', 'archived')->count(),
                'published' => $all->where('status', 'published')->count(),
                'archived' => $all->where('status', 'archived')->count(),
                'all' => $all->count(),
            ],
            'books' => $visible->values()->map(fn (Book $b) => [
                'id' => $b->id,
                'title' => $b->title,
                'subtitle' => $b->subtitle,
                'genre' => $b->genre,
                'status' => $b->status,
                'word_count' => $b->word_count,
                'target_words' => $b->target_words,
                'sections_count' => $b->sections_count,
                'updated_at' => $b->updated_at->diffForHumans(),
            ]),
            'can_create' => $request->user()->canCreateBook(),
            'plan' => $request->user()->planLimits(),
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

        // The plan limit is enforced here, not only in the UI. A disabled
        // button is a hint; this is the control.
        abort_unless(
            $request->user()->canCreateBook(),
            403,
            'This plan does not allow another book. Archive one, or upgrade.',
        );

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
            // Which pane to open on arrival, so Overview can link straight to
            // a section of the studio rather than always landing on the
            // manuscript.
            'pane' => $request->string('pane')->toString() ?: null,
            // The bottom-row Research card needs the library on first paint;
            // fetching it after mount would flash an empty card on every
            // chapter change.
            'sources' => $book->sources()
                ->orderBy('title')
                ->get()
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'kind' => $s->kind,
                    'title' => $s->title,
                    'published' => $s->published,
                    'verification' => $s->verification,
                ]),
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

    /** The Book Settings screen. */
    public function settings(Book $book): Response
    {
        $this->authorize('view', $book);

        return Inertia::render('Books/Settings', [
            'book' => [
                'id' => $book->id,
                'title' => $book->title,
                'subtitle' => $book->subtitle,
                'author_name' => $book->author_name,
                'genre' => $book->genre,
                'language' => $book->language,
                'description' => $book->description,
                'status' => $book->status,
                'word_count' => $book->word_count,
                'target_words' => $book->target_words,
                'settings' => $book->settings(),
            ],
            'genres' => ['Nonfiction', 'Memoir', 'Textbook', 'Technical', 'Fiction'],
            'languages' => ['English (US)', 'English (UK)', 'Spanish', 'French', 'Haitian Creole'],
            // Archiving the last active book would leave the author with an
            // empty shelf and nowhere to go, so the screen needs to know.
            'can_archive' => $book->user->books()->where('status', '!=', 'archived')->count() > 1,
        ]);
    }

    public function update(Request $request, Book $book): RedirectResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'author_name' => ['nullable', 'string', 'max:255'],
            'genre' => ['nullable', 'string', 'max:120'],
            'language' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:5000'],
            'target_words' => ['nullable', 'integer', 'min:1000', 'max:2000000'],
            'status' => ['sometimes', 'in:'.implode(',', Book::STATUSES)],
            'settings' => ['sometimes', 'array'],
            'settings.autosave' => ['boolean'],
            'settings.inline_ai' => ['boolean'],
            'settings.review_on_save' => ['boolean'],
        ]);

        // Merge rather than replace: the form may send one toggle, and
        // overwriting the column would silently reset the others.
        if (isset($data['settings'])) {
            $data['settings'] = array_merge($book->settings(), $data['settings']);
        }

        $book->update($data);

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
