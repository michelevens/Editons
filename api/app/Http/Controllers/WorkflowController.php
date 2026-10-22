<?php

namespace App\Http\Controllers;

use App\Mail\Notify;
use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

        $book = $section->book;

        // Only somebody who can already reach the book. A bare
        // exists:users,id let any author assign any account and read its
        // name back from the board, which is a directory of every user.
        $data = $request->validate([
            'reviewer_id' => [
                'nullable',
                'integer',
                Rule::in($this->assignable($book)),
            ],
        ]);

        $section->update($data);

        // Only a real change of reviewer, and only to somebody who can open
        // the book: an email about a book you cannot see would leak its title.
        if ($section->wasChanged('reviewer_id') && $section->reviewer_id) {
            $reviewer = User::find($section->reviewer_id);

            if ($reviewer && $reviewer->can('view', $section->book)) {
                Notify::reviewerAssigned($section, $reviewer, $request->user());
            }
        }

        return response()->json(['chapters' => $this->board($book)]);
    }

    /**
     * The owner and the active members: the people a chapter can go to.
     *
     * @return array<int, int>
     */
    private function assignable(Book $book): array
    {
        return $book->members()
            ->where('status', 'active')
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->push($book->user_id)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
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
