<?php

namespace App\Publishing;

use App\Editorial\Style\Typography;
use App\Inbox\Inbox;
use App\Jobs\GenerateExport;
use App\Mail\Notify;
use App\Models\Book;
use App\Models\BookExport;
use App\Models\ManuscriptSection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use ZipArchive;

/**
 * Generates a book file.
 *
 * Each format is produced for real, not stubbed: an author clicks Generate to
 * get something they can upload to a store, and a placeholder file that opens
 * to nothing wastes a submission cycle. EPUB is therefore a proper container
 * with a manifest and a spine, not a renamed HTML file.
 *
 * Files are written to the private disk. They are the author's manuscript
 * before publication, so they are served through a controller that checks
 * authorisation rather than from a public URL anyone holding the path can read.
 */
class Exporter
{
    public function __construct(
        private DocumentRenderer $renderer,
        private readonly PrintBook $print,
        private readonly CoverRenderer $covers,
        private readonly BookImages $images,
        private readonly CoverWrap $wraps,
    ) {}

    /** Generate one file now and record it: start() then run(). */
    public function generate(Book $book, string $format, ?int $userId = null, array $options = []): BookExport
    {
        return $this->run($this->start($book, $format, $userId, $options));
    }

    /**
     * Notes gathered for the end of the book while one is being made with
     * notes placed there (ExportOptions), else null.
     */
    private ?\ArrayObject $bookNotes = null;

    /**
     * Record a file as `generating`, after checking it can be made.
     *
     * The row exists before any rendering, so a format that throws halfway
     * leaves a visible failure rather than a silent absence. The checks that
     * the author can act on (no such format, nothing written yet) happen
     * here, so they are refused at once even when run() is left to a job.
     *
     * A PDF or EPUB already rendering for this book is returned rather than
     * a second row: each holds a worker for up to half an hour, and a second
     * click used to queue the same book again ahead of everybody else's.
     * The returned row's wasRecentlyCreated says whether to dispatch a job.
     */
    public function start(Book $book, string $format, ?int $userId = null, array $options = []): BookExport
    {
        if (! isset(BookExport::FORMATS[$format])) {
            throw new RuntimeException("There is no {$format} export.");
        }

        if ($this->chapters($book)->isEmpty()) {
            throw new RuntimeException('There is nothing to export yet. Write a chapter first.');
        }

        // The export profile (ExportOptions): sent as input, checked against
        // the book; or handed over already checked, when a file is made
        // again with what it was made with. Full book is stored as nothing.
        $profile = $options['profile'] ?? null;
        $profile = is_array($profile) ? ExportOptions::validate($profile, $book, $format) : ($profile instanceof ExportOptions ? $profile : null);
        $stored = $profile?->toStored();

        // A cover wrap is made for one service and paper; checked here so
        // a choice that cannot work is refused before a worker is taken.
        $options = $format === 'cover_wrap' ? $this->wraps->options($book, $options) : ($stored ? ['profile' => $stored] : []);
        $queued = in_array($format, GenerateExport::FORMATS, true);

        if ($queued) {
            // A dead render must not block the next one for good.
            $this->expireStale($book->id);

            if ($running = $this->rendering($book, $format)) {
                // One cover at a time: a wrap for another service would be
                // a different file, and handing back this one would not be
                // what was asked for.
                if ($format === 'cover_wrap' && array_intersect_key($running->options ?? [], $options) != $options) {
                    throw new RuntimeException('A print cover for '.PrintSpecs::label($running->options['service'] ?? 'kdp').' is being made. Ask for this one when it is ready.');
                }

                // Likewise one profile at a time: a Sample is not the
                // Full book that was asked for.
                if ($format !== 'cover_wrap' && ($running->options['profile'] ?? null) != $stored) {
                    throw new RuntimeException('A '.$running->label().' of this book is being made. Ask for this one when it is ready.');
                }

                return $running;
            }
        }

        try {
            // Its own transaction (a savepoint inside another), so a refused
            // insert does not abort a transaction the caller is in.
            return DB::transaction(fn () => $book->exports()->create([
                'format' => $format,
                'status' => 'generating',
                'word_count' => $book->word_count,
                'user_id' => $userId,
                'options' => $options ?: null,
            ]));
        } catch (UniqueConstraintViolationException $e) {
            // Two clicks raced past the check above; the index let one in.
            return $this->rendering($book, $format) ?? throw $e;
        }
    }

    /** The PDF or EPUB of this book now rendering, if there is one. */
    private function rendering(Book $book, string $format): ?BookExport
    {
        return BookExport::where('book_id', $book->id)
            ->where('format', $format)
            ->where('status', 'generating')
            ->latest('id')
            ->first();
    }

    /**
     * Fail `generating` rows nothing will ever finish.
     *
     * A job that dies by timeout reaches GenerateExport::failed(), but a
     * worker killed outright (a deploy, the container running out of
     * memory) or a request that died before dispatching leaves its row
     * saying "generating" for ever, and the Publish screen polled it every
     * five seconds for as long as it stayed open.
     *
     * A row is dead when it has had no news for BookExport::STALE_AFTER and
     * no job is waiting for it. A job still in the queue behind other books
     * keeps its row alive: it will run, and failing it now would only make
     * the author ask again.
     *
     * @return int rows failed
     */
    public function expireStale(?int $bookId = null): int
    {
        $stale = BookExport::where('status', 'generating')
            ->where('updated_at', '<', now()->subSeconds(BookExport::STALE_AFTER))
            ->when($bookId, fn ($query) => $query->where('book_id', $bookId))
            ->get();

        if ($stale->isEmpty()) {
            return 0;
        }

        $waiting = $this->waitingExports();
        $failed = 0;

        foreach ($stale as $export) {
            if (in_array($export->id, $waiting, true)) {
                $export->touch();

                continue;
            }

            if (! BookExport::whereKey($export->id)->where('status', 'generating')->update(['status' => 'failed', 'updated_at' => now()])) {
                continue;
            }

            $failed++;

            // News only while it is news: a row from last week is history,
            // and its author has long since tried again or moved on.
            if (in_array($export->format, GenerateExport::FORMATS, true)
                && $export->created_at->gt(now()->subDay())
                && $export->book) {
                Notify::exportFailed($export->fresh(), __('The export stopped before it finished. Generating it again usually works.'));
                Inbox::exportSettled($export->fresh(), false, __('The export stopped before it finished. Generating it again usually works.'));
            }
        }

        return $failed;
    }

    /**
     * Export ids with a GenerateExport job still waiting in the database queue.
     *
     * Only the database driver can be asked; with any other the answer is
     * "none", and a long queue costs an author a retry rather than a file
     * stuck for ever.
     *
     * @return list<int>
     */
    private function waitingExports(): array
    {
        $connection = config('queue.default');

        if (config("queue.connections.{$connection}.driver") !== 'database') {
            return [];
        }

        return DB::connection(config("queue.connections.{$connection}.connection"))
            ->table(config("queue.connections.{$connection}.table", 'jobs'))
            ->whereNull('reserved_at')
            ->where('payload', 'like', '%GenerateExport%')
            ->pluck('payload')
            ->map(fn (string $payload) => preg_match('/exportId\\\\?";i:(\d+);/', $payload, $m) ? (int) $m[1] : null)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Render a `generating` row's file from the book as it is now.
     *
     * The row is settled only if it still says `generating`: the sweep or a
     * second worker may have called it failed meanwhile, and the history
     * must not flip back and forth. A PDF or EPUB's author is emailed on the
     * change; the small formats are made while the author watches.
     */
    public function run(BookExport $export): BookExport
    {
        $base = $this->renderer;
        $queued = in_array($export->format, GenerateExport::FORMATS, true);

        try {
            $book = $export->book ?? throw new RuntimeException('This book has been deleted.');
            $format = $export->format;
            $sections = $this->chapters($book);
            $profile = ExportOptions::fromExport($export);

            // The book as this profile has it, for the whole of the render
            // (ExportOptions::within): the range and the matter apply
            // wherever the book's anatomy is read.
            $path = ExportOptions::within($book, $format, $profile, function () use ($base, $book, $format, $sections, $export, $profile) {
                // Numbering for the whole book: captions and equations renumber
                // from position, and cross-references resolve, in every format.
                // EPUB splits the book into files, so its links name the file.
                $structure = $this->structure($book);
                $this->renderer = $base->withNumbering(
                    Numbering::forBook($structure, $book->layout()['numbering']),
                    $format === 'epub' ? array_map(fn ($name) => $name.'.xhtml', $this->epubNames($structure)) : [],
                )->withLanguage(self::languageCode($book->language));

                $this->bookNotes = $profile->values['notes'] === 'book' ? new \ArrayObject : null;

                if ($profile->values['manuscript']) {
                    return $this->manuscript($book, $export, $profile);
                }

                return match ($format) {
                    'epub' => $this->epub($book, $sections, $export),
                    'pdf' => $this->pdf($book, $sections, $export),
                    'pdf_print' => $this->printable($book, $sections, $export),
                    'pdf_interior' => $this->interior($book, $export),
                    'cover_wrap' => $this->coverWrap($book, $export),
                    'docx' => $this->docx($book, $sections, $export),
                    'md' => $this->markdown($book, $sections, $export),
                    'html' => $this->singleHtml($book, $sections, $export),
                };
            });

            $settled = BookExport::whereKey($export->id)->where('status', 'generating')->update([
                'status' => 'ready',
                'path' => $path,
                'byte_size' => Storage::disk('files')->size($path),
                'updated_at' => now(),
            ]);

            if (! $settled) {
                // Somebody else settled it. The file is ours to remove
                // unless the row now points at it.
                if (BookExport::whereKey($export->id)->value('path') !== $path) {
                    Storage::disk('files')->delete($path);
                }
            } elseif ($queued) {
                Notify::exportReady($export->fresh());
                Inbox::exportSettled($export->fresh(), true);
            }
        } catch (\Throwable $e) {
            // Recorded, not swallowed: the history should show that a
            // generation was attempted and failed, and the exception still
            // reaches the caller so the author sees why.
            $failed = BookExport::whereKey($export->id)->where('status', 'generating')->update(['status' => 'failed', 'updated_at' => now()]);

            if ($failed && $queued && $export->book) {
                Notify::exportFailed($export->fresh(), $this->reason($e));
                Inbox::exportSettled($export->fresh(), false, $this->reason($e));
            }

            throw $e;
        } finally {
            // The next export starts from the plain renderer, not this book's.
            $this->renderer = $base;
            $this->bookNotes = null;
        }

        return $export->fresh();
    }

    /**
     * A reason an author can read.
     *
     * The exporter's own RuntimeExceptions are written for authors; anything
     * else is an internal error whose message means nothing outside the code.
     */
    private function reason(\Throwable $e): string
    {
        return $e instanceof RuntimeException
            ? $e->getMessage()
            : __('Something went wrong while laying out the book. Generating it again usually works; if it fails twice, tell us.');
    }

    /**
     * The book as a reader opens it, for the full-screen preview.
     *
     * The EPUB's own documents in its own order (Anatomy::plan, the half
     * title left out and the contents where the book places it), so the
     * preview cannot drift from the file a store receives. Listed here and
     * rendered one at a time by readerPage(): a 100k-word book is never
     * sent, or laid out on a phone, in one piece.
     *
     * @return list<array{key: string, title: string, kind: string, depth: int, section_id: int|null, words: int}>
     */
    public function readerContents(Book $book): array
    {
        return array_map(fn (array $item) => [
            'key' => $item['key'],
            'title' => $item['title'],
            'kind' => $item['kind'],
            'depth' => $item['depth'],
            'section_id' => $item['section']?->id,
            'words' => (int) ($item['section']?->word_count ?? 0),
        ], $this->readerPlan($book));
    }

    /**
     * One document of readerContents(), as the EPUB's body markup.
     *
     * Cross-references and the contents link to "preview-{key}#anchor"
     * rather than to an EPUB file name; the preview follows those itself.
     * Pictures keep the app's own URL, which the signed-in reader can load.
     */
    public function readerPage(Book $book, string $key): ?string
    {
        $plan = $this->readerPlan($book);
        $item = collect($plan)->firstWhere('key', $key);

        if ($item === null) {
            return null;
        }

        if ($item['kind'] === 'contents') {
            $entries = collect($plan)
                ->filter(fn ($entry) => $entry['section'] !== null)
                ->map(fn ($entry) => ['depth' => $entry['depth'], 'href' => 'preview-'.$entry['key'], 'title' => $entry['title']])
                ->values()
                ->all();

            return '<nav epub:type="toc" id="toc" class="toc"><h1>'.e($item['title']).'</h1>'.$this->navList($entries).'</nav>';
        }

        if ($item['section'] === null) {
            return $item['body'];
        }

        $base = $this->renderer;
        $structure = $this->structure($book)->values();

        try {
            $this->renderer = $base->withNumbering(
                Numbering::forBook($structure, $book->layout()['numbering']),
                $structure->mapWithKeys(fn ($s) => [$s->id => 'preview-s'.$s->id])->all(),
            );

            return $this->epubSectionBody($item['section'], $item['part']);
        } finally {
            $this->renderer = $base;
        }
    }

    /** The EPUB's stylesheet, for the preview to set its pages with. */
    public function readerCss(Book $book): string
    {
        return $this->epubCss($book);
    }

    /**
     * The printed book's blocks and stylesheet, for the preview's Print
     * mode: PrintBook's own plan over the same sections the PDF sets.
     *
     * @return array<string, mixed>
     */
    public function printContents(Book $book): array
    {
        return $this->print->previewContents($book, $this->structure($book), self::languageCode($book->language));
    }

    /** One block of the printed book as PrintBook sets it, or null. */
    public function printPage(Book $book, string $key): ?string
    {
        return $this->print->previewBlock($book, $this->structure($book), self::languageCode($book->language), $key);
    }

    /**
     * A chapter's opening pages for the Design & Layout screen, set by
     * PrintBook over the same sections the PDF sets (PrintBook::
     * designPreview); null when the book has nothing to show yet.
     *
     * @return array<string, mixed>|null
     */
    public function designPreview(Book $book, ?int $sectionId): ?array
    {
        return $this->print->designPreview($book, $this->structure($book), self::languageCode($book->language), $sectionId);
    }

    /**
     * The reader's documents, in the EPUB's order, with what it takes to
     * render each.
     *
     * @return list<array{key: string, title: string, kind: string, depth: int, part: string|null, section: ManuscriptSection|null, body?: string}>
     */
    private function readerPlan(Book $book): array
    {
        $byId = $this->structure($book)->keyBy('id');
        $items = [];
        $contents = false;

        foreach (Anatomy::plan($book)['pages'] as $page) {
            $part = $page['part'];

            if ($part === 'half_title') {
                continue;
            }

            if ($part === 'table_of_contents') {
                if (! $contents) {
                    $items[] = ['key' => 'toc', 'title' => $page['title'], 'kind' => 'contents', 'depth' => 0, 'part' => $part, 'section' => null];
                    $contents = true;
                }

                continue;
            }

            if ($page['section_id'] === null) {
                if ($generated = $this->epubGeneratedPage($page)) {
                    $items[] = ['key' => $generated[0], 'title' => $page['title'], 'kind' => $generated[0], 'depth' => 0,
                        'part' => $part, 'section' => null, 'body' => $generated[1]];
                }

                continue;
            }

            $section = $byId->get($page['section_id']);

            if (! $section || in_array($page['state'], ['empty', 'placeholder'], true)) {
                continue;
            }

            $items[] = ['key' => 's'.$section->id, 'title' => $section->title,
                'kind' => $section->type === 'part' ? 'part' : 'chapter', 'depth' => (int) $page['depth'],
                'part' => $part, 'section' => $section];
        }

        return $items;
    }

    /**
     * Chapters in reading order, parts excluded.
     *
     * A part is a container holding chapters, so exporting it would emit its
     * title twice — once as the part and once as the chapter beneath it.
     *
     * @return Collection<int, ManuscriptSection>
     */
    private function chapters(Book $book)
    {
        $rules = $this->exportTypography($book);

        return $book->sections()
            ->orderBy('position')
            ->get()
            ->reject(fn (ManuscriptSection $section) => $section->isContainer())
            // The book as it stands: pending suggestions read as rejected.
            ->map(fn (ManuscriptSection $section) => $this->typeset($section->asPublished(), $rules))
            // Generated lists are empty in the binder and full in the book.
            ->filter(fn (ManuscriptSection $section) => Numbering::listKind($section) !== null || trim($section->plainText()) !== '')
            ->values();
    }

    /**
     * Everything that belongs in the finished book, parts included.
     *
     * chapters() drops containers, because a part has no prose of its own
     * and every renderer below wants text to lay out. That is right for the
     * body and wrong for the book: an author who arranged twenty chapters
     * into five parts got an export with no parts in it, and no way to tell
     * where one ended and the next began.
     *
     * A part survives here as a title with no body — a part-title page,
     * which is what it is in a printed book.
     *
     * In the book's order (Anatomy::plan: the binder walked as a tree, a
     * part then its chapters), so every format and the numbering read the
     * sections in the order print sets them. A section still holding a
     * template's guidance is never exported.
     */
    private function structure(Book $book)
    {
        $rules = $this->exportTypography($book);
        $sections = $book->sections()->get()
            ->map(fn (ManuscriptSection $section) => $this->typeset($section->asPublished(), $rules))
            ->keyBy('id');

        return collect(Anatomy::plan($book)['pages'])
            ->filter(fn (array $page) => $page['section_id'] !== null && $page['state'] !== 'placeholder')
            ->map(fn (array $page) => $sections->get($page['section_id']))
            ->filter(fn (?ManuscriptSection $s) => $s && ($s->type === 'part'
                || Numbering::listKind($s) !== null
                || trim($s->plainText()) !== ''))
            ->values();
    }

    /**
     * The book's typography rules when the author asked for them at export
     * (ADR — Book language and typography), else null: an export without
     * the option is set from the manuscript exactly as it is.
     */
    private function exportTypography(Book $book): ?array
    {
        $rules = Typography::resolve($book);

        return $rules['apply_at_export'] && Typography::active($rules) ? $rules : null;
    }

    /**
     * A copy of the section with the rules applied to its title and text.
     * The manuscript itself is never written: the copy is the export's.
     */
    private function typeset(ManuscriptSection $section, ?array $rules): ManuscriptSection
    {
        if ($rules === null) {
            return $section;
        }

        $copy = clone $section;
        $copy->title = Typography::text((string) $section->title, $rules);
        $copy->content = Typography::document($section->content, $rules);

        return $copy;
    }

    /**
     * The table of contents, as a list of entries.
     *
     * Parts are depth 0 and everything else depth 1, so a renderer can
     * indent without knowing anything about the binder.
     *
     * @return array<int, array{title: string, id: string, depth: int, part: bool}>
     */
    private function contents($sections): array
    {
        $entries = [];
        $n = 0;

        foreach ($sections as $section) {
            $entries[] = [
                'title' => $section->title,
                'id' => 'sec-'.(++$n),
                'depth' => $section->type === 'part' ? 0 : 1,
                'part' => $section->type === 'part',
            ];
        }

        return $entries;
    }

    /** The contents page, as HTML, headed in the book's language. */
    private function contentsHtml(array $entries, string $language): string
    {
        if (count($entries) < 2) {
            return '';
        }

        $items = '';

        foreach ($entries as $entry) {
            $class = $entry['part'] ? ' class="toc-part"' : '';
            $items .= '<li'.$class.'><a href="#'.e($entry['id']).'">'
                .e($entry['title']).'</a></li>'."\n";
        }

        return '<nav class="toc" id="contents"><h1>'.e(PrintBook::words($language)['contents']).'</h1><ol>'."\n"
            .$items.'</ol></nav>'."\n";
    }

    /**
     * One section as HTML, with an anchor the contents can reach.
     *
     * A part renders as its title alone: it is a divider, not a chapter.
     */
    private function structuredHtml(ManuscriptSection $section, string $id): string
    {
        if ($section->type === 'part') {
            return '<section class="part" id="'.e($id).'">'
                .'<h1 class="part-title">'.e($section->title).'</h1></section>'."\n";
        }

        return '<section id="'.e($id).'">'."\n"
            .$this->chapterHtml($section)."\n".'</section>'."\n";
    }

    /**
     * A chapter's markup, with its title as the opening heading.
     *
     * The title is only added when the prose does not already open with a
     * level-1 heading of its own. Authors commonly type the chapter title as
     * the first line, and emitting both gives two H1s in one document — which
     * store validators flag and screen readers announce twice.
     */
    private function chapterHtml(ManuscriptSection $section, ?DocumentRenderer $renderer = null): string
    {
        // An anchor for references to the chapter as a whole.
        $html = '<span id="section-'.$section->id.'"></span>'.($renderer ?? $this->renderer)->html($section);

        return $this->opensWithHeading($section)
            ? $html
            : '<h1>'.e($section->title).'</h1>'."\n".$html;
    }

    private function opensWithHeading(ManuscriptSection $section): bool
    {
        foreach ($section->content['content'] ?? [] as $node) {
            if (! is_array($node)) {
                continue;
            }

            // The first block that carries text decides it; an empty
            // paragraph above the title should not count as the opening.
            if (trim($this->plainOf($node)) === '') {
                continue;
            }

            return ($node['type'] ?? '') === 'heading'
                && (int) ($node['attrs']['level'] ?? 2) <= 2;
        }

        return false;
    }

    /** @param array<string, mixed> $node */
    private function plainOf(array $node): string
    {
        $out = (string) ($node['text'] ?? '');

        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                $out .= $this->plainOf($child);
            }
        }

        return $out;
    }

    private function directory(Book $book): string
    {
        return "exports/book-{$book->id}";
    }

    private function filename(Book $book, BookExport $export): string
    {
        $slug = Str::slug($book->title) ?: 'book';

        // The export id is in the name so regenerating never overwrites an
        // earlier file. Export history promises the old file is still there.
        // What a print file is for goes in its name: two PDFs of the same
        // book that a printer must not confuse.
        $kind = match ($export->format) {
            'pdf_interior' => '-interior',
            'cover_wrap' => '-cover-'.($export->options['service'] ?? 'print'),
            default => '',
        };

        return "{$slug}{$kind}-{$export->id}.".$export->extension();
    }

    /** @param Collection<int, ManuscriptSection> $sections */
    private function markdown(Book $book, $sections, BookExport $export): string
    {
        $parts = ["# {$book->title}"];

        if ($book->subtitle) {
            $parts[] = "## {$book->subtitle}";
        }

        if ($book->author_name) {
            $parts[] = "by {$book->author_name}";
        }

        $added = $this->addedPages($book);

        if ($notice = $added['review_notice'] ?? null) {
            $parts[] = '**'.$notice['data']['text'].'**';
        }

        // In the book's order, without template guidance; parts are
        // containers with no prose of their own.
        foreach ($this->structure($book)->reject(fn (ManuscriptSection $s) => $s->isContainer()) as $section) {
            // Same reason as the HTML exports: skip the title when the prose
            // already opens with one, rather than printing it twice.
            if (! $this->opensWithHeading($section)) {
                $parts[] = "\n## {$section->title}\n";
            }

            $parts[] = $this->renderer->markdown($section);
        }

        if ($closing = $added['continue_reading'] ?? null) {
            $parts[] = "\n## {$closing['data']['heading']}\n";
            $parts[] = $closing['data']['text'].($closing['data']['url'] ? "\n\n[{$closing['data']['link']}]({$closing['data']['url']})" : '');
        }

        return $this->put($book, $export, implode("\n\n", $parts)."\n");
    }

    /** @param Collection<int, ManuscriptSection> $sections */
    private function singleHtml(Book $book, $sections, BookExport $export): string
    {
        // The whole book, not just its prose: a title page, a contents list,
        // and the parts the binder arranged the chapters into.
        $sections = $this->structure($book);
        $entries = $this->contents($sections);

        $copyright = collect(Anatomy::copyrightLines($book))->map(fn ($line) => self::copyrightParagraphs($line['text']))->implode('');

        // A profile or the book's matter set may leave the generated front
        // matter out, and may add a notice or a closing page.
        $out = $this->leftOut($book);
        $added = $this->addedPages($book);

        $body = (in_array('title_page', $out, true) ? '' : $this->titlePageHtml($book))
            .($copyright !== '' && ! in_array('copyright_page', $out, true) ? '<section class="copyright">'.$copyright.'</section>'."\n" : '')
            .(isset($added['review_notice']) ? '<section class="review-notice"><p><strong>'.e($added['review_notice']['data']['text']).'</strong></p></section>'."\n" : '')
            .(in_array('table_of_contents', $out, true) ? '' : $this->contentsHtml($entries, self::languageCode($book->language)));

        $renderer = $this->renderer;

        if ($this->bookNotes) {
            $this->renderer = $renderer->withBookNotes($this->bookNotes);
        }

        foreach ($sections as $i => $section) {
            $body .= $this->structuredHtml($section, $entries[$i]['id']);
        }

        $this->renderer = $renderer;

        if ($this->bookNotes && count($this->bookNotes) > 0) {
            $body .= '<section class="notes" id="notes"><h1>'.e(ExportOptions::words(self::languageCode($book->language))['notes']).'</h1>'
                .$renderer->bookNotesHtml($this->bookNotes).'</section>'."\n";
        }

        if ($closing = $added['continue_reading'] ?? null) {
            $body .= '<section class="continue-reading"><h1>'.e($closing['data']['heading']).'</h1><p>'.e($closing['data']['text']).'</p>'
                .($closing['data']['url'] ? '<p><a href="'.e($closing['data']['url']).'">'.e($closing['data']['link']).'</a></p>' : '').'</section>'."\n";
        }

        // One self-contained file: pictures travel inside it.
        $body = $this->images->inline($body, $book->id);

        return $this->put($book, $export, $this->page($book, $body, standalone: true));
    }

    /**
     * A real PDF, typeset as a printed book, to read on screen.
     *
     * dompdf renders it; PrintBook does the book design. The print-ready
     * HTML is made from the same pages, so fix the layout once and both
     * improve.
     */
    private function pdf(Book $book, $sections, BookExport $export): string
    {
        // The reading PDF: the front cover is its first page. The
        // print-ready interior (pdf_print) never carries the cover.
        // A profile may leave the cover out (ExportOptions).
        return $this->put($book, $export, $this->print->pdf(
            $book, $this->structure($book), self::languageCode($book->language), cover: ExportOptions::active($book)?->values['cover'] ?? true,
        ));
    }

    /**
     * The print-ready interior: the PDF without its cover, which a printer
     * binds inside the cover wrap. Records its page count and what that
     * count depends on (PrintPages), so the cover's spine and the print
     * checks use the real number.
     */
    private function interior(Book $book, BookExport $export): string
    {
        $fingerprint = PrintPages::fingerprint($book);
        $set = $this->print->typeset($book, $this->structure($book), self::languageCode($book->language));

        BookExport::whereKey($export->id)->update([
            'pages' => $set['pages'],
            // The margin it was set at and the near-empty pages it has, for
            // the print checks (PrintCheck); and the profile it was made
            // with, if any (ExportOptions).
            'options' => json_encode(array_merge($export->options ?? [], ['fingerprint' => $fingerprint, 'margin' => $set['margin'], 'outside' => $set['outside'], 'layout' => $set['layout']])),
        ]);

        return $this->put($book, $export, $set['pdf']);
    }

    /**
     * The full cover for one print service, sized from the interior's
     * real page count: the one already typeset when the book has not
     * changed since, else the book is typeset now to count it.
     */
    private function coverWrap(Book $book, BookExport $export): string
    {
        $options = $this->wraps->options($book, $export->options ?? []);
        $fingerprint = PrintPages::fingerprint($book);
        $pages = PrintPages::known($book)['pages']
            ?? $this->print->typeset($book, $this->structure($book), self::languageCode($book->language))['pages'];

        BookExport::whereKey($export->id)->update([
            'pages' => $pages,
            // The cover's own design too: a wrap made before the back, the
            // spine or the front changed is stale (Freshness).
            'options' => json_encode($options + ['fingerprint' => $fingerprint, 'cover' => $this->wraps->fingerprint($book)]),
        ]);

        return $this->put($book, $export, $this->wraps->pdf($book, $this->wraps->geometry($book, $options, $pages)));
    }

    /**
     * A copyright line as paragraphs: a disclaimer or a rights statement
     * can run to several, separated by a blank line.
     */
    private static function copyrightParagraphs(string $text): string
    {
        return collect(preg_split('/\R[ \t]*\R/', trim($text)))
            ->map(fn ($paragraph) => '<p>'.nl2br(e(trim($paragraph))).'</p>')
            ->implode('');
    }

    /** The title page: what a reader opens to. */
    private function titlePageHtml(Book $book): string
    {
        $html = '<section class="title-page">'."\n"
            .'<h1 class="book-title">'.e($book->title).'</h1>'."\n";

        if ($book->subtitle) {
            $html .= '<p class="book-subtitle">'.e($book->subtitle).'</p>'."\n";
        }

        if ($book->author_name) {
            $html .= '<p class="book-author">'.e($book->author_name).'</p>'."\n";
        }

        return $html.'</section>'."\n";
    }

    /**
     * A print-ready interior as HTML with @page rules: the PDF's pages,
     * for an author who would rather print from a browser.
     *
     * @param  Collection<int, ManuscriptSection>  $sections
     */
    private function printable(Book $book, $sections, BookExport $export): string
    {
        return $this->put($book, $export, $this->print->html(
            $book, $this->structure($book), self::languageCode($book->language),
        ));
    }

    /**
     * A .docx, built as a real Open XML package.
     *
     * Editors open these in Word and mark them up; an HTML file renamed .docx
     * prompts a repair dialog, which is where an author stops trusting the
     * export.
     *
     * Laid out as a book, from the book's anatomy (Anatomy::plan): the half
     * title, series page, title page and copyright page generated from the
     * book's details, the contents as a Word TOC field, then the author's
     * sections in the book's order. Every page is a Word section of its
     * own, so it can open on a recto (an odd-page break) or a verso (an
     * even-page break); front matter is numbered in lower-case roman and
     * the text restarts at 1 in arabic; display pages print no folio.
     *
     * @param  Collection<int, ManuscriptSection>  $sections
     */
    private function docx(Book $book, $sections, BookExport $export): string
    {
        $this->renderer->resetDocxMedia();

        $plan = Anatomy::plan($book);
        $byId = $this->structure($book)->keyBy('id');
        $layout = $book->layout();
        $size = preg_match('/([\d.]+)\s*x\s*([\d.]+)/', (string) $layout['trim'], $m)
            ? [(int) round((float) $m[1] * 1440), (int) round((float) $m[2] * 1440)]
            : [8640, 12960];

        // The pages that print, each with its content.
        $pages = [];
        $entries = [];

        foreach ($plan['pages'] as $page) {
            $section = $page['section_id'] !== null ? $byId->get($page['section_id']) : null;

            if ($page['section_id'] !== null && $page['part'] !== 'table_of_contents'
                && (! $section || in_array($page['state'], ['empty', 'placeholder'], true))) {
                continue;
            }

            if ($section && $page['show_folio'] && $page['part'] !== 'table_of_contents') {
                $entries[] = $section->title;
            }

            $pages[] = ['page' => $page, 'section' => $section];
        }

        $restart = collect($pages)->search(fn ($p) => $p['page']['folio'] === 'arabic');
        $body = '';

        foreach ($pages as $i => ['page' => $page, 'section' => $section]) {
            $data = $page['data'] ?? [];

            $content = match (true) {
                $page['part'] === 'half_title' => $this->docxParagraph((string) ($data['title'] ?? $book->title), 'Title', center: true),

                $page['part'] === 'title_page' => $this->docxTitlePage($book, $data),

                $page['part'] === 'series_page' && $data !== [] => $this->docxParagraph((string) $data['heading'], 'Subtitle', center: true)
                    .(($data['kind'] ?? '') === 'series' && ! empty($data['series']) ? $this->docxParagraph((string) $data['series'], null, center: true) : '')
                    .collect($data['books'] ?? [])->map(fn ($b) => $this->docxParagraph(($b['number'] ? $b['number'].'. ' : '').$b['title'], null, center: true))->implode(''),

                $page['part'] === 'copyright_page' && ! $section => collect($data['lines'] ?? [])
                    ->flatMap(fn ($line) => preg_split('/\R[ \t]*\R/', trim($line['text'])))
                    ->map(fn ($paragraph) => $this->docxParagraph(trim($paragraph), 'Copyright'))->implode(''),

                $page['part'] === 'table_of_contents' => $this->docxParagraph((string) $page['title'], 'TOCHeading')
                    .$this->docxContents($entries, ExportOptions::active($book)?->values['toc_depth'] ?? 1),

                // Pages an export profile adds (ExportOptions).
                $page['part'] === 'review_notice' => $this->docxParagraph((string) $data['text'], 'Title', center: true),

                $page['part'] === 'continue_reading' => $this->docxParagraph((string) $data['heading'], 'Heading1')
                    .$this->docxParagraph((string) $data['text'])
                    .($data['url'] ? $this->docxParagraph($data['link'].': '.$data['url']) : ''),

                $section === null => '',

                $section->type === 'part' => $this->docxParagraph($section->title, 'Heading1', center: true),

                // Display pages (dedication, epigraph, a hand-written copyright
                // page) carry no heading of their own.
                ! $page['show_folio'] => $this->renderer->docx($section),

                // Structure, not plain text: headings, italics, quotes, lists
                // and tables survive, because Word is where an examiner or an
                // editor will read it.
                default => $this->docxParagraph($section->title, 'Heading1').$this->renderer->docx($section),
            };

            $properties = $this->docxSection(
                start: $i === 0 ? 'any' : ($i === $restart ? 'recto' : $page['start']),
                format: $restart === false || $i < $restart ? 'lowerRoman' : 'decimal',
                restart: $i === 0 || $i === $restart,
                folio: (bool) $page['show_folio'],
                size: $size,
            );

            // A section's properties close it: on an empty paragraph after
            // its content, and for the last section, at the end of the body.
            $body .= ($content !== '' ? $content : '<w:p/>')
                .($i < count($pages) - 1 ? '<w:p><w:pPr>'.$properties.'</w:pPr></w:p>' : $properties);
        }

        // Styles the body needs beyond the standard set (a table at the
        // text size), added only when it does.
        $styles = str_replace('</w:styles>', $this->renderer->docxExtraStyles().'</w:styles>', $this->docxStyles(self::wordLanguage(self::languageCode($book->language))));

        return $this->docxPackage($book, $export, $body, $styles);
    }

    /**
     * A Word package around a document body: styles, settings, the folio
     * footers, and the pictures, footnotes and list numbering the renderer
     * collected. $headers adds header parts (relationship id => [file,
     * XML]), which only the manuscript uses.
     *
     * @param  array<string, array{0: string, 1: string}>  $headers
     */
    private function docxPackage(Book $book, BookExport $export, string $body, string $styles, array $headers = []): string
    {
        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            .'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
            .'<w:body>'.$body.'</w:body></w:document>';

        // Pictures placed in the text, as package parts; footnotes and list
        // numbering as theirs.
        $media = $this->renderer->docxMedia();
        $footnotes = $this->renderer->docxFootnotes();
        $numbering = $this->renderer->docxNumbering();

        $footer = fn (string $paragraph) => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'.$paragraph.'</w:ftr>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .collect($media)->mapWithKeys(fn ($m) => [pathinfo($m['file'], PATHINFO_EXTENSION) => $m['mime']])
                ->map(fn ($mime, $extension) => '<Default Extension="'.$extension.'" ContentType="'.$mime.'"/>')->implode('')
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            .'<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>'
            .'<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>'
            .'<Override PartName="/word/footer2.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>'
            .collect($headers)->map(fn ($h) => '<Override PartName="/word/'.$h[0].'" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>')->implode('')
            .($footnotes ? '<Override PartName="/word/footnotes.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footnotes+xml"/>' : '')
            .($numbering ? '<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>' : '')
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .'</Types>';

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            .'</Relationships>';

        $documentRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'<Relationship Id="rIdSettings" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/>'
            .'<Relationship Id="rIdFolio" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>'
            .'<Relationship Id="rIdBlind" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer2.xml"/>'
            .collect($headers)->map(fn ($h, $id) => '<Relationship Id="'.$id.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="'.$h[0].'"/>')->implode('')
            .($footnotes ? '<Relationship Id="rIdNotes" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footnotes" Target="footnotes.xml"/>' : '')
            .($numbering ? '<Relationship Id="rIdNumbering" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>' : '')
            .collect($media)->map(fn ($m, $id) => '<Relationship Id="'.$id.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/'.$m['file'].'"/>')->implode('')
            .'</Relationships>';

        // Word fills the contents field when the file is opened.
        $settings = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:updateFields w:val="true"/><w:evenAndOddHeaders w:val="false"/></w:settings>';

        return $this->zip($book, $export, [
            '[Content_Types].xml' => $contentTypes,
            '_rels/.rels' => $rels,
            'word/document.xml' => $document,
            'word/_rels/document.xml.rels' => $documentRels,
            'word/styles.xml' => $styles,
            'word/settings.xml' => $settings,
            // The folio, centred at the foot; and an empty footer for the
            // display pages, which a Word section would otherwise inherit.
            'word/footer1.xml' => $footer('<w:p><w:pPr><w:jc w:val="center"/></w:pPr>'
                .'<w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> PAGE </w:instrText></w:r>'
                .'<w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>1</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r></w:p>'),
            'word/footer2.xml' => $footer('<w:p/>'),
            'docProps/core.xml' => $this->docxCore($book),
            ...collect($headers)->mapWithKeys(fn ($h) => ['word/'.$h[0] => $h[1]])->all(),
            ...($footnotes ? ['word/footnotes.xml' => $footnotes] : []),
            ...($numbering ? ['word/numbering.xml' => $numbering] : []),
            ...collect($media)->mapWithKeys(fn ($m) => ['word/media/'.$m['file'] => $m['data']])->all(),
        ]);
    }

    /**
     * One page's Word section properties.
     *
     * start: recto opens on the next odd page (Word inserts the blank),
     * verso on the next even one. format: lowerRoman or decimal, restarting
     * at 1 where asked. folio false: the empty footer, so a display page
     * prints no number.
     *
     * @param  array{0: int, 1: int}  $size  twips
     */
    private function docxSection(string $start, string $format, bool $restart, bool $folio, array $size): string
    {
        $type = ['recto' => 'oddPage', 'verso' => 'evenPage'][$start] ?? 'nextPage';
        [$width, $height] = $size;
        $side = (int) round(0.78 * 1440);

        return '<w:sectPr>'
            .'<w:footerReference w:type="default" r:id="'.($folio ? 'rIdFolio' : 'rIdBlind').'"/>'
            .'<w:type w:val="'.$type.'"/>'
            .'<w:pgSz w:w="'.$width.'" w:h="'.$height.'"/>'
            .'<w:pgMar w:top="1296" w:right="'.$side.'" w:bottom="1368" w:left="'.$side.'" w:header="720" w:footer="720" w:gutter="0"/>'
            .'<w:pgNumType w:fmt="'.$format.'"'.($restart ? ' w:start="1"' : '').'/>'
            .'</w:sectPr>';
    }

    /** The title page: title, subtitle, the author if there is one, the publisher. */
    private function docxTitlePage(Book $book, array $data): string
    {
        $data += ['title' => $book->title, 'subtitle' => $book->subtitle, 'author' => $book->author_name ?: null,
            'publisher' => null, 'imprint' => null, 'place' => null];
        $publisher = trim((string) ($data['imprint'] ?: $data['publisher']));

        return $this->docxParagraph((string) $data['title'], 'Title', center: true)
            .($data['subtitle'] ? $this->docxParagraph((string) $data['subtitle'], 'Subtitle', center: true) : '')
            .($data['author'] ? $this->docxParagraph((string) $data['author'], 'Subtitle', center: true) : '')
            .($publisher !== '' ? $this->docxParagraph($publisher, null, center: true) : '')
            .(trim((string) $data['place']) !== '' ? $this->docxParagraph((string) $data['place'], null, center: true) : '');
    }

    /**
     * The contents as a Word TOC field over the chapter headings. Its
     * result is the chapter titles, so the page reads right before Word
     * updates the field and adds the page numbers.
     *
     * @param  list<string>  $entries
     */
    private function docxContents(array $entries, int $depth = 1): string
    {
        $x = fn (string $value) => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        // Chapter titles only, unless a profile asks Word to go deeper.
        $begin = '<w:r><w:fldChar w:fldCharType="begin" w:dirty="true"/></w:r>'
            .'<w:r><w:instrText xml:space="preserve"> TOC \o "1-'.max(1, min(3, $depth)).'" \h \z \u </w:instrText></w:r>'
            .'<w:r><w:fldChar w:fldCharType="separate"/></w:r>';
        $end = '<w:r><w:fldChar w:fldCharType="end"/></w:r>';

        if ($entries === []) {
            return '<w:p>'.$begin.$end.'</w:p>';
        }

        $html = '';
        $last = count($entries) - 1;

        foreach ($entries as $i => $entry) {
            $html .= '<w:p><w:pPr><w:pStyle w:val="TOC1"/></w:pPr>'
                .($i === 0 ? $begin : '')
                .'<w:r><w:t xml:space="preserve">'.$x($entry).'</w:t></w:r>'
                .($i === $last ? $end : '')
                .'</w:p>';
        }

        return $html;
    }

    private function docxParagraph(string $text, ?string $style = null, bool $center = false): string
    {
        $properties = $style || $center
            ? '<w:pPr>'.($style ? '<w:pStyle w:val="'.$style.'"/>' : '').($center ? '<w:jc w:val="center"/>' : '').'</w:pPr>'
            : '';

        // xml:space="preserve" so leading and trailing spaces inside a
        // paragraph are not silently collapsed by Word.
        return '<w:p>'.$properties.'<w:r><w:t xml:space="preserve">'
            .htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8')
            .'</w:t></w:r></w:p>';
    }

    /**
     * The document's properties: its title and language, and the author
     * only when the book names one. Many of these books are anonymous, and
     * Word would otherwise show whoever's machine last saved the file.
     */
    private function docxCore(Book $book): string
    {
        $x = fn (string $value) => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $now = now()->utc()->format('Y-m-d\TH:i:s\Z');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            .'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
            .'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dc:title>'.$x($book->title).'</dc:title>'
            .($book->subtitle ? '<dc:subject>'.$x($book->subtitle).'</dc:subject>' : '')
            .($book->author_name ? '<dc:creator>'.$x($book->author_name).'</dc:creator>' : '')
            .'<dc:language>'.$x(self::languageCode($book->language)).'</dc:language>'
            .'<dcterms:created xsi:type="dcterms:W3CDTF">'.$now.'</dcterms:created>'
            .'<dcterms:modified xsi:type="dcterms:W3CDTF">'.$now.'</dcterms:modified>'
            .'</cp:coreProperties>';
    }

    /** The language tag Word proofs in: it expects a region (fr-FR, ht-HT). */
    private static function wordLanguage(string $code): string
    {
        return match ($code) {
            'fr' => 'fr-FR',
            'es' => 'es-ES',
            'de' => 'de-DE',
            'pt' => 'pt-PT',
            'ht' => 'ht-HT',
            default => $code,
        };
    }

    private function docxStyles(string $language = 'en-US'): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            // The book's language for every run, so Word proofs the text in
            // it rather than in the reader's own.
            .'<w:docDefaults><w:rPrDefault><w:rPr><w:lang w:val="'.$language.'" w:eastAsia="'.$language.'" w:bidi="ar-SA"/></w:rPr></w:rPrDefault></w:docDefaults>'
            .'<w:style w:type="paragraph" w:styleId="ListParagraph"><w:name w:val="List Paragraph"/>'
            .'<w:pPr><w:spacing w:after="60"/><w:ind w:left="720"/><w:contextualSpacing/></w:pPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/>'
            .'<w:rPr><w:sz w:val="56"/><w:b/></w:rPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="Subtitle"><w:name w:val="Subtitle"/>'
            .'<w:rPr><w:sz w:val="28"/><w:i/></w:rPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/>'
            // No page break of its own: every page of the book is a Word
            // section, and the section break already starts the page.
            .'<w:pPr><w:keepNext/><w:spacing w:before="720" w:after="240"/><w:outlineLvl w:val="0"/></w:pPr>'
            .'<w:rPr><w:sz w:val="36"/><w:b/></w:rPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="TOCHeading"><w:name w:val="TOC Heading"/>'
            .'<w:pPr><w:spacing w:before="720" w:after="360"/></w:pPr><w:rPr><w:sz w:val="36"/><w:b/></w:rPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="TOC1"><w:name w:val="toc 1"/>'
            .'<w:pPr><w:spacing w:after="80"/></w:pPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="Copyright"><w:name w:val="Copyright"/>'
            .'<w:pPr><w:spacing w:after="120"/></w:pPr><w:rPr><w:sz w:val="17"/></w:rPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/>'
            .'<w:pPr><w:keepNext/><w:spacing w:before="320" w:after="120"/><w:outlineLvl w:val="1"/></w:pPr>'
            .'<w:rPr><w:sz w:val="28"/><w:b/></w:rPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="Heading3"><w:name w:val="heading 3"/>'
            .'<w:pPr><w:keepNext/><w:spacing w:before="240" w:after="80"/><w:outlineLvl w:val="2"/></w:pPr>'
            .'<w:rPr><w:sz w:val="24"/><w:b/></w:rPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="Heading4"><w:name w:val="heading 4"/>'
            .'<w:pPr><w:keepNext/><w:spacing w:before="200" w:after="60"/><w:outlineLvl w:val="3"/></w:pPr>'
            .'<w:rPr><w:sz w:val="24"/><w:b/><w:i/></w:rPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="Quote"><w:name w:val="Quote"/>'
            .'<w:pPr><w:ind w:left="720" w:right="720"/></w:pPr><w:rPr><w:i/></w:rPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="Code"><w:name w:val="Code"/>'
            .'<w:pPr><w:spacing w:after="0"/></w:pPr>'
            .'<w:rPr><w:rFonts w:ascii="Consolas" w:hAnsi="Consolas"/><w:sz w:val="18"/></w:rPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="Break"><w:name w:val="Section Break"/>'
            .'<w:pPr><w:jc w:val="center"/></w:pPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="TableText"><w:name w:val="Table Text"/>'
            .'<w:pPr><w:spacing w:before="40" w:after="40"/></w:pPr><w:rPr><w:sz w:val="20"/></w:rPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="TableHeading"><w:name w:val="Table Heading"/>'
            .'<w:pPr><w:keepNext/><w:spacing w:before="40" w:after="40"/></w:pPr><w:rPr><w:b/><w:sz w:val="20"/></w:rPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="FootnoteText"><w:name w:val="footnote text"/>'
            .'<w:pPr><w:spacing w:after="0" w:line="240" w:lineRule="auto"/></w:pPr><w:rPr><w:sz w:val="18"/></w:rPr></w:style>'
            .'<w:style w:type="character" w:styleId="FootnoteReference"><w:name w:val="footnote reference"/>'
            .'<w:rPr><w:vertAlign w:val="superscript"/></w:rPr></w:style>'
            .'<w:style w:type="table" w:styleId="BookTable"><w:name w:val="Book Table"/>'
            .'<w:tblPr><w:tblCellMar><w:left w:w="100" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tblCellMar></w:tblPr></w:style>'
            .'</w:styles>';
    }

    /**
     * epub:type for each part (EPUB 3 Structural Semantics). Parts with no
     * term of their own (an abbreviations list, an About the author) take
     * only their matter's: frontmatter, bodymatter or backmatter. Index and
     * glossary are left out on purpose: those terms make a reading system
     * and epubcheck expect the EPUB Indexes and Dictionaries structures.
     */
    private const EPUB_TYPES = [
        'half_title' => 'halftitlepage', 'series_page' => 'seriespage', 'title_page' => 'titlepage',
        'copyright_page' => 'copyright-page', 'dedication' => 'dedication', 'epigraph' => 'epigraph',
        'table_of_contents' => 'toc', 'list_of_illustrations' => 'loi', 'list_of_tables' => 'lot',
        'foreword' => 'foreword', 'preface' => 'preface', 'acknowledgments' => 'acknowledgments',
        'introduction' => 'introduction', 'prologue' => 'prologue', 'part' => 'part', 'chapter' => 'chapter',
        'conclusion' => 'conclusion', 'epilogue' => 'epilogue', 'afterword' => 'afterword',
        'appendix' => 'appendix', 'notes' => 'endnotes', 'bibliography' => 'bibliography',
        'contributors' => 'contributors', 'colophon' => 'colophon',
    ];

    /**
     * A valid EPUB 3 container.
     *
     * The spine follows the book's anatomy (Anatomy::plan): the title page,
     * copyright page and series page generated from the book's details, the
     * contents (the navigation document, placed in the reading order where
     * the contents page falls), then the author's sections. EPUB has no
     * pages, so recto starts and folios do not apply; the half title is
     * left out, as most stores prefer.
     *
     * @param  Collection<int, ManuscriptSection>  $sections
     */
    private function epub(Book $book, $sections, BookExport $export): string
    {
        // The EPUB's own ISBN; books set up before formats had their own
        // numbers keep the identifier they had.
        $isbn = Anatomy::isbns($book)['epub'] ?? ($book->isbn ?: null);
        // Without an ISBN, a UUID made from the book, so every export of it
        // is the same publication to a reading app rather than a new one.
        $identifier = $isbn ? 'urn:isbn:'.$isbn : 'urn:uuid:'.Uuid::uuid5(Uuid::NAMESPACE_URL, 'https://ecrivain.ai/books/'.$book->id)->toString();
        $language = self::languageCode($book->language);
        $modified = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $plan = Anatomy::plan($book);
        $publication = $book->publication();

        $files = [];
        $pictures = [];
        $manifest = '';
        $spine = '';
        $entries = [];
        $landmarks = [];

        // The whole book, parts included: a reader opening the contents of
        // an EPUB should see the same structure the binder shows. Files are
        // named by place in structure(), as run() named them for the
        // cross-reference links.
        $structure = $this->structure($book)->values();
        $names = $this->epubNames($structure);
        $byId = $structure->keyBy('id');

        $declare = function (string $id, string $name) use (&$files, &$manifest, &$spine): void {
            // EPUB 3 requires declaring MathML; a validator rejects the book
            // otherwise, and a store with it.
            $flags = array_filter([
                str_contains($files[$name], '<math') ? 'mathml' : null,
                str_contains($files[$name], '<svg') ? 'svg' : null,
            ]);
            $properties = $flags ? ' properties="'.implode(' ', $flags).'"' : '';
            $manifest .= '<item id="'.$id.'" href="'.$id.'.xhtml" media-type="application/xhtml+xml"'.$properties.'/>';
            $spine .= '<itemref idref="'.$id.'"/>';
        };

        $navInSpine = false;
        $bodymatter = null;
        $parts = [];

        foreach ($plan['pages'] as $page) {
            $part = $page['part'];
            $matter = ['front' => 'frontmatter', 'body' => 'bodymatter', 'back' => 'backmatter'][$page['group']] ?? null;

            if ($part === 'half_title') {
                continue;
            }

            // The contents is the navigation document, read where it falls.
            if ($part === 'table_of_contents') {
                if (! $navInSpine) {
                    $spine .= '<itemref idref="nav"/>';
                    $landmarks[] = ['toc', 'nav.xhtml#toc', $page['title']];
                    $navInSpine = true;
                }

                continue;
            }

            if ($page['section_id'] === null) {
                // The notes gathered from the chapters set above, at the
                // end of the book (an export profile's notes placement).
                [$id, $body] = $part === 'book_notes'
                    ? ($this->bookNotes && count($this->bookNotes) > 0 ? ['notes', '<section epub:type="endnotes" class="notes"><h1>'.e($page['title']).'</h1>'
                        .$this->renderer->withNotes('epub')->bookNotesHtml($this->bookNotes).'</section>'] : [null, null])
                    : ($this->epubGeneratedPage($page) ?? [null, null]);

                if ($id === null) {
                    continue;
                }

                if ($id === 'notes') {
                    $entries[] = ['depth' => 0, 'href' => 'notes.xhtml', 'title' => $page['title']];
                }

                $files["OEBPS/{$id}.xhtml"] = $this->xhtml($page['title'], $body, $language, $matter);
                $declare($id, "OEBPS/{$id}.xhtml");

                if ($id === 'titlepage') {
                    $landmarks[] = ['titlepage', 'titlepage.xhtml', $page['title']];
                }

                continue;
            }

            $section = $byId->get($page['section_id']);

            // Empty, or still the template's guidance: not exported.
            if (! $section || in_array($page['state'], ['empty', 'placeholder'], true)) {
                continue;
            }

            $id = $names[$section->id];
            $name = "OEBPS/{$id}.xhtml";

            // A part is a title, not a document: it opens the file of its
            // first chapter, where a reader lands from the contents, rather
            // than being a near-empty page of its own.
            if ($section->type === 'part' && $id !== 'ch'.($structure->search(fn ($s) => $s->id === $section->id) + 1)) {
                $parts[] = $section;
                // The file itself, not its #section-N: the part heading opens
                // the file, and the chapter's own entry that follows points at
                // the file too. A fragment here put the part after the chapter
                // in reading order (epubcheck NAV-011).
                $entries[] = ['depth' => $page['depth'], 'href' => $id.'.xhtml', 'title' => $section->title];

                if ($bodymatter === null && $page['folio'] === 'arabic') {
                    $bodymatter = [$id.'.xhtml', $section->title];
                }

                continue;
            }

            $opening = collect($parts)->map(fn ($p) => '<section class="part" epub:type="part" id="section-'.$p->id.'">'
                .'<h1 class="part-title">'.e($p->title).'</h1></section>')->implode('');
            $parts = [];

            $files[$name] = $this->xhtml($section->title, $opening.$this->epubSectionBody($section, $part), $language, $matter);

            // Pictures go into the package, each declared once.
            $files[$name] = $this->images->rewrite($files[$name], $book->id, function ($image) use (&$files, &$pictures) {
                $files['OEBPS/images/'.$image['name']] = $image['data'];
                $pictures[$image['name']] = $image['mime'];

                return 'images/'.$image['name'];
            });

            $declare($id, $name);
            $entries[] = ['depth' => $page['depth'], 'href' => $id.'.xhtml', 'title' => $section->title];

            // The chapter's own sections, one level down: a reader of a
            // textbook goes to "1.4 Méthodes", not just to chapter 1.
            // As deep as the design's EPUB contents depth (InteriorDesign):
            // 2, the default, lists level 1 and 2 headings; 3 adds level 3.
            if ($section->type !== 'part') {
                array_push($entries, ...$this->headingEntries($section, $id.'.xhtml', (int) $page['depth'] + 1, $this->tocDepth($book, 'epub')));
            }

            // Where the text begins: the first arabic page.
            if ($bodymatter === null && $page['folio'] === 'arabic') {
                $bodymatter = [$id.'.xhtml', $section->title];
            }
        }

        // The cover. Stores reject an EPUB without one, and every reading
        // app shows it on the shelf. It is the same rendered image Cover
        // Studio previews. A cover that cannot be drawn leaves the book
        // without one rather than failing the whole export.
        $coverManifest = '';
        $coverMeta = '';

        // A profile may leave the cover out (ExportOptions).
        if (ExportOptions::active($book)?->values['cover'] ?? true) {
            try {
                $files['OEBPS/cover.jpg'] = Storage::disk('files')->get($this->covers->ensure($book));
                $files['OEBPS/cover.xhtml'] = $this->xhtml(
                    $book->title,
                    '<div class="cover"><img src="cover.jpg" alt="'.e('Cover of '.$book->title).'"/></div>',
                    $language,
                );

                $coverManifest = '<item id="cover-image" href="cover.jpg" media-type="image/jpeg" properties="cover-image"/>'
                    .'<item id="cover" href="cover.xhtml" media-type="application/xhtml+xml"/>';
                // EPUB 2's form as well: Kindle's converter still reads it.
                $coverMeta = '<meta name="cover" content="cover-image"/>';
                $spine = '<itemref idref="cover" linear="no"/>'.$spine;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // The accessibility metadata stores check for. Claimed only where it
        // is true: textual content is accurate for a text manuscript, and
        // nothing here asserts tested conformance.
        $all = implode('', $files);
        $math = str_contains($all, '<math');
        $visual = $pictures !== [] || str_contains($all, '<figure class="chart">');
        $features = array_filter([
            'structuralNavigation', 'readingOrder', 'tableOfContents', 'displayTransformability',
            $math ? 'MathML' : null,
            $math ? 'describedMath' : null,
            $visual ? 'alternativeText' : null,
        ]);
        $accessibility = '<meta property="schema:accessMode">textual</meta>'
            .($visual ? '<meta property="schema:accessMode">visual</meta>' : '')
            .collect($features)->map(fn ($f) => '<meta property="schema:accessibilityFeature">'.$f.'</meta>')->implode('')
            .'<meta property="schema:accessibilityHazard">none</meta>'
            .'<meta property="schema:accessModeSufficient">textual</meta>'
            .($visual ? '<meta property="schema:accessModeSufficient">textual,visual</meta>' : '')
            .'<meta property="schema:accessibilitySummary">'.e(
                'Structured with headings and a navigable table of contents down to sections; reading order follows the printed book.'
                .($math ? ' Equations are MathML, each with its LaTeX source as alternative text.' : '')
                .($visual ? ' Pictures and charts carry text descriptions.' : '')
            ).'</meta>';

        $description = trim((string) (($book->meta ?? [])['description'] ?? ''));
        $publisher = trim((string) ($publication['imprint'] ?: $publication['publisher']));
        $rights = collect(Anatomy::copyrightLines($book))->whereIn('key', ['notice', 'rights'])->pluck('text')->implode(' ');

        $opf = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="pub-id" prefix="schema: http://schema.org/">'
            .'<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">'
            .'<dc:identifier id="pub-id">'.e($identifier).'</dc:identifier>'
            .'<dc:title id="title">'.e($book->title).'</dc:title>'
            .'<meta refines="#title" property="title-type">main</meta>'
            .($book->subtitle ? '<dc:title id="sub">'.e($book->subtitle).'</dc:title><meta refines="#sub" property="title-type">subtitle</meta>' : '')
            .'<dc:language>'.e($language).'</dc:language>'
            .($book->author_name ? '<dc:creator>'.e($book->author_name).'</dc:creator>' : '')
            .($publisher !== '' ? '<dc:publisher>'.e($publisher).'</dc:publisher>' : '')
            .($rights !== '' ? '<dc:rights>'.e($rights).'</dc:rights>' : '')
            .($description !== '' ? '<dc:description>'.e($description).'</dc:description>' : '')
            .'<meta property="dcterms:modified">'.$modified.'</meta>'
            .$accessibility
            .$coverMeta
            .'</metadata>'
            .'<manifest><item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>'
            .'<item id="css" href="style.css" media-type="text/css"/>'
            .$coverManifest.$manifest
            .collect($pictures)->map(fn ($mime, $name) => '<item id="img-'.e(pathinfo($name, PATHINFO_FILENAME)).'" href="images/'.e($name).'" media-type="'.$mime.'"/>')->implode('')
            .'</manifest>'
            .'<spine>'.$spine.'</spine></package>';

        $contents = $plan['words']['contents'];

        // Landmarks: the cover, the title page, the contents and where the
        // text begins. The cover sits outside the reading order
        // (linear="no"), and EPUB requires such a page to be reachable by a
        // link; without this one epubcheck failed every book with a cover
        // (OPF-096). Only spine pages are linked (RSC-011).
        $bodymatter ??= isset($entries[0]) ? [$entries[0]['href'], $entries[0]['title']] : null;
        $landmarks = array_merge(
            $coverManifest !== '' ? [['cover', 'cover.xhtml', $book->title]] : [],
            $landmarks,
            $bodymatter ? [['bodymatter', $bodymatter[0], $bodymatter[1]]] : [],
        );

        $navDocument = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<!DOCTYPE html><html xmlns="http://www.w3.org/1999/xhtml" '
            .'xmlns:epub="http://www.idpf.org/2007/ops" lang="'.e($language).'" xml:lang="'.e($language).'">'
            .'<head><title>'.e($contents).'</title><meta charset="utf-8"/><link rel="stylesheet" type="text/css" href="style.css"/></head><body epub:type="frontmatter">'
            .'<nav epub:type="toc" id="toc"><h1>'.e($contents).'</h1>'.$this->navList($entries).'</nav>'
            .'<nav epub:type="landmarks" id="landmarks" hidden="hidden"><ol>'
            .collect($landmarks)->map(fn ($l) => '<li><a epub:type="'.$l[0].'" href="'.e($l[1]).'">'.e($l[2]).'</a></li>')->implode('')
            .'</ol></nav>'
            .'</body></html>';

        $container = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">'
            .'<rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles>'
            .'</container>';

        return $this->zip($book, $export, array_merge([
            'META-INF/container.xml' => $container,
            'OEBPS/content.opf' => $opf,
            'OEBPS/nav.xhtml' => $navDocument,
            'OEBPS/style.css' => $this->epubCss($book),
        ], $files), mimetypeFirst: true);
    }

    /**
     * A page the EPUB generates from the book's details (title page,
     * copyright, series list), as [file id, body], or null for one it
     * leaves out. Shared with the reading preview, so both set it alike.
     *
     * @return array{0: string, 1: string}|null
     */
    private function epubGeneratedPage(array $page): ?array
    {
        [$id, $body] = match ($page['part']) {
            'title_page' => ['titlepage', $this->epubTitlePage($page['data'])],
            'copyright_page' => ['copyright', '<section epub:type="copyright-page" class="copyright">'
                .collect($page['data']['lines'] ?? [])->map(fn ($line) => self::copyrightParagraphs($line['text']))->implode('')
                .'</section>'],
            'series_page' => ['series', $this->epubSeriesPage($page['data'])],
            // Pages an export profile adds (ExportOptions).
            'review_notice' => ['notice', '<section class="review-notice"><p>'.e($page['data']['text']).'</p></section>'],
            'continue_reading' => ['continue', '<section class="continue-reading"><h1>'.e($page['data']['heading']).'</h1><p>'.e($page['data']['text']).'</p>'
                .(! empty($page['data']['url']) ? '<p><a href="'.e($page['data']['url']).'">'.e($page['data']['link']).'</a></p>' : '').'</section>'],
            default => [null, null],
        };

        if ($id === null || ($id === 'copyright' && empty($page['data']['lines']))) {
            return null;
        }

        return [$id, $body];
    }

    /**
     * Each section's EPUB file name, in structure() order: "ch3" for the
     * third. A part opens the file of the chapter after it, so its name is
     * that chapter's; a part with no chapter after it keeps its own.
     *
     * @param  Collection<int, ManuscriptSection>  $structure
     * @return array<int, string> section id => name
     */
    private function epubNames(Collection $structure): array
    {
        $names = [];
        $waiting = [];

        foreach ($structure->values() as $i => $section) {
            $names[$section->id] = 'ch'.($i + 1);

            if ($section->type === 'part') {
                $waiting[] = $section->id;

                continue;
            }

            foreach ($waiting as $part) {
                $names[$part] = 'ch'.($i + 1);
            }

            $waiting = [];
        }

        return $names;
    }

    /**
     * Contents entries for a chapter's own headings (levels 1 and 2, and 3
     * one level further in at depth 3; none at depth 1), each linked to its
     * anchor, the first left out when it repeats the title.
     *
     * @return list<array{depth: int, href: string, title: string}>
     */
    private function headingEntries(ManuscriptSection $section, string $href, int $depth, int $levels = 2): array
    {
        $entries = [];
        $first = true;

        if ($levels < 2) {
            return [];
        }

        foreach (array_values(Suggestions::published($section->content)['content'] ?? []) as $i => $node) {
            if (! is_array($node) || ($node['type'] ?? '') !== 'heading' || (int) ($node['attrs']['level'] ?? 2) > ($levels >= 3 ? 3 : 2)) {
                if (is_array($node) && trim($this->plainOf($node)) !== '') {
                    $first = false;
                }

                continue;
            }

            $title = trim($this->plainOf($node));

            if ($title === '' || ($first && mb_strtolower($title) === mb_strtolower(trim($section->title)))) {
                $first = false;

                continue;
            }

            $first = false;
            $entries[] = ['depth' => $depth + ((int) ($node['attrs']['level'] ?? 2) > 2 ? 1 : 0), 'href' => $href.'#'.Textbook::headingAnchor($node, $section->id, $i), 'title' => $title];
        }

        return $entries;
    }

    /** One section's EPUB body: a part's title page, or a chapter's text. */
    private function epubSectionBody(ManuscriptSection $section, ?string $part): string
    {
        $type = self::EPUB_TYPES[$part ?? ''] ?? match ($section->type) {
            'part' => 'part',
            'chapter' => 'chapter',
            default => null,
        };
        $typeAttribute = $type ? ' epub:type="'.$type.'"' : '';

        return $section->type === 'part'
            ? '<section class="part"'.$typeAttribute.'><h1 class="part-title">'.e($section->title).'</h1></section>'
            // EPUB footnotes, which readers open in a pop-up.
            : '<section'.$typeAttribute.'>'.$this->chapterHtml($section, $this->bookNotes
                ? $this->renderer->withNotes('epub')->withBookNotes($this->bookNotes, 'notes.xhtml')
                : $this->renderer->withNotes('epub')).'</section>';
    }

    /**
     * The contents as nested lists: a part's chapters inside its entry.
     *
     * @param  list<array{depth: int, href: string, title: string}>  $entries
     */
    private function navList(array $entries, int $depth = 0): string
    {
        $html = '';
        $count = count($entries);

        for ($i = 0; $i < $count; $i++) {
            $children = [];

            while ($i + 1 < $count && $entries[$i + 1]['depth'] > $depth) {
                $children[] = $entries[++$i];
            }

            // Search back for the entry that owns these children: the one
            // at this depth just before them.
            $html .= '<li><a href="'.e($entries[$i - count($children)]['href']).'">'.e($entries[$i - count($children)]['title']).'</a>'
                .($children ? $this->navList($children, $depth + 1) : '').'</li>';
        }

        return '<ol>'.$html.'</ol>';
    }

    /** The EPUB title page. An anonymous book prints no author. */
    private function epubTitlePage(array $data): string
    {
        $publisher = trim((string) (($data['imprint'] ?? null) ?: ($data['publisher'] ?? null)));

        return '<section epub:type="titlepage" class="title-page">'
            .'<h1 class="book-title">'.e($data['title']).'</h1>'
            .(! empty($data['subtitle']) ? '<p class="book-subtitle">'.e($data['subtitle']).'</p>' : '')
            .(! empty($data['author']) ? '<p class="book-author">'.e($data['author']).'</p>' : '')
            .($publisher !== '' ? '<p class="book-publisher">'.e($publisher).'</p>' : '')
            .'</section>';
    }

    /** "Dans la même collection" or "Also by", as a list. */
    private function epubSeriesPage(array $data): string
    {
        return '<section epub:type="seriespage" class="series-page">'
            .'<h2>'.e($data['heading']).'</h2>'
            .(($data['kind'] ?? '') === 'series' && ! empty($data['series']) ? '<p class="series-name">'.e($data['series']).'</p>' : '')
            .'<ul>'.collect($data['books'] ?? [])->map(fn ($b) => '<li>'.($b['number'] ? e((string) $b['number']).'. ' : '').e($b['title']).'</li>')->implode('').'</ul>'
            .'</section>';
    }

    private function epubCss(Book $book): string
    {
        $layout = $book->layout();

        // em rather than pt: a reader controls the type size in an e-reader,
        // and a fixed point size fights that.
        return "body { line-height: {$layout['leading']}; margin: 0 5%; }\n"
            ."h1 { font-size: 1.6em; margin: 1.5em 0 1em; }\n"
            ."p { margin: 0 0 .25em; text-indent: 1.5em; }\n"
            ."h1 + p { text-indent: 0; }\n"
            ."blockquote { margin: 1em 2em; font-style: italic; }\n"
            ."table { border-collapse: collapse; width: 100%; margin: 1em 0; font-size: .85em; }\n"
            ."th, td { text-align: left; vertical-align: top; padding: .25em .5em .25em 0; border-bottom: 1px solid #ccc; }\n"
            ."th { border-top: 2px solid #222; border-bottom: 1px solid #222; }\n"
            ."th p, td p { margin: 0; text-indent: 0; }\n"
            ."pre { font-family: monospace; font-size: .8em; white-space: pre-wrap; }\n"
            ."p.caption, p.table-note { text-indent: 0; font-size: .9em; }\n"
            ."figure.chart { margin: 1em 0; text-align: center; }\n"
            ."sup.fn-ref a { text-decoration: none; }\n"
            ."section.footnotes { margin-top: 2em; border-top: 1px solid #999; font-size: .85em; }\n"
            ."aside.footnote p { text-indent: 0; margin: .4em 0; }\n"
            ."figure.chart svg { max-width: 100%; height: auto; }\n"
            .".math-display { position: relative; text-align: center; margin: 1em 0; }\n"
            .".math-number { position: absolute; right: 0; top: 50%; transform: translateY(-50%); }\n"
            .".title-page { text-align: center; margin: 3em 0; }\n"
            .".title-page p, .copyright p, .series-page p { text-indent: 0; }\n"
            .".book-subtitle { font-style: italic; }\n"
            .".book-publisher { margin-top: 3em; letter-spacing: .1em; text-transform: uppercase; font-size: .85em; }\n"
            .".copyright { margin-top: 2em; font-size: .8em; }\n"
            .".copyright p { margin: 0 0 .7em; }\n"
            .".series-page { text-align: center; }\n"
            .".series-page ul { list-style: none; padding: 0; }\n"
            .'img { max-width: 100%; height: auto; }'."\n"
            // Boxes (Textbook): upright text, a label, a rule or a tint,
            // readable in black and white on an e-ink screen.
            ."aside.callout { margin: 1em 0; padding: .5em .8em; border: 1px solid #555; font-style: normal; }\n"
            ."aside.callout p { text-indent: 0; margin: 0 0 .4em; }\n"
            .".callout-label { font-variant: small-caps; letter-spacing: .04em; }\n"
            .".callout-pitfall { border-left: 4px solid #000; }\n"
            .".callout-key { background: #ececec; border-color: #ececec; }\n"
            .".callout-tip { border-style: dashed; }\n"
            .".callout-example { border-width: 1px 0; }\n"
            .".callout-note { border-width: 0 0 0 2px; }\n"
            ."ul.checklist { list-style: none; padding-left: 0; }\n"
            ."ul.checklist li { margin: .3em 0; }\n"
            .".check-box { margin-right: .4em; }\n"
            .".exam-banner { border: 2px solid #000; padding: .6em .8em; text-align: center; margin: 1em 0; }\n"
            .".exam-banner p, .exam-directions p, p.exam-end { text-indent: 0; }\n"
            .".exam-title { font-weight: bold; }\n"
            ."ul.exam-fields { list-style: none; padding: 0; margin: .3em 0 0; }\n"
            .".exam-directions { border: 1px solid #000; border-left-width: 4px; padding: .5em .8em; margin: 0 0 1em; }\n"
            ."p.exam-end { text-align: center; font-weight: bold; letter-spacing: .1em; margin: 1.5em 0; }\n"
            .".ex-link { font-size: .85em; font-style: italic; margin-left: .3em; }\n"
            ."table.grid th, table.grid td { border: 1px solid #000; padding: .3em .4em; }\n"
            ."table.grid td.write-in { height: 1.8em; min-width: 3em; }\n"
            ."table.grid tr.total td { font-weight: bold; }\n"
            // The book's own box styles, when it has changed them.
            .InteriorDesign::calloutCss($layout['callouts'], 'epub')
            // Placed pictures and styled tables, only in a book that has them.
            .(Placement::bookUses($book) ? Placement::readerCss() : '')
            // The pages an export profile adds, only when it adds them, so
            // a Full-book EPUB's stylesheet is what it always was.
            .(ExportOptions::active($book)?->isDefault() === false
                ? ".review-notice { text-align: center; margin-top: 30%; font-weight: bold; }
"
                    .".review-notice p, .continue-reading p, .notes li p { text-indent: 0; }
"
                    .".notes h2 { font-size: 1.1em; margin: 1.2em 0 .4em; }
"
                : '');
    }

    /** One content document; $matter is the body's epub:type (frontmatter, bodymatter, backmatter). */
    private function xhtml(string $title, string $body, string $language, ?string $matter = null): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<!DOCTYPE html><html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" lang="'.e($language)
            .'" xml:lang="'.e($language).'"><head><meta charset="utf-8"/><title>'.e($title)
            .'</title><link rel="stylesheet" type="text/css" href="style.css"/></head><body'
            .($matter ? ' epub:type="'.$matter.'"' : '').'>'
            .$body.'</body></html>';
    }

    /** A standalone HTML page. */
    private function page(Book $book, string $body, bool $standalone = false, string $css = ''): string
    {
        $language = self::languageCode($book->language);

        $default = $css !== '' ? $css : <<<'CSS'
        body { max-width: 38em; margin: 3em auto; padding: 0 1.25em;
               font-family: Georgia, 'Times New Roman', serif; line-height: 1.6; }
        h1 { font-size: 1.8em; margin: 2em 0 1em; }
        img { max-width: 100%; height: auto; }
        .title-page { text-align: center; margin: 4em 0 5em; }
        .book-title { font-size: 2.4em; margin: 0 0 .3em; }
        .book-subtitle { font-size: 1.15em; font-style: italic; margin: 0 0 2em; }
        .book-author { font-size: 1em; letter-spacing: .08em; text-transform: uppercase; }
        .toc { margin: 0 0 4em; }
        .toc ol { list-style: none; padding: 0; }
        .toc li { margin: .35em 0 .35em 1.5em; }
        .toc li.toc-part { margin-left: 0; font-weight: 700; margin-top: 1.2em; }
        .toc a { text-decoration: none; color: inherit; }
        .part { text-align: center; margin: 5em 0 3em; }
        .part-title { font-size: 1.6em; letter-spacing: .04em; }
        .copyright { font-size: .85em; margin: 0 0 4em; }
        CSS;

        // Placed pictures and styled tables, only in a book that has them.
        if ($css === '' && Placement::bookUses($book)) {
            $default .= "\n".Placement::readerCss();
        }

        return '<!doctype html>'."\n".'<html lang="'.e($language).'">'."\n"
            .'<head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.e($book->title).'</title>'
            .'<style>'.$default.'</style></head>'."\n"
            .'<body>'."\n".$body."\n".'</body></html>'."\n";
    }

    /**
     * The front and back matter this export leaves out: the profile's and
     * the book's own set for the format (ExportOptions). Only the formats
     * that set their front matter without the anatomy (HTML) need it.
     *
     * @return list<string>
     */
    private function leftOut(Book $book): array
    {
        $options = ExportOptions::active($book);

        return $options ? [...ExportOptions::bookMatter($book, (string) ExportOptions::activeFormat()), ...$options->values['exclude']] : [];
    }

    /**
     * The pages an export profile adds to the book (the review notice, the
     * notes at the end, the "Continue reading" page), by part, for the
     * formats that do not walk the anatomy page by page.
     *
     * @return array<string, array<string, mixed>>
     */
    private function addedPages(Book $book): array
    {
        if (! ExportOptions::active($book)) {
            return [];
        }

        return collect(Anatomy::plan($book)['pages'])
            ->whereIn('part', ['review_notice', 'continue_reading', 'book_notes'])
            ->keyBy('part')
            ->all();
    }

    /** How deep the contents goes: the profile's override, else the design's. */
    private function tocDepth(Book $book, string $kind): int
    {
        return (int) (ExportOptions::active($book)?->values['toc_depth'] ?? $book->layout()['toc'][$kind]);
    }

    /**
     * Standard manuscript format (Manuscript), as a PDF or a Word document:
     * the text as a submission, not as a book.
     */
    private function manuscript(Book $book, BookExport $export, ExportOptions $profile): string
    {
        $language = self::languageCode($book->language);
        $sections = $this->structure($book);
        $manuscript = app(Manuscript::class);

        if ($export->format === 'pdf') {
            return $this->put($book, $export, $manuscript->pdf($book, $sections, $this->renderer, $profile, $language, $this->bookNotes));
        }

        $this->renderer->resetDocxMedia();
        $body = $manuscript->docxBody($book, $sections, $this->renderer, $profile, $language, fn (bool $title) => Manuscript::docxSection($title, $language));

        return $this->docxPackage($book, $export, $body, Manuscript::docxStyles($profile->values['manuscript_font'], self::wordLanguage($language)), [
            'rIdMsHeader' => ['header1.xml', Manuscript::docxHeader($book)],
            'rIdMsBlank' => ['header2.xml', Manuscript::docxBlankHeader()],
        ]);
    }

    /**
     * Map a display language to a BCP 47 code.
     *
     * EPUB requires one, and stores reject a file whose dc:language is a
     * display string like "English (US)". Every language the book settings
     * offer needs a case here: a Kreyòl book that fell through to en-US was
     * catalogued, hyphenated and labelled as English.
     */
    public static function languageCode(?string $language): string
    {
        return match ($language) {
            'Spanish' => 'es',
            'French' => 'fr',
            'Haitian Creole', 'Kreyòl', 'Kreyol' => 'ht',
            'German' => 'de',
            'Portuguese' => 'pt',
            'English (UK)' => 'en-GB',
            // The translation languages Editions offers.
            'Italian' => 'it',
            'Dutch' => 'nl',
            // English has always been written as en-US here.
            'en', 'English', 'English (US)' => 'en-US',
            default => self::bcp47($language) ?? 'en-US',
        };
    }

    /**
     * A language the author gave as a BCP 47 tag ("fr-CA", "it", "pt-BR"),
     * in its usual case, or null when it is not one. Display names never
     * match: they are longer than a primary subtag.
     */
    public static function bcp47(?string $language): ?string
    {
        $language = trim((string) $language);

        if (! preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $language)) {
            return null;
        }

        $parts = explode('-', $language);

        return implode('-', array_map(fn (string $p, int $i) => match (true) {
            $i === 0 => strtolower($p),
            strlen($p) === 2 => strtoupper($p),
            strlen($p) === 4 => ucfirst(strtolower($p)),
            default => strtolower($p),
        }, $parts, array_keys($parts)));
    }

    private function put(Book $book, BookExport $export, string $contents): string
    {
        $path = $this->directory($book).'/'.$this->filename($book, $export);

        // The files disk: the bucket in production, so an export survives
        // the next deploy instead of vanishing with the container.
        Storage::disk('files')->put($path, $contents);

        return $path;
    }

    /**
     * Write a zip container.
     *
     * @param  array<string, string>  $entries
     * @param  bool  $mimetypeFirst  EPUB requires an uncompressed `mimetype`
     *                               entry first in the archive; a reader that
     *                               cannot find it there rejects the file.
     */
    private function zip(Book $book, BookExport $export, array $entries, bool $mimetypeFirst = false): string
    {
        $relative = $this->directory($book).'/'.$this->filename($book, $export);

        // Built in a temporary file, because ZipArchive needs a real path
        // and the files disk may be a bucket, then stored like any export.
        //
        // In the app's own storage rather than the system temp directory:
        // tempnam() there raised a notice on a worker whose TEMP it would
        // not use, which Laravel turns into an exception, and the EPUB
        // failed. A random name in a folder we own needs no permission
        // from the host, and the finally removes it however this ends.
        $directory = storage_path('app/tmp');

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create the export file.');
        }

        $absolute = $directory.'/export-'.Str::random(24).'.zip';

        try {
            $archive = new ZipArchive;

            if ($archive->open($absolute, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create the export file.');
            }

            if ($mimetypeFirst) {
                $archive->addFromString('mimetype', 'application/epub+zip');
                $archive->setCompressionName('mimetype', ZipArchive::CM_STORE);
            }

            foreach ($entries as $name => $contents) {
                $archive->addFromString($name, $contents);
            }

            if (! $archive->close()) {
                throw new RuntimeException('Could not write the export file.');
            }

            $stream = fopen($absolute, 'rb');
            Storage::disk('files')->put($relative, $stream);
        } finally {
            if (isset($stream) && is_resource($stream)) {
                fclose($stream);
            }

            if (is_file($absolute)) {
                @unlink($absolute);
            }
        }

        return $relative;
    }
}
