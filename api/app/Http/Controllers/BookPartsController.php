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
