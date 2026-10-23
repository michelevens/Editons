<?php

namespace App\Publishing;

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
    ) {}

    /** Generate one file now and record it: start() then run(). */
    public function generate(Book $book, string $format, ?int $userId = null): BookExport
    {
        return $this->run($this->start($book, $format, $userId));
    }

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
    public function start(Book $book, string $format, ?int $userId = null): BookExport
    {
        if (! isset(BookExport::FORMATS[$format])) {
            throw new RuntimeException("There is no {$format} export.");
        }

        if ($this->chapters($book)->isEmpty()) {
            throw new RuntimeException('There is nothing to export yet. Write a chapter first.');
        }

        $queued = in_array($format, GenerateExport::FORMATS, true);

        if ($queued) {
            // A dead render must not block the next one for good.
            $this->expireStale($book->id);

            if ($running = $this->rendering($book, $format)) {
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

            // Numbering for the whole book: captions and equations renumber
            // from position, and cross-references resolve, in every format.
            // EPUB splits the book into files, so its links name the file.
            $structure = $this->structure($book);
            $this->renderer = $base->withNumbering(
                Numbering::forBook($structure),
                $format === 'epub' ? $structure->values()->mapWithKeys(fn ($s, $i) => [$s->id => 'ch'.($i + 1).'.xhtml'])->all() : [],
            );

            $path = match ($format) {
                'epub' => $this->epub($book, $sections, $export),
                'pdf' => $this->pdf($book, $sections, $export),
                'pdf_print' => $this->printable($book, $sections, $export),
                'docx' => $this->docx($book, $sections, $export),
                'md' => $this->markdown($book, $sections, $export),
                'html' => $this->singleHtml($book, $sections, $export),
            };

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
            }
        } catch (\Throwable $e) {
            // Recorded, not swallowed: the history should show that a
            // generation was attempted and failed, and the exception still
            // reaches the caller so the author sees why.
            $failed = BookExport::whereKey($export->id)->where('status', 'generating')->update(['status' => 'failed', 'updated_at' => now()]);

            if ($failed && $queued && $export->book) {
                Notify::exportFailed($export->fresh(), $this->reason($e));
            }

            throw $e;
        } finally {
            // The next export starts from the plain renderer, not this book's.
            $this->renderer = $base;
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
     * Chapters in reading order, parts excluded.
     *
     * A part is a container holding chapters, so exporting it would emit its
     * title twice — once as the part and once as the chapter beneath it.
     *
     * @return Collection<int, ManuscriptSection>
     */
    private function chapters(Book $book)
    {
        return $book->sections()
            ->orderBy('position')
            ->get()
            ->reject(fn (ManuscriptSection $section) => $section->isContainer())
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
     */
    private function structure(Book $book)
    {
        return $book->sections()
            ->orderBy('position')
            ->get()
            ->filter(fn (ManuscriptSection $s) => $s->type === 'part'
                || Numbering::listKind($s) !== null
                || trim($s->plainText()) !== '')
            ->values();
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
        return "{$slug}-{$export->id}.".$export->extension();
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

        foreach ($sections as $section) {
            // Same reason as the HTML exports: skip the title when the prose
            // already opens with one, rather than printing it twice.
            if (! $this->opensWithHeading($section)) {
                $parts[] = "\n## {$section->title}\n";
            }

            $parts[] = $this->renderer->markdown($section);
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

        $body = $this->titlePageHtml($book).$this->contentsHtml($entries, self::languageCode($book->language));

        foreach ($sections as $i => $section) {
            $body .= $this->structuredHtml($section, $entries[$i]['id']);
        }

        // One self-contained file: pictures travel inside it.
        $body = $this->images->inline($body, $book->id);

        return $this->put($book, $export, $this->page($book, $body, standalone: true));
    }

    /**
     * A real PDF, typeset as a printed book.
     *
     * dompdf renders it; PrintBook does the book design. The print-ready
     * HTML is made from the same pages, so fix the layout once and both
     * improve.
     */
    private function pdf(Book $book, $sections, BookExport $export): string
    {
        return $this->put($book, $export, $this->print->pdf(
            $book, $this->structure($book), self::languageCode($book->language),
        ));
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
     * @param  Collection<int, ManuscriptSection>  $sections
     */
    private function docx(Book $book, $sections, BookExport $export): string
    {
        $paragraphs = $this->docxParagraph($book->title, 'Title');

        if ($book->subtitle) {
            $paragraphs .= $this->docxParagraph($book->subtitle, 'Subtitle');
        }

        if ($book->author_name) {
            $paragraphs .= $this->docxParagraph($book->author_name, 'Subtitle');
        }

        $this->renderer->resetDocxMedia();

        foreach ($sections as $section) {
            $paragraphs .= $this->docxParagraph($section->title, 'Heading1');

            // Structure, not plain text: headings, italics, quotes, lists and
            // tables survive, because Word is where an examiner or an editor
            // will read it.
            $paragraphs .= $this->renderer->docx($section);
        }

        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            .'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
            .'<w:body>'.$paragraphs.'</w:body></w:document>';

        // Pictures placed in the text, as package parts; footnotes and list
        // numbering as theirs.
        $media = $this->renderer->docxMedia();
        $footnotes = $this->renderer->docxFootnotes();
        $numbering = $this->renderer->docxNumbering();

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .collect($media)->mapWithKeys(fn ($m) => [pathinfo($m['file'], PATHINFO_EXTENSION) => $m['mime']])
                ->map(fn ($mime, $extension) => '<Default Extension="'.$extension.'" ContentType="'.$mime.'"/>')->implode('')
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
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
            .($footnotes ? '<Relationship Id="rIdNotes" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footnotes" Target="footnotes.xml"/>' : '')
            .($numbering ? '<Relationship Id="rIdNumbering" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>' : '')
            .collect($media)->map(fn ($m, $id) => '<Relationship Id="'.$id.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/'.$m['file'].'"/>')->implode('')
            .'</Relationships>';

        return $this->zip($book, $export, [
            '[Content_Types].xml' => $contentTypes,
            '_rels/.rels' => $rels,
            'word/document.xml' => $document,
            'word/_rels/document.xml.rels' => $documentRels,
            'word/styles.xml' => $this->docxStyles(self::wordLanguage(self::languageCode($book->language))),
            'docProps/core.xml' => $this->docxCore($book),
            ...($footnotes ? ['word/footnotes.xml' => $footnotes] : []),
            ...($numbering ? ['word/numbering.xml' => $numbering] : []),
            ...collect($media)->mapWithKeys(fn ($m) => ['word/media/'.$m['file'] => $m['data']])->all(),
        ]);
    }

    private function docxParagraph(string $text, ?string $style = null): string
    {
        $properties = $style ? '<w:pPr><w:pStyle w:val="'.$style.'"/></w:pPr>' : '';

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
            .'<w:pPr><w:pageBreakBefore/><w:spacing w:after="240"/><w:outlineLvl w:val="0"/></w:pPr>'
            .'<w:rPr><w:sz w:val="36"/><w:b/></w:rPr></w:style>'
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
     * A valid EPUB 3 container.
     *
     * @param  Collection<int, ManuscriptSection>  $sections
     */
    private function epub(Book $book, $sections, BookExport $export): string
    {
        $uuid = 'urn:uuid:'.Str::uuid()->toString();
        $identifier = $book->isbn ? 'urn:isbn:'.$book->isbn : $uuid;
        $language = self::languageCode($book->language);
        $modified = now()->utc()->format('Y-m-d\TH:i:s\Z');

        $files = [];
        $pictures = [];
        $manifest = '';
        $spine = '';
        $nav = '';

        // The whole book, parts included: a reader opening the contents of
        // an EPUB should see the same structure the binder shows.
        $sections = $this->structure($book);

        foreach ($sections as $index => $section) {
            $id = 'ch'.($index + 1);
            $name = "OEBPS/{$id}.xhtml";

            $files[$name] = $this->xhtml(
                $section->title,
                $section->type === 'part'
                    ? '<section class="part"><h1 class="part-title">'.e($section->title).'</h1></section>'
                    // EPUB footnotes, which readers open in a pop-up.
                    : $this->chapterHtml($section, $this->renderer->withNotes('epub')),
                $language,
            );

            // Pictures go into the package, each declared once.
            $files[$name] = $this->images->rewrite($files[$name], $book->id, function ($image) use (&$files, &$pictures) {
                $files['OEBPS/images/'.$image['name']] = $image['data'];
                $pictures[$image['name']] = $image['mime'];

                return 'images/'.$image['name'];
            });

            // EPUB 3 requires declaring MathML; a validator rejects the book
            // otherwise, and a store with it.
            $flags = array_filter([
                str_contains($files[$name], '<math') ? 'mathml' : null,
                str_contains($files[$name], '<svg') ? 'svg' : null,
            ]);
            $properties = $flags ? ' properties="'.implode(' ', $flags).'"' : '';
            $manifest .= '<item id="'.$id.'" href="'.$id.'.xhtml" media-type="application/xhtml+xml"'.$properties.'/>';
            $spine .= '<itemref idref="'.$id.'"/>';
            $nav .= '<li><a href="'.$id.'.xhtml">'.e($section->title).'</a></li>';
        }

        // The cover. Stores reject an EPUB without one, and every reading
        // app shows it on the shelf. It is the same rendered image Cover
        // Studio previews. A cover that cannot be drawn leaves the book
        // without one rather than failing the whole export.
        $coverManifest = '';
        $coverMeta = '';

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

        // The accessibility metadata stores check for. Claimed only where it
        // is true: textual content is accurate for a text manuscript, and
        // nothing here asserts tested conformance.
        $accessibility = '<meta property="schema:accessMode">textual</meta>'
            .'<meta property="schema:accessibilityFeature">structuralNavigation</meta>'
            .'<meta property="schema:accessibilityFeature">readingOrder</meta>'
            .'<meta property="schema:accessModeSufficient">textual</meta>';

        $description = trim((string) (($book->meta ?? [])['description'] ?? ''));

        $opf = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="pub-id" prefix="schema: http://schema.org/">'
            .'<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">'
            .'<dc:identifier id="pub-id">'.e($identifier).'</dc:identifier>'
            .'<dc:title>'.e($book->title).'</dc:title>'
            .($book->subtitle ? '<dc:title id="sub">'.e($book->subtitle).'</dc:title>' : '')
            .'<dc:language>'.e($language).'</dc:language>'
            .($book->author_name ? '<dc:creator>'.e($book->author_name).'</dc:creator>' : '')
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

        $contents = PrintBook::words($language)['contents'];

        // Landmarks: where the cover and the text begin. The cover sits
        // outside the reading order (linear="no"), and EPUB requires such a
        // page to be reachable by a link; without this one epubcheck failed
        // every book with a cover (OPF-096). Only spine pages are linked: a
        // link back into this file is itself an error (RSC-011).
        $landmarks = '<nav epub:type="landmarks" id="landmarks" hidden="hidden"><ol>'
            .($coverManifest !== '' ? '<li><a epub:type="cover" href="cover.xhtml">'.e($book->title).'</a></li>' : '')
            .($sections->isNotEmpty() ? '<li><a epub:type="bodymatter" href="ch1.xhtml">'.e($sections->first()->title).'</a></li>' : '')
            .'</ol></nav>';

        $navDocument = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<!DOCTYPE html><html xmlns="http://www.w3.org/1999/xhtml" '
            .'xmlns:epub="http://www.idpf.org/2007/ops" lang="'.e($language).'" xml:lang="'.e($language).'">'
            .'<head><title>'.e($contents).'</title><meta charset="utf-8"/></head><body>'
            .'<nav epub:type="toc" id="toc"><h1>'.e($contents).'</h1><ol>'.$nav.'</ol></nav>'
            .$landmarks
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
            .'img { max-width: 100%; height: auto; }'."\n";
    }

    private function xhtml(string $title, string $body, string $language): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<!DOCTYPE html><html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" lang="'.e($language)
            .'" xml:lang="'.e($language).'"><head><meta charset="utf-8"/><title>'.e($title)
            .'</title><link rel="stylesheet" type="text/css" href="style.css"/></head><body>'
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
        CSS;

        return '<!doctype html>'."\n".'<html lang="'.e($language).'">'."\n"
            .'<head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.e($book->title).'</title>'
            .'<style>'.$default.'</style></head>'."\n"
            .'<body>'."\n".$body."\n".'</body></html>'."\n";
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
            default => 'en-US',
        };
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
