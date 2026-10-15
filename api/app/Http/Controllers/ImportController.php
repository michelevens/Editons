<?php

namespace App\Http\Controllers;

use App\Import\MarkdownExporter;
use App\Import\MarkdownImporter;
use App\Models\Book;
use App\Publishing\PortableBook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ImportController extends Controller
{
    public function __construct(
        private readonly MarkdownImporter $importer,
        private readonly MarkdownExporter $exporter,
        private readonly PortableBook $portable,
    ) {}

    /**
     * Download a book as a .ecrivain file.
     *
     * The only format that round-trips. Every other export loses the binder
     * tree, the journey state or the idea; this one is the book.
     */
    public function portableExport(Book $book): BinaryFileResponse
    {
        $this->authorize('view', $book);

        [$path, $name] = $this->portable->export($book);

        return response()->download($path, $name)->deleteFileAfterSend();
    }

    /**
     * Create a book from a .ecrivain file.
     *
     * Always a new book, never an overwrite: matching a title and replacing
     * what is there is the kind of helpfulness that loses somebody's work.
     * The importing account's ownership and plan limit both apply.
     */
    public function portableImport(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:25600'],
        ]);

        abort_unless(
            $request->user()->canCreateBook(),
            403,
            'This plan does not allow another book. Archive one, or upgrade.',
        );

        try {
            $book = $this->portable->import($request->file('file'), $request->user()->id);
        } catch (Throwable $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return redirect()
            ->route('books.edit', $book)
            ->with('status', "Imported “{$book->title}”.");
    }

    /**
     * Import a Markdown manuscript, appending to the binder.
     *
     * Accepts either an uploaded file or pasted text. The response carries
     * the import report, including what could not be carried — an author who
     * uploads 90,000 words needs to see that before they start editing, not
     * at proof stage.
     */
    public function store(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'file' => ['nullable', 'file', 'mimetypes:text/plain,text/markdown,text/x-markdown', 'max:10240'],
            'markdown' => ['nullable', 'string', 'max:5000000'],
        ]);

        $markdown = isset($data['file'])
            ? (string) file_get_contents($data['file']->getRealPath())
            : (string) ($data['markdown'] ?? '');

        if (trim($markdown) === '') {
            abort(422, 'Nothing to import.');
        }

        // The manuscript is content, never instructions — the same rule the
        // editorial drivers follow. Nothing here interprets it.
        $report = $this->importer->import($book, $markdown, $request->user()->id);

        return response()->json([
            'report' => $report->toArray(),
            'book' => ['id' => $book->refresh()->id, 'word_count' => $book->word_count],
        ], 201);
    }

    /**
     * Export the manuscript back to Markdown.
     *
     * This is the other half of the round-trip, not the Milestone 3 export
     * pipeline — no edition themes, pagination or validation. Offering it
     * here means an author can always get their text out in a format nothing
     * else needs to interpret.
     */
    public function export(Book $book): StreamedResponse
    {
        $this->authorize('view', $book);

        $markdown = $this->exporter->book($book);
        $name = Str::slug($book->title ?: 'manuscript').'.md';

        return response()->streamDownload(
            fn () => print ($markdown),
            $name,
            ['Content-Type' => 'text/markdown; charset=UTF-8'],
        );
    }
}
