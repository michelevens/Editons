<?php

namespace App\Http\Controllers;

use App\Manuscript\FindReplace;
use App\Models\Book;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manuscript-wide find, and replace-all across chapters (ADR — Author
 * walkthrough fixes). Find in one chapter happens in the editor itself.
 */
class FindReplaceController extends Controller
{
    public function __construct(private readonly FindReplace $finder) {}

    public function find(Request $request, Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        $data = $request->validate([
            'q' => ['required', 'string', 'max:'.FindReplace::MAX_TERM],
            'case' => ['sometimes', 'boolean'],
            'word' => ['sometimes', 'boolean'],
        ]);

        $sections = $this->finder->find($book, $data['q'], $request->boolean('case'), $request->boolean('word'));

        return response()->json([
            'sections' => $sections,
            'total' => array_sum(array_column($sections, 'count')),
        ]);
    }

    public function replace(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'q' => ['required', 'string', 'max:'.FindReplace::MAX_TERM],
            'replacement' => ['present', 'nullable', 'string', 'max:'.FindReplace::MAX_TERM],
            'case' => ['sometimes', 'boolean'],
            'word' => ['sometimes', 'boolean'],
            // One click changes many chapters: the client asks first.
            'confirmed' => ['accepted'],
            // The open chapter, sent back rewritten so the editor shows it.
            'section' => ['nullable', 'integer'],
        ]);

        $result = $this->finder->replaceAll(
            $book, $data['q'], (string) ($data['replacement'] ?? ''),
            $request->boolean('case'), $request->boolean('word'), $request->user()->id,
        );

        $open = isset($data['section']) && in_array((int) $data['section'], array_column($result['sections'], 'id'), true)
            ? $book->sections()->find($data['section'])
            : null;

        return response()->json($result + [
            'book_word_count' => $book->fresh()->word_count,
            'current' => $open ? ['id' => $open->id, 'content' => $open->content, 'word_count' => $open->word_count] : null,
        ]);
    }
}
