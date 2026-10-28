<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\HouseStyle;
use App\Publishing\HouseStyles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * House styles from the studio: save a book's design under a name, update
 * a style from a book, apply one to books (one, or several from the
 * shelf), detach a book, rename and delete (App\Publishing\HouseStyles).
 * The console's House catalog applies them to house books with its own
 * audited bulk action (HouseCatalog).
 */
class HouseStyleController extends Controller
{
    public function __construct(private readonly HouseStyles $styles) {}

    /** The signed-in account's styles, for the shelf's bulk action. */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'styles' => HouseStyle::where('user_id', $request->user()->id)->withCount('books')->orderBy('name')->get()
                ->map(fn (HouseStyle $style) => [
                    'id' => $style->id, 'name' => $style->name, 'version' => $style->version,
                    'books' => $style->books_count, 'summary' => HouseStyles::describe($style->design),
                ])->all(),
        ]);
    }

    /** "Save as house style". */
    public function store(Request $request, Book $book): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'follow' => ['sometimes', 'boolean'],
        ]);

        $style = $this->styles->save($book, $request->user(), $data['name'], $data['follow'] ?? true);

        return response()->json(['style' => ['id' => $style->id, 'name' => $style->name], 'house_style' => HouseStyles::summary($book->fresh(), $request->user())], 201);
    }

    /** "Update the style from this book": every book that follows it changes. */
    public function update(Request $request, HouseStyle $style): JsonResponse
    {
        $data = $request->validate(['book' => ['required', 'integer']]);
        $book = Book::findOrFail($data['book']);
        $count = $this->styles->update($style, $book, $request->user());

        return response()->json(['updated' => $count, 'house_style' => HouseStyles::summary($book->fresh(), $request->user())]);
    }

    public function rename(Request $request, HouseStyle $style): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:80']]);
        $this->styles->rename($style, $request->user(), $data['name']);

        return response()->json(['style' => ['id' => $style->id, 'name' => $style->name]]);
    }

    public function destroy(Request $request, HouseStyle $style): JsonResponse
    {
        $this->styles->delete($style, $request->user());

        return response()->json(['deleted' => true]);
    }

    /** Apply to one book or several; each book's result on its own. */
    public function apply(Request $request, HouseStyle $style): JsonResponse
    {
        $data = $request->validate([
            'books' => ['required', 'array', 'min:1', 'max:200'],
            'books.*' => ['integer'],
            'follow' => ['sometimes', 'boolean'],
        ]);

        $results = $this->styles->applyMany($style, $data['books'], $request->user(), $data['follow'] ?? true);

        return response()->json(['results' => $results]);
    }

    /** "Customise this book": stop following, keep the design. */
    public function detach(Request $request, Book $book): JsonResponse
    {
        $this->styles->detach($book, $request->user());

        return response()->json(['house_style' => HouseStyles::summary($book->fresh(), $request->user())]);
    }
}
