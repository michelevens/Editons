<?php

namespace App\Http\Controllers;

use App\Editorial\EditorialAgent;
use App\Journey\BookType;
use App\Journey\Scaffolder;
use App\Models\Book;
use App\Models\ManuscriptSection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Illuminate\Validation\Rule;
use Inertia\Response;

class BookController extends Controller
{
    public function __construct(private readonly Scaffolder $scaffolder) {}

    /**
     * The author's shelf.
     *
     * Counts come from the unfiltered set so the filter chips can show how
     * many are behind each one — a chip reading "Archived" with no number is
     * a guess the author has to click to resolve.
     */
    public function index(Request $request): Response
    {
        // 'all' by default, as the design has it. Opening on a filtered view
        // hides books without saying so, which is the wrong first impression
        // of somebody's own shelf.
        $filter = $request->string('filter')->toString() ?: 'all';

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
                'cover' => $b->coverFor(),
            ]),
            // The guided flow's options. Sent with the shelf so choosing a
            // type needs no extra request.
            'book_types' => array_map(
                fn (BookType $type) => $type->toArray(),
                BookType::all(),
            ),
            // The author's usual kind of book, so the flow opens on it
            // rather than asking a question they have already answered.
            'default_book_type' => $request->user()->default_book_type,
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

            // The guided flow sends these. All optional, because the plain
            // "New book" button still works and must stay one click — an
            // author who knows what they are doing should not be walked
            // through a wizard to get an empty page.
            'book_type' => ['nullable', 'string', Rule::in(array_keys(config('booktypes')))],
            'chapters' => ['nullable', 'integer', 'min:1', 'max:60'],
            'target_words' => ['nullable', 'integer', 'min:100', 'max:500000'],
            'assistance' => ['nullable', Rule::in(Book::ASSISTANCE)],
            'started_with' => ['nullable', 'string', 'max:40'],
        ]);

        // The plan limit is enforced here, not only in the UI. A disabled
        // button is a hint; this is the control.
        abort_unless(
            $request->user()->canCreateBook(),
            403,
            'This plan does not allow another book. Archive one, or upgrade.',
        );

        $type = BookType::find($data['book_type'] ?? null);

        $book = DB::transaction(function () use ($request, $data, $type) {
            // Ownership from the session, never from input — see the Book
            // model's note on why user_id is not fillable.
            $book = $request->user()->books()->create(array_merge(
                Arr::except($data, ['chapters']),
                $type ? [
                    // A book that knows its type starts past Discover: the
                    // question that stage asks has just been answered.
                    'journey_stage' => 'plan',
                    'target_words' => $data['target_words'] ?? $type->suggestedWords(),
                ] : [],
            ));

            if ($type) {
                $this->scaffolder->build($book, $type, $data['chapters'] ?? null);

                return $book;
            }

            // No type chosen: the plain path. A book with an empty binder has
            // nowhere to type, and the editor would render a three-pane shell
            // around nothing. One chapter is the smallest thing that makes the
            // product usable on arrival.
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

        $this->rememberPlace($book, $active);

        return Inertia::render('Books/Editor', [
            'book' => [
                'id' => $book->id,
                'title' => $book->title,
                'subtitle' => $book->subtitle,
                'genre' => $book->genre,
                'status' => $book->status,
                'word_count' => $book->word_count,
                'cover' => $book->coverFor(),
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
                'cover' => $book->coverFor(),
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
    /**
     * Which section to open.
     *
     * In order: the one asked for, the one the author last worked in, then
     * the first. Resuming where you stopped is the difference between
     * picking a book up and finding your place in it again — on a
     * twenty-chapter manuscript, landing on chapter one every time means
     * hunting for your own work.
     *
     * A part is never resumed into: it holds chapters rather than prose, so
     * there is nothing to type in.
     */
    private function resolveActiveSection(Book $book, ?int $requested): ?ManuscriptSection
    {
        if ($requested) {
            $match = $book->sections()->whereKey($requested)->first();

            if ($match) {
                return $match;
            }
        }

        if ($book->last_section_id) {
            $last = $book->sections()
                ->whereKey($book->last_section_id)
                ->where('type', '!=', 'part')
                ->first();

            // Gone if the section was deleted, which the foreign key already
            // nulls — this also covers one that became a part.
            if ($last) {
                return $last;
            }
        }

        return $book->sections()
            ->where('type', '!=', 'part')
            ->orderBy('position')
            ->first();
    }

    /**
     * Remember where the author is.
     *
     * forceFill because this is the server recording what happened, not a
     * field a request may claim. Saved quietly: it is a bookmark, and a
     * failure to move it should never interrupt writing.
     */
    private function rememberPlace(Book $book, ?ManuscriptSection $section): void
    {
        if (! $section || $book->last_section_id === $section->id) {
            return;
        }

        $book->forceFill(['last_section_id' => $section->id])->saveQuietly();
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
