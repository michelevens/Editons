<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Publishing\Exporter;
use App\Publishing\InteriorFonts;
use App\Publishing\PreviewCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The full-screen book preview: the book as a phone, an e-reader and a
 * printed page will show it.
 *
 * Nothing here formats a book. The reflowable modes get the EPUB's own
 * documents and stylesheet, Print gets PrintBook's blocks and stylesheet
 * (both through Exporter), so a preview cannot disagree with an export.
 * The contents come first and each page is fetched as it is needed, which
 * is what keeps a 100k-word book from arriving, or being laid out, at once.
 *
 * Gated on view: a reviewer reading a proof is the point of inviting one.
 */
class PreviewController extends Controller
{
    /** The faces Print mode sets in, served to the browser (SIL OFL). */
    public function __construct(private readonly Exporter $exporter) {}

    public function show(Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        return response()->json([
            'book' => ['id' => $book->id, 'title' => $book->title],
            'layout' => $book->layout(),
            'reader' => [
                'items' => $this->exporter->readerContents($book),
                'css' => $this->exporter->readerCss($book),
            ],
            'print' => $this->exporter->printContents($book),
        ]);
    }

    /** One document (reader) or block (print), with what the server sees wrong in it. */
    public function page(Request $request, Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        $data = $request->validate([
            'mode' => ['required', 'in:reader,print'],
            'key' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/'],
        ]);

        $html = $data['mode'] === 'print'
            ? $this->exporter->printPage($book, $data['key'])
            : $this->exporter->readerPage($book, $data['key']);

        abort_if($html === null, 404, 'That page is no longer in the book.');

        $checked = PreviewCheck::inspect($html, $book->id, $data['mode']);

        return response()->json([
            'key' => $data['key'],
            'mode' => $data['mode'],
            'html' => $checked['html'],
            'warnings' => $checked['warnings'],
        ]);
    }

    /** A typeface for Print mode. Public files, cached for a year. */
    public function font(string $name): BinaryFileResponse
    {
        // Only the interior faces (InteriorFonts), by their file name.
        $file = InteriorFonts::files()[$name] ?? abort(404);

        return response()->file(resource_path('fonts/'.$file), [
            'Content-Type' => 'font/ttf',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
