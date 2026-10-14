<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\ManuscriptSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The review board: which chapters are drafted, in review, in revision or
 * approved.
 *
 * The status lives on the section rather than in a separate workflow table.
 * A chapter is in exactly one state at a time, and the history of how it got
 * there is already in its versions — a second table would record the same
 * facts twice and let them disagree.
 */
class WorkflowController extends Controller
{
    public function show(Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        return response()->json([
            'statuses' => ManuscriptSection::STATUSES,
            'chapters' => $this->board($book),
        ]);
    }

    public function move(Request $request, ManuscriptSection $section): JsonResponse
    {
        $this->authorize('update', $section);

        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', ManuscriptSection::STATUSES)],
        ]);

        $section->update($data);

        return response()->json(['chapters' => $this->board($section->book)]);
    }

    public function assign(Request $request, ManuscriptSection $section): JsonResponse
    {
        $this->authorize('update', $section);

        $data = $request->validate([
            'reviewer_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $section->update($data);

        return response()->json(['chapters' => $this->board($section->book)]);
    }

    /** @return array<int, array<string, mixed>> */
    private function board(Book $book): array
    {
        return $book->sections()
            ->where('type', '!=', 'part')
            ->with('reviewer:id,name')
            ->orderBy('position')
            ->get()
            ->map(fn (ManuscriptSection $section) => [
                'id' => $section->id,
                'title' => $section->title,
                'status' => $section->status,
                'word_count' => $section->word_count,
                'reviewer' => $section->reviewer?->only(['id', 'name']),
                'updated_at' => $section->updated_at->diffForHumans(),
            ])
            ->all();
    }
}
