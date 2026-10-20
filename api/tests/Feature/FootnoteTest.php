<?php

use App\Import\ImportReport;
use App\Import\MarkdownExporter;
use App\Import\MarkdownImporter;
use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\DocumentRenderer;
use App\Publishing\Exporter;
use App\Publishing\PrintBook;
use Illuminate\Support\Facades\Storage;

/**
 * Footnotes: the reference in the text, the note at the foot or the end.
 *
 * History, law, philosophy and theology cite in notes, so a thesis in those
 * fields is unwritable without them.
 */
const NOTED = <<<'MD'
The treaty was signed in haste.[^a] Its terms were never published.[^b]

[^a]: Hobsbawm, *The Age of Empire*, 41.
[^b]: Archive file 12, folder 3.
MD;

function notesDoc(string $markdown): array
{
    $importer = app(MarkdownImporter::class);

    return (new ReflectionMethod($importer, 'toProseMirror'))->invoke($importer, $markdown, new ImportReport);
}

function notedBook(): Book
{
    $book = Book::factory()->for(User::factory())->create(['title' => 'Notes']);

    ManuscriptSection::factory()->for($book)->create([
        'title' => 'Chapter 2: The Treaty',
        'type' => 'chapter',
        'content' => notesDoc(NOTED),
    ]);

    return $book->fresh();
}

it('imports Pandoc footnotes as notes in the text', function () {
    $paragraph = notesDoc(NOTED)['content'][0]['content'];

    expect($paragraph[1])->toBe(['type' => 'footnote', 'attrs' => ['text' => 'Hobsbawm, *The Age of Empire*, 41.']])
        ->and($paragraph[3])->toBe(['type' => 'footnote', 'attrs' => ['text' => 'Archive file 12, folder 3.']])
        // The definitions are notes now, not paragraphs.
        ->and(notesDoc(NOTED)['content'])->toHaveCount(1);
});

it('leaves a reference with no definition as typed', function () {
    expect(notesDoc('See below.[^missing]')['content'][0]['content'])
        ->toBe([['type' => 'text', 'text' => 'See below.'], ['type' => 'text', 'text' => '[^missing]']]);
});

it('numbers notes from 1 in each chapter and links them both ways', function () {
    $html = app(DocumentRenderer::class)->html(notedBook()->sections()->first());

    expect($html)->toContain('<sup class="fn-ref"><a href="#fn-s')
        ->and($html)->toContain('role="doc-noteref">2</a></sup>')
        ->and($html)->toContain('<section class="footnotes" role="doc-endnotes"><ol>')
        ->and($html)->toContain('Hobsbawm, <em>The Age of Empire</em>, 41.')
        ->and($html)->toContain('role="doc-backlink"');
});

it('sets notes at the chapter end in print, without links back', function () {
    $book = notedBook();
    $html = app(PrintBook::class)->html($book, $book->sections()->get(), 'en');

    expect($html)->toContain('<section class="footnotes"')
        ->and($html)->not->toContain('doc-backlink');
});

it('makes real Word footnotes', function () {
    $export = app(Exporter::class)->generate(notedBook(), 'docx');

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($export->path));
    $document = $zip->getFromName('word/document.xml');
    $notes = $zip->getFromName('word/footnotes.xml');

    expect($document)->toContain('<w:footnoteReference w:id="1"/>')
        ->and($document)->toContain('<w:footnoteReference w:id="2"/>')
        ->and($notes)->toContain('<w:footnote w:id="1">')
        ->and($notes)->toContain('<w:rPr><w:i/></w:rPr><w:t xml:space="preserve">The Age of Empire</w:t>')
        ->and($zip->getFromName('word/_rels/document.xml.rels'))->toContain('Target="footnotes.xml"')
        ->and($zip->getFromName('[Content_Types].xml'))->toContain('/word/footnotes.xml')
        ->and($zip->getFromName('word/styles.xml'))->toContain('w:styleId="FootnoteText"')
        ->and(simplexml_load_string($notes))->not->toBeFalse()
        ->and(simplexml_load_string($document))->not->toBeFalse();

    $zip->close();
});

it('makes EPUB pop-up footnotes', function () {
    $export = app(Exporter::class)->generate(notedBook(), 'epub');

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($export->path));
    $chapter = $zip->getFromName('OEBPS/ch1.xhtml');
    $zip->close();

    expect($chapter)->toContain('epub:type="noteref"')
        ->and($chapter)->toContain('<aside epub:type="footnote"')
        ->and($chapter)->toContain('xmlns:epub="http://www.idpf.org/2007/ops"')
        ->and(simplexml_load_string($chapter))->not->toBeFalse();
});

it('round-trips footnotes through Markdown', function () {
    $book = notedBook();
    $markdown = app(DocumentRenderer::class)->markdown($book->sections()->first());

    expect($markdown)->toContain("[^1]: Hobsbawm, *The Age of Empire*, 41.\n[^2]: Archive file 12, folder 3.")
        ->and(notesDoc($markdown))->toBe(notesDoc(NOTED));

    // And through the whole-book exporter and importer.
    $once = app(MarkdownExporter::class)->book($book);
    $again = $book->user->books()->create(['title' => 'Again']);
    app(MarkdownImporter::class)->import($again, $once, $book->user_id);

    expect(app(MarkdownExporter::class)->book($again->fresh()))->toBe($once);
});
