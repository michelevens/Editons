<?php

namespace App\Http\Controllers;

use App\Journey\BookType;
use App\Journey\OutlineBuilder;
use App\Journey\OutlineImporter;
use App\Models\Book;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Where the author is in the book, and what to do next.
 *
 * The six stages are a path, not a wizard: every one can be opened at any
 * point, and nothing is enforced in order. What the stage records is where
 * the author considers themselves to be, which is what lets the product
 * answer "what should I do next" without pretending to know better than they
 * do.
 */
class JourneyController extends Controller
{
    public function __construct(
        private readonly OutlineBuilder $outliner,
        private readonly OutlineImporter $importer,
    ) {}

    public function show(Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        return response()->json($this->payload($book));
    }

    /** The Discover stage: what the book is, who it is for, what it promises. */
    public function saveIdea(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'premise' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'audience' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'promise' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'book_type' => ['sometimes', 'nullable', Rule::in(array_keys(config('booktypes')))],
        ]);

        $book->fill($data);

        // Answering the questions is what finishing Discover means, so the
        // stage moves on its own rather than needing a button that says
        // "I have finished thinking".
        if ($book->journey_stage === 'discover' && trim((string) $book->premise) !== '') {
            $book->journey_stage = 'plan';
        }

        $book->save();

        return response()->json($this->payload($book->fresh()));
    }

    public function setStage(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'stage' => ['required', Rule::in(Book::JOURNEY_STAGES)],
        ]);

        // Any stage, any time. The directive asks that stages be skippable
        // and revisitable, and a product that refuses to let somebody go back
        // to Discover in chapter nine has misunderstood how writing works.
        $book->update(['journey_stage' => $data['stage']]);

        return response()->json($this->payload($book->fresh()));
    }

    public function setAssistance(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'assistance' => ['required', Rule::in(Book::ASSISTANCE)],
        ]);

        // Changing this never touches the manuscript, which is what makes it
        // safe to change at any point.
        $book->update(['assistance' => $data['assistance']]);

        return response()->json($this->payload($book->fresh()));
    }

    /**
     * Name the chapters from the idea.
     *
     * Free: it rearranges what the author already told us rather than calling
     * a provider, and charging for that would be charging for arithmetic.
     */
    public function outline(Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        try {
            $renamed = $this->outliner->build($book);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(array_merge($this->payload($book->fresh()), [
            'renamed' => $renamed,
        ]));
    }

    /**
     * Import an outline the author already has.
     *
     * Free, like generating one: it rearranges text they pasted in.
     */
    public function importOutline(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'outline' => ['required', 'string', 'max:20000'],
        ]);

        try {
            $created = $this->importer->import($book, $data['outline']);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // An imported outline is a plan, so the stage follows.
        if (in_array($book->journey_stage, ['discover', 'plan'], true)) {
            $book->update(['journey_stage' => 'plan']);
        }

        return response()->json(array_merge($this->payload($book->fresh()), [
            'created' => $created,
        ]));
    }

    /** @return array<string, mixed> */
    private function payload(Book $book): array
    {
        $type = BookType::find($book->book_type);

        $chapters = $book->sections()->where('type', 'chapter')->count();
        $written = $book->sections()
            ->where('type', 'chapter')
            ->where('word_count', '>', 0)
            ->count();

        return [
            'stage' => $book->journey_stage,
            'stages' => $this->stages($book, $chapters, $written),
            'assistance' => $book->assistance,
            'assistance_levels' => self::ASSISTANCE_LEVELS,
            'idea' => [
                'premise' => $book->premise,
                'audience' => $book->audience,
                'promise' => $book->promise,
            ],
            'type' => $type?->toArray(),
            'book_types' => array_map(
                fn (BookType $t) => ['key' => $t->key, 'name' => $t->name(), 'blurb' => $t->blurb()],
                BookType::all(),
            ),
            'progress' => [
                'chapters' => $chapters,
                'written' => $written,
                'words' => $book->word_count,
                'target' => $book->target_words,
            ],
            'next' => $this->nextAction($book, $chapters, $written),
            // Every way in, with the honest answer about which work today.
            'starting_methods' => collect(config('startingmethods'))
                ->map(fn (array $method, string $key) => array_merge(
                    ['key' => $key],
                    $method,
                ))
                ->values()
                ->all(),
        ];
    }

    /**
     * The six stages, each with whether its work is actually done.
     *
     * "Done" is computed from the manuscript rather than ticked by the
     * author: a stage that claims completion while the work is absent would
     * make the whole path decorative.
     *
     * @return array<int, array<string, mixed>>
     */
    private function stages(Book $book, int $chapters, int $written): array
    {
        $hasIdea = trim((string) $book->premise) !== '';
        $titled = $book->sections()
            ->where('type', 'chapter')
            ->where('title', 'not like', 'Chapter %')
            ->count();

        $definitions = [
            ['discover', 'Discover', 'What the book is, who it is for, and what it promises.', $hasIdea],
            ['plan', 'Plan', 'The chapters, in the order they will be read.', $chapters > 0 && $titled > 0],
            ['create', 'Create', 'Writing it.', $written > 0],
            ['improve', 'Improve', 'Editorial review, fact checking and consistency.', $book->reviewsCount() > 0],
            ['design', 'Design', 'The interior layout and the cover.', $book->cover !== null],
            ['publish', 'Publish', 'Files, stores and the launch.', $book->exports()->exists()],
        ];

        return array_map(fn (array $d) => [
            'key' => $d[0],
            'name' => $d[1],
            'detail' => $d[2],
            'done' => (bool) $d[3],
            'current' => $book->journey_stage === $d[0],
        ], $definitions);
    }

    /**
     * One thing to do next.
     *
     * One, not a list. A product that answers "what should I do next" with
     * nine options has not answered it. Ordered by what actually blocks
     * progress rather than by stage, because an author in Create who has
     * never said who the book is for has a gap worth naming.
     *
     * @return array<string, string>|null
     */
    private function nextAction(Book $book, int $chapters, int $written): ?array
    {
        if (trim((string) $book->premise) === '') {
            return [
                'label' => 'Say what the book is about',
                'detail' => 'A sentence is enough. Everything else is easier once it exists.',
                'stage' => 'discover',
            ];
        }

        if ($chapters === 0) {
            return [
                'label' => 'Add some chapters',
                'detail' => 'Even a rough list. You can move and rename them freely.',
                'stage' => 'plan',
            ];
        }

        $untitled = $book->sections()
            ->where('type', 'chapter')
            ->where('title', 'like', 'Chapter %')
            ->count();

        if ($untitled === $chapters) {
            return [
                'label' => 'Name the chapters',
                'detail' => 'From what you have said the book is about. You can change any of them.',
                'stage' => 'plan',
                'action' => 'outline',
            ];
        }

        if ($written === 0) {
            return [
                'label' => 'Write the first chapter',
                'detail' => 'Any chapter. The first one written is rarely the first one read.',
                'stage' => 'create',
            ];
        }

        if ($written < $chapters) {
            $left = $chapters - $written;

            return [
                'label' => "Keep writing — {$left} chapter".($left === 1 ? '' : 's').' still empty',
                'detail' => 'Nothing else needs doing until there is more of it.',
                'stage' => 'create',
            ];
        }

        if ($book->reviewsCount() === 0) {
            return [
                'label' => 'Run an editorial review',
                'detail' => 'A whole draft is the point at which review is worth the credits.',
                'stage' => 'improve',
            ];
        }

        if (! $book->exports()->exists()) {
            return [
                'label' => 'Make a file',
                'detail' => 'An EPUB is enough to read it on a device and see what you have.',
                'stage' => 'publish',
            ];
        }

        return null;
    }

    private const ASSISTANCE_LEVELS = [
        [
            'key' => 'author_led',
            'name' => 'I write',
            'detail' => 'You write. The editors run when you ask them to.',
        ],
        [
            'key' => 'coach',
            'name' => 'Coach me',
            'detail' => 'Questions, structure and suggestions as you go — the words stay yours.',
        ],
        [
            'key' => 'collaborative',
            'name' => 'Draft with me',
            'detail' => 'Drafts passages from your notes and outline, for you to accept or reject.',
        ],
    ];
}
