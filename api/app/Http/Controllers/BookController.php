<?php

namespace App\Http\Controllers;

use App\Editorial\EditorialAgent;
use App\Editorial\Style\Typography;
use App\Journey\BookType;
use App\Journey\Scaffolder;
use App\Mail\NotificationSettings;
use App\Models\Book;
use App\Models\Isbn;
use App\Models\ManuscriptSection;
use App\Platform\House\House;
use App\Platform\Trust\MasterData;
use App\Publishing\Anatomy;
use App\Publishing\BookPart;
use App\Publishing\Exporter;
use App\Research\Bibliography;
use App\Shelf\Shelf;
use App\Shelf\ShelfPage;
use App\Workspace\Workspace;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BookController extends Controller
{
    public function __construct(private readonly Scaffolder $scaffolder) {}

    /**
     * The author's shelf.
     *
     * Searched, sorted, grouped and paged on the server (App\Shelf\Shelf,
     * ADR-0045 — Shelf views and bulk actions), in the way the author last
     * left it unless the address says otherwise. With no saved view it is
     * the shelf as it always was: every book, last updated first, as cards.
     *
     * Counts come from the unfiltered set so the filter chips can show how
     * many are behind each one — a chip reading "Archived" with no number is
     * a guess the author has to click to resolve.
     */
    public function index(Request $request, ShelfPage $shelf): Response
    {
        $user = $request->user();

        // 'all' by default, as the design has it. Opening on a filtered view
        // hides books without saying so, which is the wrong first impression
        // of somebody's own shelf. Owned AND shared: a collaborator who
        // accepted an invitation and then found an empty shelf would have no
        // way into the book.
        $state = $shelf->state($request, 'books');

        // The House catalog shelf (ADR — house catalog): staff see the
        // platform's own imprints' books here, as themselves. Never part of
        // "All": they are not this person's books.
        $staff = House::canViewCatalog($user);

        if ($state['filter'] === 'house' && ! $staff) {
            $state['filter'] = 'all';
        }

        // Per-book mute (NotificationSettings): the card's bell shows it.
        $muted = NotificationSettings::mutedBooks($user);

        $page = $shelf->build($request, 'books', $state, fn (Book $b) => [
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
            'house' => $b->user_id !== $user->id && $b->user?->isHouse(),
            'muted' => in_array($b->id, $muted, true),
        ], fn ($query) => $query->withCount('sections')->with('user:id,account_kind'));

        return Inertia::render('Books/Index', $page + [
            'filter' => $state['filter'],
            'counts' => Shelf::counts($user, 'books') + [
                'house' => $staff ? Shelf::counts($user, 'house')['all'] : null,
                // The header's total is the shelf's, not this page's.
                'words' => (int) $user->accessibleBooks()->where('status', '!=', 'archived')->sum('word_count'),
            ],
            'house' => $staff ? ['console' => route('admin.house.index')] : null,
            // The guided flow's options. Sent with the shelf so choosing a
            // type needs no extra request.
            'book_types' => array_map(
                fn (BookType $type) => $type->toArray(),
                BookType::all(),
            ),
            // The author's usual kind of book, so the flow opens on it
            // rather than asking a question they have already answered.
            'default_book_type' => $user->default_book_type,
            'can_create' => $user->canCreateBook(),
            'plan' => $user->planLimits(),
        ]);
    }

    /**
     * Quick Start: one question, straight into writing.
     *
     * The same books and the same history as the studio — a different door
     * into one building, not a second building. Which door somebody prefers
     * is theirs to choose, so this is a route rather than a mode they have
     * to be switched into.
     */
    public function quickStart(Request $request): Response
    {
        return Inertia::render('Books/QuickStart', [
            'recent' => $request->user()->books()
                ->where('status', '!=', 'archived')
                ->latest('updated_at')
                ->limit(3)
                ->get()
                ->map(fn (Book $book) => [
                    'id' => $book->id,
                    'title' => $book->title,
                    'word_count' => $book->word_count,
                    // Where they stopped, named — "Back to Chapter 7" is a
                    // more useful offer than "Open".
                    'resume' => $book->last_section_id
                        ? $book->sections()
                            ->whereKey($book->last_section_id)
                            ->value('title')
                        : null,
                ])
                ->all(),
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
            // Asked at creation in the genre's own words, because the moment
            // somebody has just chosen "Cookbook" is the moment "What ties
            // these recipes together?" is easiest to answer. Optional: it is
            // also the moment somebody wants to get on with it.
            'premise' => ['nullable', 'string', 'max:2000'],
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
                    // A book that knows its type and its premise starts past
                    // Discover. Without a premise it stays there, because
                    // that is the question Discover asks and it is still
                    // unanswered — the stage must not claim otherwise.
                    'journey_stage' => trim((string) ($data['premise'] ?? '')) !== ''
                        ? 'plan'
                        : 'discover',
                    'target_words' => $data['target_words'] ?? $type->suggestedWords(),
                ] : [],
            ));

            // Print defaults, stored so the cover wrap and the calculator
            // read what the author sees: white paper, black ink, matte,
            // at the type's trim (letter for textbooks, else 6 x 9).
            $book->print = Book::DEFAULT_PRINT;

            if ($type?->trim()) {
                $book->layout = array_merge(Book::DEFAULT_LAYOUT, ['trim' => $type->trim()]);
            }

            $book->save();

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
                // The Autosave switch in Book Settings was saved and never
                // read; the editor needs it to honour it.
                'autosave' => (bool) ($book->settings()['autosave'] ?? true),
                // The editor writes in the book's language: the browser's
                // spell-check follows it, and the typography rules (ADR —
                // Book language and typography) correct as the author types
                // only when they turned that on.
                'language_code' => Exporter::languageCode($book->language),
                'spellcheck' => (bool) ($book->settings()['spellcheck'] ?? true),
                'typography' => Typography::resolve($book),
            ],
            // The panel renders whatever agents exist, so adding one is a
            // single enum case rather than an edit in two languages.
            //
            // Ordered for this kind of book: config/booktypes.php names the
            // reviewers that matter most for each type, and until now that
            // list had no consumer at all. Ordering only — every agent stays
            // available, because an author wanting the accessibility check on
            // a memoir should not have to change the book's type to reach it.
            'agents' => EditorialAgent::catalogue(
                BookType::find($book->book_type)?->agents() ?? [],
            ),
            // Which pane to open on arrival, so Overview can link straight to
            // a section of the studio rather than always landing on the
            // manuscript.
            'pane' => $request->string('pane')->toString() ?: null,
            // How this author has arranged the studio (ADR — customisable
            // writing workspace): on first paint, so no panel jumps once the
            // page has loaded.
            'workspace' => Workspace::for($request->user(), $book),
            // A house book (ADR — house catalog): the studio says so, and
            // whose name its versions are saved under — the staff member's.
            'house' => House::isHouseBook($book) ? [
                'imprint' => $book->user->name,
                'editor' => $request->user()->name,
                'can_edit' => $request->user()->can('update', $book),
                'console' => route('admin.house.index'),
            ] : null,
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
            // What the binder's "Book part" menu offers, in Chicago order.
            'book_parts' => self::partOptions(),
            'activeSection' => $active ? [
                'id' => $active->id,
                'title' => $active->title,
                'subtitle' => $active->subtitle,
                'type' => $active->type,
                'content' => $active->content,
                'word_count' => $active->word_count,
                'word_goal' => $active->word_goal,
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
                'series_name' => $book->series_name,
                'series_number' => $book->series_number,
                'publication' => $book->publication(),
            ],
            // The Book parts checklist renders on first paint, not after a
            // fetch that would flash an empty panel.
            'anatomy' => Anatomy::checklist($book),
            'copyright_preview' => Anatomy::copyrightLines($book),
            // Owner-edited lists (App\Platform\Trust\MasterData); a value the
            // book already has stays selectable when the list drops it.
            'genres' => MasterData::with('genres', $book->genre),
            'languages' => MasterData::with('languages', $book->language),
            'citation_styles' => MasterData::citationOptions($book->settings()['citation_style'] ?? null),
            // Language and typography (ADR — Book language and typography):
            // the rules with the language's defaults, and the paragraph and
            // heading settings, which live in the interior design and are
            // shown here only to link there.
            'typography' => Typography::resolve($book),
            'interior' => [
                'paragraph' => $book->layout()['text']['paragraph'],
                'label' => $book->layout()['opener']['label'],
                'numbering' => $book->layout()['numbering'],
            ],
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
            'settings.citation_style' => ['string', 'in:'.implode(',', Bibliography::STYLES)],
            'settings.spellcheck' => ['boolean'],
            ...Typography::validationRules(),

            // Publishing metadata, printed by the generated pages. Every
            // field optional: an empty one prints nothing.
            'series_name' => ['nullable', 'string', 'max:255'],
            'series_number' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'publication' => ['sometimes', 'array'],
            'publication.publisher' => ['nullable', 'string', 'max:255'],
            'publication.imprint' => ['nullable', 'string', 'max:255'],
            'publication.place' => ['nullable', 'string', 'max:255'],
            'publication.edition_statement' => ['nullable', 'string', 'max:255'],
            'publication.copyright_holder' => ['nullable', 'string', 'max:255'],
            'publication.copyright_year' => ['nullable', 'integer', 'min:1450', 'max:'.(now()->year + 2)],
            'publication.rights_statement' => ['nullable', 'string', 'max:1000'],
            'publication.isbn_print' => ['nullable', 'string', 'max:20', $this->isbnRule()],
            'publication.isbn_epub' => ['nullable', 'string', 'max:20', $this->isbnRule()],
            'publication.isbn_pdf' => ['nullable', 'string', 'max:20', $this->isbnRule()],
            'publication.legal_deposit' => ['nullable', 'string', 'max:500'],
            'publication.country' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'publication.printing' => ['nullable', 'string', 'max:500'],
            'publication.disclaimer' => ['nullable', 'string', 'max:3000'],
            'publication.credits' => ['nullable', 'array', 'max:20'],
            'publication.credits.*.role' => ['nullable', 'string', 'max:120'],
            'publication.credits.*.name' => ['nullable', 'string', 'max:255'],
        ]);

        // Archived books do not count toward the plan's limit, so bringing
        // one back is creating a book as far as the limit is concerned:
        // otherwise archive, create, restore would walk past it.
        if (($data['status'] ?? null) && $data['status'] !== 'archived' && $book->status === 'archived') {
            abort_unless(
                $book->user->canCreateBook(),
                403,
                'This plan does not allow another active book. Archive another one first, or upgrade.',
            );
        }

        if (isset($data['publication'])) {
            $data['publication'] = $this->mergePublication($book, $data['publication']);
        }

        // Merge rather than replace: the form may send one toggle, and
        // overwriting the column would silently reset the others.
        if (isset($data['settings'])) {
            // Typography is a group of its own, merged key by key the same way.
            if (isset($data['settings']['typography'])) {
                $data['settings']['typography'] = Typography::normalise(
                    array_merge($book->settings()['typography'] ?? [], $data['settings']['typography']),
                );
            }

            $data['settings'] = array_merge($book->settings(), $data['settings']);
        }

        $book->update($data);

        return back();
    }

    /** An ISBN must pass the ISBN-13 checksum; none is ever generated. */
    private function isbnRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if ($value !== null && trim((string) $value) !== '' && ! Isbn::isValid((string) $value)) {
                $fail('That is not a valid ISBN-13. Check the digits.');
            }
        };
    }

    /**
     * Merge a partial publication update into what is stored, as settings
     * are, and refuse one number for two formats.
     *
     * @return array<string, mixed>
     */
    private function mergePublication(Book $book, array $incoming): array
    {
        $merged = array_merge($book->publication(), array_intersect_key($incoming, Book::DEFAULT_PUBLICATION));

        foreach ($merged as $key => $value) {
            if (is_string($value)) {
                $merged[$key] = trim($value) === '' ? null : trim($value);
            }
        }

        // A half-filled credit row (a role with no name) prints nothing.
        $merged['credits'] = array_values(array_map(
            fn ($c) => ['role' => trim((string) $c['role']), 'name' => trim((string) $c['name'])],
            array_filter(
                $merged['credits'] ?? [],
                fn ($c) => trim((string) ($c['role'] ?? '')) !== '' && trim((string) ($c['name'] ?? '')) !== '',
            ),
        ));

        $numbers = array_filter([
            'isbn_print' => $merged['isbn_print'] ? Isbn::normalise($merged['isbn_print']) : null,
            'isbn_epub' => $merged['isbn_epub'] ? Isbn::normalise($merged['isbn_epub']) : null,
            'isbn_pdf' => $merged['isbn_pdf'] ? Isbn::normalise($merged['isbn_pdf']) : null,
        ]);

        if (count($numbers) !== count(array_unique($numbers))) {
            throw ValidationException::withMessages([
                'publication.isbn_epub' => 'Each format needs its own ISBN: the same number is entered twice.',
            ]);
        }

        return $merged;
    }

    /**
     * The binder's part menu: every part a section can be, in Chicago order.
     *
     * @return list<array{value: string, label: string, group: string}>
     */
    public static function partOptions(): array
    {
        return array_map(fn (BookPart $p) => [
            'value' => $p->value,
            'label' => $p->title('en'),
            'group' => $p->group(),
        ], BookPart::sectionKinds());
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
            'part' => $s->part,
            'title' => $s->title,
            'word_count' => $s->word_count,
            // The binder's progress ring; null for most chapters.
            'word_goal' => $s->word_goal,
            'children' => $s->children->map(fn ($c) => $this->binderNode($c))->all(),
        ];
    }
}
