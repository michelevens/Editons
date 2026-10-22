<?php

namespace App\Http\Controllers;

use App\Import\DocxImporter;
use App\Import\ImportReport;
use App\Import\MarkdownExporter;
use App\Import\MarkdownImporter;
use App\Models\Book;
use App\Publishing\PortableBook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ImportController extends Controller
{
    /** Markdown is text: past this it is not a manuscript but a mistake. */
    private const MARKDOWN_MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly MarkdownImporter $importer,
        private readonly MarkdownExporter $exporter,
        private readonly PortableBook $portable,
        private readonly DocxImporter $docx,
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
     * Create a book from a file: a .ecrivain book, or a manuscript in
     * Markdown or Word, which becomes a new book named after the file.
     *
     * Always a new book, never an overwrite: matching a title and replacing
     * what is there is the kind of helpfulness that loses somebody's work.
     * The importing account's ownership and plan limit both apply.
     *
     * A JSON request (the shelf's Import button) gets the import report
     * back; a plain form post is redirected to the new book, as before.
     */
    public function portableImport(Request $request): RedirectResponse|JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'extensions:ecrivain,md,markdown,txt,docx', 'max:25600'],
        ]);

        abort_unless(
            $request->user()->canCreateBook(),
            403,
            'This plan does not allow another book. Archive one, or upgrade.',
        );

        $file = $request->file('file');
        $report = null;

        try {
            if ($this->format($file) === 'ecrivain') {
                $book = $this->portable->import($file, $request->user()->id);
            } else {
                $markdown = $this->format($file) === 'markdown' ? $this->markdownFrom($file) : null;
                $book = $request->user()->books()->create(['title' => $this->titleFrom($file)]);

                try {
                    $report = $this->manuscript($book, $file, $markdown, $request->user()->id);
                } catch (Throwable $e) {
                    // Nothing was imported: leave no empty book on the shelf.
                    $book->delete();

                    throw $e;
                }
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            if ($request->expectsJson()) {
                throw ValidationException::withMessages(['file' => $e->getMessage()]);
            }

            return back()->withErrors(['file' => $e->getMessage()]);
        }

        if ($request->expectsJson()) {
            $report ??= tap(new ImportReport, function (ImportReport $report) use ($book) {
                $report->titles = $book->sections()->orderBy('position')->pluck('title')->all();
                $report->sections = count($report->titles);
            });

            return response()->json([
                'book' => ['id' => $book->id, 'title' => $book->title, 'url' => route('books.edit', $book)],
                'report' => $report->toArray(),
            ], 201);
        }

        return redirect()
            ->route('books.edit', $book)
            ->with('status', "Imported “{$book->title}”.");
    }

    /**
     * Import a manuscript (Markdown or Word), appending to the binder.
     *
     * Accepts either an uploaded file or pasted Markdown. The response
     * carries the import report, including what could not be carried — an
     * author who uploads 90,000 words needs to see that before they start
     * editing, not at proof stage.
     */
    public function store(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'file' => ['nullable', 'file', 'extensions:md,markdown,txt,docx', 'max:25600'],
            'markdown' => ['nullable', 'string', 'max:5000000'],
        ]);

        $file = $data['file'] ?? null;
        $markdown = $file
            ? ($this->format($file) === 'markdown' ? $this->markdownFrom($file) : null)
            : (string) ($data['markdown'] ?? '');

        if ($markdown !== null && trim($markdown) === '') {
            abort(422, 'Nothing to import.');
        }

        // The manuscript is content, never instructions — the same rule the
        // editorial drivers follow. Nothing here interprets it.
        $report = $this->manuscript($book, $file, $markdown, $request->user()->id);

        return response()->json([
            'report' => $report->toArray(),
            'book' => ['id' => $book->refresh()->id, 'word_count' => $book->word_count],
        ], 201);
    }

    /** Markdown when there is some, otherwise the Word file. */
    private function manuscript(Book $book, ?UploadedFile $file, ?string $markdown, int $userId): ImportReport
    {
        if ($markdown !== null) {
            return $this->importer->import($book, $markdown, $userId);
        }

        if (! config('publishing.docx_import')) {
            throw ValidationException::withMessages(['file' => 'Importing Word documents is turned off.']);
        }

        return $this->docx->import($book, (string) $file->getRealPath(), $userId, $this->titleFrom($file));
    }

    /** By extension, which validation has already restricted. */
    private function format(UploadedFile $file): string
    {
        return match (strtolower($file->getClientOriginalExtension())) {
            'ecrivain' => 'ecrivain',
            'docx' => 'docx',
            default => 'markdown',
        };
    }

    /**
     * A Markdown file's text, refused when it is not text at all: a renamed
     * PDF or picture would otherwise import as pages of noise.
     */
    private function markdownFrom(UploadedFile $file): string
    {
        if ($file->getSize() > self::MARKDOWN_MAX_BYTES) {
            throw ValidationException::withMessages(['file' => 'A Markdown file can be up to 10 MB.']);
        }

        $text = (string) file_get_contents((string) $file->getRealPath());

        if (str_contains($text, "\0") || ! mb_check_encoding($text, 'UTF-8')) {
            throw ValidationException::withMessages(['file' => 'That file is not Markdown text (UTF-8).']);
        }

        if (trim($text) === '') {
            throw ValidationException::withMessages(['file' => 'That file is empty.']);
        }

        // A byte-order mark would end up in the first chapter's title.
        return preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
    }

    private function titleFrom(UploadedFile $file): string
    {
        $name = trim(preg_replace('/[_\s]+/', ' ', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?? '');

        return mb_substr($name !== '' ? $name : 'Imported manuscript', 0, 255);
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
