<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Workspace\Workspace;
use App\Workspace\WorkspaceSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The writing workspace: which studio panels an author shows, their sizes,
 * and how the canvas reads (App\Workspace\Workspace).
 *
 * JSON, because the studio saves as the author drags and toggles, behind
 * them, and never waits for the answer before carrying on.
 */
class WorkspaceController extends Controller
{
    /** The layout in force, for the author or for one of their books. */
    public function show(Request $request): JsonResponse
    {
        return response()->json(Workspace::for($request->user(), $this->book($request)));
    }

    /** Save the layout, everywhere (scope user) or for one book (scope book). */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settings' => ['present', 'array'],
            'scope' => ['sometimes', Rule::in(['user', 'book'])],
            'book' => [Rule::requiredIf($request->input('scope') === 'book'), 'nullable', 'integer'],
        ]);

        // settings is validated key by key by the schema, which refuses
        // anything it does not name; Laravel's rules above only see that it
        // is there. Read raw so validate() has not reshaped it.
        return response()->json(Workspace::save(
            $request->user(),
            $request->input('settings'),
            $data['scope'] ?? 'user',
            $this->book($request),
        ));
    }

    /** Back to the default layout (named layouts are kept). */
    public function destroy(Request $request): JsonResponse
    {
        return response()->json(Workspace::reset($request->user(), $this->book($request)));
    }

    /** Stop using a book's own layout. */
    public function forgetBook(Request $request, Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        return response()->json(Workspace::forget($request->user(), $book));
    }

    public function storePreset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:'.WorkspaceSchema::PRESET_NAME_MAX, 'regex:/\S/'],
            'layout' => ['present', 'array'],
        ]);

        return response()->json([
            'presets' => Workspace::addPreset($request->user(), $data['name'], $request->input('layout')),
        ]);
    }

    public function destroyPreset(Request $request, string $preset): JsonResponse
    {
        return response()->json([
            'presets' => Workspace::removePreset($request->user(), $preset),
        ]);
    }

    /** The book named by ?book= or the body, if the author may open it. */
    private function book(Request $request): ?Book
    {
        $id = $request->input('book');

        if ($id === null || $id === '') {
            return null;
        }

        $book = Book::findOrFail((int) $id);

        $this->authorize('view', $book);

        return $book;
    }
}
