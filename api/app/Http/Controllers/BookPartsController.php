<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Publishing\Anatomy;
use App\Publishing\BookPart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * The Book parts checklist: which conventional parts a book has, adding a
 * missing one from its template, putting the binder in conventional order,
 * and naming a section's part from the binder.
 *
 * JSON throughout: the checklist and the binder update in place, and a page
 * visit from the binder would reload the chapter being edited.
 */
class BookPartsController extends Controller
{
    public function show(Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        return response()->json(Anatomy::checklist($book));
    }

    /** What reordering would do. Nothing moves. */
    public function preview(Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        return response()->json(Anatomy::reorderPreview($book));
    }

    public function reorder(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate(['fingerprint' => ['required', 'string', 'size:40']]);

        if (! Anatomy::reorder($book, $data['fingerprint'])) {
            return response()->json([
                'message' => 'The binder changed since the preview. Check the new preview before reordering.',
                'preview' => Anatomy::reorderPreview($book),
            ], 409);
        }

        return response()->json(Anatomy::checklist($book));
    }

    /**
     * The author's own order, from the binder's drag-and-drop or its move
     * up/down controls: one level at a time (the top level, or the chapters
     * inside one part).
     */
    public function arrange(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'parent_id' => ['nullable', 'integer'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        // The parent must be a section of THIS book, like sections.store.
        $parentId = null;
        if (! empty($data['parent_id'])) {
            $parentId = $book->sections()->whereKey($data['parent_id'])->firstOrFail()->id;
        }

        if (! Anatomy::arrange($book, $parentId, $data['ids'])) {
            return response()->json([
                'message' => 'The binder changed since it was loaded. Reload it and move the chapter again.',
            ], 409);
        }

        return response()->json([
            'order' => $book->sections()
                ->where('parent_id', $parentId)
                ->orderBy('position')
                ->orderBy('id')
                ->pluck('id'),
        ]);
    }

    public function store(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'part' => ['required', Rule::enum(BookPart::class)],
        ]);

        try {
            $section = Anatomy::add($book, BookPart::from($data['part']));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['part' => [$e->getMessage()]]], 422);
        }

        return response()->json([
            'section' => ['id' => $section->id, 'title' => $section->title, 'type' => $section->type, 'part' => $section->part],
            'checklist' => Anatomy::checklist($book),
        ], 201);
    }

    /** Name (or un-name) a section's part from the binder. */
    public function assign(Request $request, ManuscriptSection $section): JsonResponse
    {
        $this->authorize('update', $section);

        $data = $request->validate([
            'part' => ['present', 'nullable', Rule::enum(BookPart::class)->except([BookPart::BackCoverCopy])],
        ]);

        try {
            $section = Anatomy::assign($section, $data['part'] ? BookPart::from($data['part']) : null);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['part' => [$e->getMessage()]]], 422);
        }

        return response()->json(['id' => $section->id, 'type' => $section->type, 'part' => $section->part]);
    }
}
