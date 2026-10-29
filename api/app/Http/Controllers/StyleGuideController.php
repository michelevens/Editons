<?php

namespace App\Http\Controllers;

use App\Editorial\Style\StyleGuides;
use App\Models\Book;
use App\Models\StyleGuide;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Style guides from the studio (App\Editorial\Style\StyleGuides; ADR —
 * Style guides and review options): the book's guide and its own rules,
 * creating and saving guides of the book's account, following and
 * "Customise for this book".
 */
class StyleGuideController extends Controller
{
    public function __construct(private readonly StyleGuides $guides) {}

    /** The book's style: what it follows, its own rules, the account's guides. */
    public function show(Request $request, Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        return response()->json(['style' => $this->guides->summary($book, $request->user())]);
    }

    /** A new guide of the book's account, optionally followed by the book at once. */
    public function store(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'rules' => ['sometimes', 'array'],
            'follow' => ['sometimes', 'boolean'],
        ]);

        $guide = $this->guides->create($book->user, $request->user(), $data['name'], $data['rules'] ?? []);

        if ($data['follow'] ?? true) {
            $this->guides->follow($guide, $book, $request->user());
        }

        return response()->json(['guide' => StyleGuides::present($guide), 'style' => $this->guides->summary($book->fresh(), $request->user())], 201);
    }

    /** Save a guide's rules as its next version (and rename it). */
    public function update(Request $request, StyleGuide $guide): JsonResponse
    {
        $data = $request->validate([
            'rules' => ['required', 'array'],
            'name' => ['sometimes', 'string', 'min:2', 'max:80'],
            'book' => ['sometimes', 'integer'],
        ]);

        $guide = $this->guides->save($guide, $request->user(), $data['rules'], $data['name'] ?? null);
        $book = isset($data['book']) ? Book::find($data['book']) : null;

        return response()->json([
            'guide' => StyleGuides::present($guide),
            'style' => $book && $request->user()->can('view', $book) ? $this->guides->summary($book, $request->user()) : null,
        ]);
    }

    public function destroy(Request $request, StyleGuide $guide): JsonResponse
    {
        $this->guides->delete($guide, $request->user());

        return response()->json(['deleted' => true]);
    }

    /** The book follows a guide of its account. */
    public function follow(Request $request, Book $book): JsonResponse
    {
        $data = $request->validate(['guide' => ['required', 'integer']]);
        $guide = StyleGuide::find($data['guide']);
        abort_unless($guide, 422, 'No such style guide.');

        $this->guides->follow($guide, $book, $request->user());

        return response()->json(['style' => $this->guides->summary($book->fresh(), $request->user())]);
    }

    /** "Customise for this book": stop following, keep the rules as the book's own. */
    public function detach(Request $request, Book $book): JsonResponse
    {
        $this->guides->detach($book, $request->user());

        return response()->json(['style' => $this->guides->summary($book->fresh(), $request->user())]);
    }

    /** The book's own rules over its guide (dictionary, any rule). */
    public function overrides(Request $request, Book $book): JsonResponse
    {
        $data = $request->validate(['rules' => ['required', 'array']]);
        $this->guides->saveOverrides($book, $request->user(), $data['rules']);

        return response()->json(['style' => $this->guides->summary($book->fresh(), $request->user())]);
    }
}
