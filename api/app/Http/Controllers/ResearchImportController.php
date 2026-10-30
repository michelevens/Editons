<?php

namespace App\Http\Controllers;

use App\I18n\Locales;
use App\Models\Book;
use App\Models\ResearchSource;
use App\Research\Doi\DoiLookup;
use App\Research\Doi\DoiLookupFailed;
use App\Research\Import\BibTex;
use App\Research\Import\BibTexExport;
use App\Research\Import\Duplicates;
use App\Research\Import\Entry;
use App\Research\Import\Ris;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Filling a research library from elsewhere: a BibTeX or RIS file, or a DOI.
 *
 * Two steps for a file, and the second re-reads the file rather than
 * trusting rows sent back from the browser: preview parses it and says what
 * is new and what the library already has; import takes the same file plus
 * the rows the author ticked. Either way every record arrives unverified —
 * a file or a registry can say a work exists, never that it supports a
 * claim, so verification stays the separate act it is in ResearchController.
 */
class ResearchImportController extends Controller
{
    /** Parse a file; nothing is saved. */
    public function preview(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        [$format, $entries, $skipped, $truncated] = $this->read($request);
        $seen = $this->library($book);

        $rows = [];
        foreach ($entries as $index => $entry) {
            $duplicate = $seen->of($entry);
            $seen->add($entry, Locales::t('research.import_earlier_in_file', ['title' => $entry['title']]));
            $rows[] = $entry + ['index' => $index, 'duplicate_of' => $duplicate];
        }

        return response()->json([
            'format' => $format,
            'entries' => $rows,
            'skipped' => $skipped,
            'truncated' => $truncated,
        ]);
    }

    /** Import the ticked rows of the same file, skipping anything already there. */
    public function import(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $request->validate([
            'only' => ['sometimes', 'array', 'max:'.Entry::MAX_ENTRIES],
            'only.*' => ['integer', 'min:0'],
            'collection' => ['nullable', 'string', 'max:80'],
        ]);

        [$format, $entries] = $this->read($request);
        $only = $request->has('only') ? array_flip(array_map('intval', $request->input('only', []))) : null;
        $collection = Entry::text((string) $request->input('collection', '')) ?: null;
        $seen = $this->library($book);
        $imported = 0;
        $duplicates = 0;

        DB::transaction(function () use ($book, $request, $entries, $only, $seen, $format, $collection, &$imported, &$duplicates) {
            foreach ($entries as $index => $entry) {
                if ($only !== null && ! isset($only[$index])) {
                    continue;
                }

                if ($seen->of($entry) !== null) {
                    $duplicates++;

                    continue;
                }

                $source = $book->sources()->make(collect($entry)->except('kind')->all());
                // Server-set, never from the file: who, how, and the standing
                // every new source starts with.
                $source->kind = $entry['kind'];
                $source->collection = $collection;
                $source->added_by = $request->user()->id;
                $source->origin = $format;
                $source->verification = 'unverified';
                $source->save();

                $seen->add($entry, $entry['title']);
                $imported++;
            }
        });

        return response()->json([
            'sources' => ResearchController::sourcesFor($book),
            'facets' => ResearchController::facets($book),
            'imported' => $imported,
            'duplicates' => $duplicates,
        ], $imported > 0 ? 201 : 200);
    }

    /**
     * Look a DOI up in Crossref and hand back a filled-in form, unsaved. The
     * author reviews it and adds it like any other source.
     */
    public function lookup(Request $request, Book $book, DoiLookup $registry): JsonResponse
    {
        $this->authorize('update', $book);

        $request->validate(['doi' => ['required', 'string', 'max:255']]);
        $doi = Entry::doi($request->string('doi')->toString());

        if ($doi === null) {
            throw ValidationException::withMessages(['doi' => Locales::t('research.doi_invalid')]);
        }

        try {
            $record = $registry->find($doi);
        } catch (DoiLookupFailed) {
            return response()->json(['message' => Locales::t('research.doi_unreachable')], 503);
        }

        $entry = $record ? Entry::clean($record + ['doi' => $doi]) : null;

        if ($entry === null) {
            throw ValidationException::withMessages(['doi' => Locales::t('research.doi_not_found')]);
        }

        return response()->json([
            'entry' => $entry,
            'duplicate_of' => $this->library($book)->of($entry),
        ]);
    }

    /** The whole library as a .bib file. */
    public function export(Book $book): Response
    {
        $this->authorize('view', $book);

        $name = (Str::slug($book->title) ?: 'library').'.bib';

        return response(BibTexExport::render($book->sources()->orderBy('title')->get()), 200, [
            'Content-Type' => 'application/x-bibtex; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The uploaded file, parsed and cleaned.
     *
     * @return array{0: string, 1: array<int, array<string, string|null>>, 2: int, 3: bool}
     */
    private function read(Request $request): array
    {
        $request->validate([
            'file' => ['required', 'file', 'max:'.intdiv(Entry::MAX_BYTES, 1024)],
            'format' => ['nullable', 'in:bibtex,ris'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $text = (string) file_get_contents($file->getRealPath());

        // A binary file (a PDF, a .docx) is not a bibliography, whatever
        // its name says.
        if (str_contains($text, "\0")) {
            throw ValidationException::withMessages(['file' => Locales::t('research.import_unreadable')]);
        }

        // Older EndNote and RIS exports are Windows-1252, not UTF-8.
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }

        $format = $request->input('format') ?? $this->sniff($file, $text);

        $raw = $format === 'ris'
            ? Ris::parse($text, Entry::MAX_ENTRIES + 1)
            : BibTex::parse($text, Entry::MAX_ENTRIES + 1);

        $truncated = count($raw) > Entry::MAX_ENTRIES;
        $raw = array_slice($raw, 0, Entry::MAX_ENTRIES);

        $entries = [];
        $skipped = 0;
        foreach ($raw as $index => $record) {
            $clean = Entry::clean($record);
            $clean === null ? $skipped++ : $entries[$index] = $clean;
        }

        if ($entries === []) {
            throw ValidationException::withMessages(['file' => Locales::t('research.import_empty')]);
        }

        return [$format, $entries, $skipped, $truncated];
    }

    private function sniff(UploadedFile $file, string $text): string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, ['ris', 'enw', 'nbib'], true)) {
            return 'ris';
        }

        if ($extension === 'bib' || $extension === 'bibtex') {
            return 'bibtex';
        }

        return preg_match('/^\s*TY  - /m', $text) ? 'ris' : 'bibtex';
    }

    /** What the library already holds, for de-duplication. */
    private function library(Book $book): Duplicates
    {
        return new Duplicates(
            $book->sources()->get(['title', 'authors', 'published', 'publisher', 'doi', 'url'])
                ->map(fn (ResearchSource $s) => ['title' => $s->title, 'doi' => $s->doi, 'url' => $s->url, 'label' => $s->title])
        );
    }
}
