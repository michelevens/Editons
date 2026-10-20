<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Publishing\BookImages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Upload a picture into a manuscript, and serve it back to the editor.
 */
class BookImageController extends Controller
{
    public function __construct(private readonly BookImages $images) {}

    public function store(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $request->validate([
            // Explicit types, not `image`: that rule admits SVG, which can
            // carry script and would be served back to collaborators.
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:15360'],
        ]);

        return response()->json($this->images->store($book->id, $request->file('image')), 201);
    }

    public function show(Book $book, string $name): StreamedResponse
    {
        $this->authorize('view', $book);

        abort_unless(BookImages::validName($name), 404);

        $path = $this->images->path($book->id, $name);
        abort_unless(Storage::disk('files')->exists($path), 404);

        return Storage::disk('files')->response($path, null, [
            'Content-Type' => BookImages::MIMES[pathinfo($name, PATHINFO_EXTENSION)] ?? 'application/octet-stream',
            // The name is random and never reused, so it can be cached for good.
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
