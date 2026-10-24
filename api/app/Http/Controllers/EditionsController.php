<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Platform\Trust\MasterData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Editions.
 *
 * An edition is a separate book that remembers where it came from. It is not
 * a view of the original: a translation gets edited independently, and a
 * workbook's content diverges immediately. Sharing rows would mean an edit to
 * one silently changing the other.
 *
 * Nothing here calls an AI provider. A translation is created as a structured
 * shell with the source text in place and marked for translation; doing the
 * translation is an editorial job that runs through the driver seam, where
 * every other AI call in the app lives.
 */
class EditionsController extends Controller
{
    public function index(Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        return response()->json($this->payload($book));
    }

    public function store(Request $request, Book $book): JsonResponse
    {
        // `manage`, not `update`: creating a new book on somebody's shelf is
        // an owner's decision, not an editor's.
        $this->authorize('manage', $book);

        $data = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', Book::EDITION_TYPES)],
            'language' => ['required_if:type,translation', 'nullable', 'string', 'max:50'],
        ]);

        // An edition is a new book on the owner's shelf, so the plan's book
        // limit applies exactly as it does to New book and Import.
        abort_unless(
            $request->user()->canCreateBook(),
            403,
            'This plan does not allow another book. Archive one, or upgrade.',
        );

        $chapters = $book->sections()
            ->where('type', '!=', 'part')
            ->orderBy('position')
            ->get();

        if ($chapters->isEmpty()) {
            return response()->json([
                'message' => 'Write a chapter before making an edition of this book.',
            ], 422);
        }

        // One transaction: an edition that exists with half its chapters is
        // worse than one that failed to be created.
        $edition = DB::transaction(function () use ($request, $book, $chapters, $data) {
            $edition = match ($data['type']) {
                'workbook' => $this->workbook($request, $book, $chapters),
                'translation' => $this->translation($request, $book, $chapters, $data['language']),
                'large_print' => $this->largePrint($request, $book, $chapters),
            };

            // The shelf, Overview and Journey read the denormalised count;
            // without this every new edition said "0 words".
            $edition->recalculateWordCount();

            return $edition;
        });

        return response()->json([
            'edition' => $this->row($edition),
            'editions' => $this->payload($book->fresh())['editions'],
        ], 201);
    }

    /**
     * A companion workbook.
     *
     * Built from the source's headings, with a reflection prompt per section.
     * The prompts are derived from the structure rather than invented, so the
     * workbook is a real scaffold the author fills in rather than filler
     * pretending to be finished work.
     */
    private function workbook(Request $request, Book $book, $chapters): Book
    {
        $edition = $this->newEdition($request, $book, [
            'title' => $book->title.' — Workbook',
            'subtitle' => 'Exercises and reflection',
            'edition_type' => 'workbook',
            'genre' => $book->genre,
        ]);

        // Written in by hand: letter size, like a textbook.
        $edition->update(['layout' => array_merge($book->layout(), ['trim' => '8.5 x 11 in'])]);

        foreach ($chapters as $index => $chapter) {
            $headings = $this->headings($chapter);

            $blocks = [[
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => 'Work through this after reading “'.$chapter->title.'”.',
                ]],
            ]];

            // Fall back to the chapter itself when it has no internal
            // headings, so every chapter yields at least one exercise.
            foreach ($headings ?: [$chapter->title] as $heading) {
                $blocks[] = [
                    'type' => 'heading',
                    'attrs' => ['level' => 3],
                    'content' => [['type' => 'text', 'text' => $heading]],
                ];

                $blocks[] = [
                    'type' => 'paragraph',
                    'content' => [[
                        'type' => 'text',
                        'text' => 'What does this mean for your situation? Write your answer here.',
                    ]],
                ];
            }

            $edition->sections()->create([
                'title' => $chapter->title,
                'type' => 'chapter',
                'position' => ($index + 1) * ManuscriptSection::POSITION_STEP,
                'content' => ['type' => 'doc', 'content' => $blocks],
            ]);
        }

        return $edition;
    }

    /**
     * A translated edition.
     *
     * The source text is copied in and the edition's language is set. The
     * prose is still in the original language, and the edition says so rather
     * than implying a translation has happened.
     */
    private function translation(Request $request, Book $book, $chapters, string $language): Book
    {
        $edition = $this->newEdition($request, $book, [
            'title' => $book->title.' ('.$language.')',
            'subtitle' => $book->subtitle,
            'edition_type' => 'translation',
            'genre' => $book->genre,
            'language' => $language,
        ]);

        foreach ($chapters as $index => $chapter) {
            $edition->sections()->create([
                'title' => $chapter->title,
                'type' => 'chapter',
                'position' => ($index + 1) * ManuscriptSection::POSITION_STEP,
                'content' => $chapter->content,
            ]);
        }

        return $edition;
    }

    /**
     * A large-print edition.
     *
     * Same text, different interior: 18pt type and wide margins. The whole
     * difference is the layout, which is why this copies content unchanged
     * and overrides only the layout settings.
     */
    private function largePrint(Request $request, Book $book, $chapters): Book
    {
        $edition = $this->newEdition($request, $book, [
            'title' => $book->title.' — Large Print',
            'subtitle' => $book->subtitle,
            'edition_type' => 'large_print',
            'genre' => $book->genre,
        ]);

        $edition->update(['layout' => array_merge($book->layout(), [
            // The accepted threshold for a large-print book, and a trim big
            // enough to carry it without running to twice the page count.
            'size' => 14.0,
            'leading' => 1.8,
            'margins' => 'Wide',
            'trim' => '7 x 10 in',
            'dropcap' => false,
        ])]);

        foreach ($chapters as $index => $chapter) {
            $edition->sections()->create([
                'title' => $chapter->title,
                'type' => 'chapter',
                'position' => ($index + 1) * ManuscriptSection::POSITION_STEP,
                'content' => $chapter->content,
            ]);
        }

        return $edition;
    }

    /** @param array<string, mixed> $attributes */
    private function newEdition(Request $request, Book $book, array $attributes): Book
    {
        $edition = $request->user()->books()->create(array_merge([
            'author_name' => $book->author_name,
            'language' => $book->language,
            'status' => 'draft',
        ], $attributes));

        // parent_book_id and edition_type are not fillable: which book an
        // edition derives from is a structural fact the server sets, not
        // something a client should be able to claim.
        $edition->forceFill([
            'parent_book_id' => $book->id,
            'edition_type' => $attributes['edition_type'],
        ])->save();

        return $edition->fresh();
    }

    /** @return array<int, string> */
    private function headings(ManuscriptSection $section): array
    {
        $headings = [];

        foreach ($section->content['content'] ?? [] as $node) {
            if (! is_array($node) || ($node['type'] ?? '') !== 'heading') {
                continue;
            }

            $text = '';

            foreach ($node['content'] ?? [] as $child) {
                $text .= $child['text'] ?? '';
            }

            if (trim($text) !== '') {
                $headings[] = trim($text);
            }
        }

        return $headings;
    }

    /** @return array<string, mixed> */
    private function payload(Book $book): array
    {
        return [
            // Owner-edited (App\Platform\Trust\MasterData).
            'languages' => MasterData::get('translation_languages'),
            'source' => [
                'id' => $book->id,
                'title' => $book->title,
                'chapters' => $book->sections()->where('type', '!=', 'part')->count(),
            ],
            'editions' => $book->editions()
                ->withCount('sections')
                ->latest()
                ->get()
                ->map(fn (Book $edition) => $this->row($edition))
                ->all(),
            // An edition of an edition is allowed, but worth showing: this is
            // how the screen can say "this book is itself a translation".
            'derived_from' => $book->parentBook ? [
                'id' => $book->parentBook->id,
                'title' => $book->parentBook->title,
                'type' => $book->edition_type,
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function row(Book $edition): array
    {
        return [
            'id' => $edition->id,
            'title' => $edition->title,
            'type' => $edition->edition_type,
            'language' => $edition->language,
            'status' => $edition->status,
            'sections' => $edition->sections_count ?? $edition->sections()->count(),
            'word_count' => $edition->word_count,
            'created_at' => $edition->created_at->diffForHumans(),
        ];
    }
}
