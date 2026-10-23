<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Services\WritingProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Writing goals: a word goal per chapter, a daily goal per person, and the
 * figures the goals sheet shows against them.
 *
 * Every figure comes from saved state — the chapter's word count, the
 * book's target, and version history for the day and the streak
 * (App\Services\WritingProgress, the same arithmetic as Analytics). Nothing
 * is counted on the client and sent back.
 */
class GoalsController extends Controller
{
    /** The largest goal accepted: a chapter longer than this is a book. */
    public const MAX_CHAPTER_GOAL = 1_000_000;

    /** The goals sheet for a book, about one of its chapters. */
    public function show(Request $request, Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        $section = $request->filled('section')
            // A chapter of THIS book only; another book's id is a 404.
            ? $book->sections()->whereKey($request->integer('section'))->firstOrFail()
            : null;

        return response()->json(WritingProgress::goals($request->user(), $book, $section));
    }

    /** Set or clear (null) a chapter's word goal. */
    public function chapter(Request $request, ManuscriptSection $section): JsonResponse
    {
        $this->authorize('update', $section);

        $data = $request->validate([
            'goal' => ['present', 'nullable', 'integer', 'min:1', 'max:'.self::MAX_CHAPTER_GOAL],
        ]);

        // Parts hold chapters; their size is their chapters'. A goal on one
        // would be a second, competing count.
        abort_if($section->type === 'part' && $data['goal'] !== null, 422, 'A part has no word goal of its own; set one on its chapters.');

        $section->forceFill(['word_goal' => $data['goal']])->save();

        return response()->json([
            'id' => $section->id,
            'goal' => $section->word_goal,
            'words' => (int) $section->word_count,
        ]);
    }

    /**
     * Set or clear (null) the signed-in person's daily goal.
     *
     * The same column and the same limits as the profile form
     * (ProfileUpdateRequest), reachable from the studio without leaving it.
     */
    public function daily(Request $request): JsonResponse
    {
        $data = $request->validate([
            'goal' => ['present', 'nullable', 'integer', 'min:50', 'max:20000'],
        ]);

        $user = $request->user();
        $user->forceFill(['daily_word_goal' => $data['goal']])->save();

        return response()->json(WritingProgress::forUser($user));
    }
}
