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
 * Tables and code blocks, from import to every export.
 *
 * A thesis is made of tables: participants, descriptive statistics, model
 * fits, requirements. An importer that turned them into paragraphs of pipes
 * would make the app useless for the people who most need structure.
 */
function markdownDoc(string $markdown): array
{
    $importer = app(MarkdownImporter::class);

    return (new ReflectionMethod($importer, 'toProseMirror'))->invoke($importer, $markdown, new ImportReport);
}

function tableSection(array $doc): ManuscriptSection
{
    $book = Book::factory()->for(User::factory())->create(['title' => 'Tables']);

    return ManuscriptSection::factory()->for($book)->create([
        'title' => 'Results',
        'type' => 'chapter',
        'content' => $doc,
    ]);
}

const PARTICIPANTS = <<<'MD'
**Table 4.1** Participant characteristics (n = 3)
| Pseudonym | Age | Programme |
|---|---:|---|
| Amara | 34 | *MBA* |
| Luis | 29 | MS Nursing \| Leadership |
| Keisha | 41 |
*Note.* Pseudonyms are used throughout.
MD;

it('imports a table with its caption and note as paragraphs around it', function () {
    $content = markdownDoc(PARTICIPANTS)['content'];

    expect(array_column($content, 'type'))->toBe(['paragraph', 'table', 'paragraph']);

    $rows = $content[1]['content'];

    expect($rows)->toHaveCount(4)
        ->and($rows[0]['content'][0]['type'])->toBe('tableHeader')
        ->and($rows[1]['content'][0]['type'])->toBe('tableCell')
        ->and($rows[1]['content'][2]['content'][0]['content'][0]['marks'][0]['type'])->toBe('italic')
        // An escaped pipe is text, not a column.
        ->and($rows[2]['content'][2]['content'][0]['content'][0]['text'])->toBe('MS Nursing | Leadership')
        // A short row is padded so every row has the same width.
        ->and($rows[3]['content'])->toHaveCount(3);
});

it('leaves prose with a stray pipe alone', function () {
    expect(markdownDoc('Either this | or that, said nobody.')['content'][0]['type'])->toBe('paragraph');
});

it('imports a fenced code block whole, blank lines and all', function () {
    $content = markdownDoc("Before.\n\n```js\nconst a = 1;\n\nconst b = 2;\n```\n\nAfter.")['content'];

    expect(array_column($content, 'type'))->toBe(['paragraph', 'codeBlock', 'paragraph'])
        ->and($content[1]['attrs']['language'])->toBe('js')
        ->and($content[1]['content'][0]['text'])->toBe("const a = 1;\n\nconst b = 2;");
});

it('renders a table to HTML with a header and to Markdown', function () {
    $section = tableSection(markdownDoc(PARTICIPANTS));
    $renderer = app(DocumentRenderer::class);

    $html = $renderer->html($section);

    expect($html)->toContain('<thead>')
        ->and($html)->toContain('<th>Pseudonym</th>')
        ->and($html)->toContain('<td><em>MBA</em></td>')
        ->and($renderer->markdown($section))->toContain("| Pseudonym | Age | Programme |\n| --- | --- | --- |");
});

it('round-trips a table through Markdown export and import', function () {
    $renderer = app(DocumentRenderer::class);
    $first = markdownDoc(PARTICIPANTS);
    $again = markdownDoc($renderer->markdown(tableSection($first)));

    expect($again['content'][1])->toBe($first['content'][1]);
});

it('keeps structure in the DOCX: headings, italics, quotes and tables', function () {
    $book = Book::factory()->for(User::factory())->create(['title' => 'A Thesis']);

    ManuscriptSection::factory()->for($book)->create([
        'title' => 'Chapter 4: Findings',
        'type' => 'chapter',
        'content' => markdownDoc("## 4.1 Overview\n\nA *careful* reading.\n\n> I was the one with the camera off.\n\n".PARTICIPANTS),
    ]);

    $export = app(Exporter::class)->generate($book->fresh(), 'docx');

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($export->path));
    $document = $zip->getFromName('word/document.xml');
    $styles = $zip->getFromName('word/styles.xml');
    $zip->close();

    expect($document)->toContain('<w:pStyle w:val="Heading2"/>')
        ->and($document)->toContain('<w:rPr><w:i/></w:rPr><w:t xml:space="preserve">careful</w:t>')
        ->and($document)->toContain('<w:pStyle w:val="Quote"/>')
        ->and($document)->toContain('<w:tbl>')
        ->and($document)->toContain('<w:tblHeader/>')
        ->and(substr_count($document, '<w:tr>'))->toBe(4)
        ->and($styles)->toContain('w:styleId="Quote"');

    // Well-formed XML, or Word offers to "repair" the file.
    expect(simplexml_load_string($document))->not->toBeFalse();
});

it('typesets a table in the PDF', function () {
    $book = Book::factory()->for(User::factory())->create(['title' => 'A Thesis']);

    ManuscriptSection::factory()->for($book)->create([
        'title' => 'Results',
        'type' => 'chapter',
        'content' => markdownDoc(PARTICIPANTS),
    ]);

    $html = app(PrintBook::class)->html($book->fresh(), $book->sections()->get(), 'en');

    // A table this short is kept on one page.
    expect($html)->toContain('<table class="keep">')
        ->and($html)->toContain('.text table');
});

it('survives the import round trip with tables and code intact', function () {
    $user = User::factory()->create();
    $book = $user->books()->create(['title' => 'Round trip']);

    app(MarkdownImporter::class)->import($book, "# One\n\n".PARTICIPANTS."\n\n```\nlet x = 1;\n```", $user->id);
    $once = app(MarkdownExporter::class)->book($book->fresh());

    $again = $user->books()->create(['title' => 'Again']);
    app(MarkdownImporter::class)->import($again, $once, $user->id);

    expect(app(MarkdownExporter::class)->book($again->fresh()))->toBe($once)
        ->and($once)->toContain('| Pseudonym | Age | Programme |')
        ->and($once)->toContain("```\nlet x = 1;\n```");
});
