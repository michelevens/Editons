<?php

use App\Import\DocxImporter;
use App\Models\Book;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Word import, from a .docx built here part by part.
 *
 * The fixtures are written as WordprocessingML rather than saved from Word,
 * so each test shows exactly which construct it is about.
 */
const DOCX_W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture" xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math"';

/**
 * @param  array<string, string>  $parts  extra or replacement zip entries
 */
function docxFile(string $body, array $parts = []): string
{
    $path = tempnam(sys_get_temp_dir(), 'docx').'.docx';
    // Kept in a variable: the fake's temporary file goes with the object.
    $png = UploadedFile::fake()->image('map.png', 2, 2);
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    $defaults = [
        '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
        'word/document.xml' => '<?xml version="1.0" encoding="UTF-8"?><w:document '.DOCX_W.'><w:body>'.$body.'</w:body></w:document>',
        'word/styles.xml' => '<?xml version="1.0"?><w:styles '.DOCX_W.'>'
            .'<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/></w:style>'
            .'<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:basedOn w:val="Heading1"/><w:pPr><w:outlineLvl w:val="1"/></w:pPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="ListBullet"><w:name w:val="List Bullet"/><w:pPr><w:numPr><w:numId w:val="1"/></w:numPr></w:pPr></w:style>'
            .'</w:styles>',
        'word/numbering.xml' => '<?xml version="1.0"?><w:numbering '.DOCX_W.'>'
            .'<w:abstractNum w:abstractNumId="0"><w:lvl w:ilvl="0"><w:numFmt w:val="bullet"/></w:lvl><w:lvl w:ilvl="1"><w:numFmt w:val="bullet"/></w:lvl></w:abstractNum>'
            .'<w:abstractNum w:abstractNumId="1"><w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="decimal"/></w:lvl></w:abstractNum>'
            .'<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num><w:num w:numId="2"><w:abstractNumId w:val="1"/></w:num>'
            .'</w:numbering>',
        'word/footnotes.xml' => '<?xml version="1.0"?><w:footnotes '.DOCX_W.'>'
            .'<w:footnote w:type="separator" w:id="-1"><w:p><w:r><w:separator/></w:r></w:p></w:footnote>'
            .'<w:footnote w:id="1"><w:p><w:r><w:t>Archives nationales, 1804.</w:t></w:r></w:p></w:footnote>'
            .'</w:footnotes>',
        'word/_rels/document.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rIdImg" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/>'
            .'<Relationship Id="rIdEmf" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image2.emf"/>'
            .'<Relationship Id="rIdWeb" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="http://127.0.0.1:9/x.png" TargetMode="External"/>'
            .'</Relationships>',
        'word/media/image1.png' => file_get_contents($png->getRealPath()),
        'word/media/image2.emf' => 'not really an emf',
    ];

    foreach (array_merge($defaults, $parts) as $name => $content) {
        $zip->addFromString($name, $content);
    }

    $zip->close();

    return $path;
}

function docxP(string $runs, string $properties = ''): string
{
    return '<w:p>'.($properties !== '' ? '<w:pPr>'.$properties.'</w:pPr>' : '').$runs.'</w:p>';
}

function docxR(string $text, string $properties = ''): string
{
    return '<w:r>'.($properties !== '' ? '<w:rPr>'.$properties.'</w:rPr>' : '').'<w:t xml:space="preserve">'.$text.'</w:t></w:r>';
}

function docxHeading(string $text, int $level = 1): string
{
    return docxP(docxR($text), '<w:pStyle w:val="Heading'.$level.'"/>');
}

function docxItem(string $text, int $numId, int $ilvl = 0): string
{
    return docxP(docxR($text), '<w:numPr><w:ilvl w:val="'.$ilvl.'"/><w:numId w:val="'.$numId.'"/></w:numPr>');
}

function docxDrawing(string $rid, string $alt): string
{
    return '<w:r><w:drawing><wp:inline><wp:docPr id="1" name="Picture 1" descr="'.$alt.'"/><a:graphic><a:graphicData><pic:pic><pic:blipFill><a:blip r:embed="'.$rid.'"/></pic:blipFill></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
}

/** @return array{0: Book, 1: App\Import\ImportReport, 2: array<int, App\Models\ManuscriptSection>} */
function docxImport(string $body, array $parts = []): array
{
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $book->sections()->delete();

    $path = docxFile($body, $parts);
    $report = app(DocxImporter::class)->import($book, $path, $user->id, 'Mon manuscrit');
    @unlink($path);

    return [$book->refresh(), $report, $book->sections()->orderBy('position')->get()->all()];
}

beforeEach(fn () => Storage::fake('files'));

it('splits chapters at the top heading level and keeps the text before the first', function () {
    [$book, $report, $sections] = docxImport(
        docxP(docxR('Avant-propos sans titre.'))
        .docxHeading('Chapitre premier')
        .docxP(docxR('Il était une fois.'))
        .docxHeading('Une section', 2)
        .docxP(docxR('Suite.'))
        .docxHeading('Chapitre deux')
        .docxP(docxR('Fin.'))
    );

    expect(array_map(fn ($s) => $s->title, $sections))->toBe(['Mon manuscrit', 'Chapitre premier', 'Chapitre deux'])
        ->and($report->sections)->toBe(3)
        ->and($report->titles)->toBe(['Mon manuscrit', 'Chapitre premier', 'Chapitre deux'])
        // Headings are labelled on save, so cross-references can point at them.
        ->and($sections[1]->content['content'][1]['type'])->toBe('heading')
        ->and($sections[1]->content['content'][1]['attrs']['level'])->toBe(2)
        ->and($sections[1]->content['content'][1]['attrs']['label'])->toStartWith('sec-')
        ->and($sections[1]->content['content'][1]['content'])->toEqual([['type' => 'text', 'text' => 'Une section']])
        ->and($book->word_count)->toBeGreaterThan(5);
});

it('carries bold, italic, underline, strikethrough and line breaks', function () {
    [, $report, $sections] = docxImport(docxP(
        docxR('Un ').docxR('mot', '<w:b/>').docxR(' en ', '<w:b w:val="0"/>').docxR('italique', '<w:i/><w:u w:val="single"/>')
        .'<w:r><w:br/></w:r>'.docxR('barré', '<w:strike/>')
    ));

    expect($sections[0]->content['content'][0]['content'])->toEqual([
        ['type' => 'text', 'text' => 'Un '],
        ['type' => 'text', 'text' => 'mot', 'marks' => [['type' => 'bold']]],
        ['type' => 'text', 'text' => ' en '],
        ['type' => 'text', 'text' => 'italique', 'marks' => [['type' => 'italic'], ['type' => 'underline']]],
        ['type' => 'hardBreak'],
        ['type' => 'text', 'text' => 'barré', 'marks' => [['type' => 'strike']]],
    ])->and($report->toArray()['lossless'])->toBeTrue();
});

it('nests bullet lists and keeps numbering that resumes after a paragraph', function () {
    [, , $sections] = docxImport(
        docxItem('Fruits', 1).docxItem('Mangues', 1, 1).docxItem('Avocats', 1, 1).docxItem('Légumes', 1)
        .docxItem('Un', 2).docxItem('Deux', 2)
        .docxP(docxR('Une remarque entre deux questions.'))
        .docxItem('Trois', 2)
        .docxP(docxR('Par le style.'), '<w:pStyle w:val="ListBullet"/>')
    );

    $content = $sections[0]->content['content'];

    expect(array_column($content, 'type'))->toBe(['bulletList', 'orderedList', 'paragraph', 'orderedList', 'bulletList'])
        ->and(count($content[0]['content']))->toBe(2)
        ->and($content[0]['content'][0]['content'][1]['type'])->toBe('bulletList')
        ->and(count($content[0]['content'][0]['content'][1]['content']))->toBe(2)
        ->and($content[1])->not->toHaveKey('attrs')
        ->and($content[3]['attrs'])->toBe(['start' => 3]);
});

it('imports tables with merged cells, padded to an even width', function () {
    $cell = fn (string $text, string $properties = '') => '<w:tc>'.($properties ? '<w:tcPr>'.$properties.'</w:tcPr>' : '').docxP(docxR($text)).'</w:tc>';

    [, , $sections] = docxImport('<w:tbl>'
        .'<w:tr><w:trPr><w:tblHeader/></w:trPr>'.$cell('Pays').$cell('PIB', '<w:gridSpan w:val="2"/>').'</w:tr>'
        .'<w:tr>'.$cell('Haïti', '<w:vMerge w:val="restart"/>').$cell('2022').$cell('20').'</w:tr>'
        .'<w:tr>'.$cell('', '<w:vMerge/>').$cell('2023').'</w:tr>'
        .'</w:tbl>');

    $rows = $sections[0]->content['content'][0]['content'];

    expect($rows[0]['content'][0]['type'])->toBe('tableHeader')
        ->and($rows[0]['content'][1]['attrs'])->toBe(['colspan' => 2, 'rowspan' => 1])
        ->and($rows[1]['content'][0]['attrs'])->toBe(['colspan' => 1, 'rowspan' => 2])
        ->and($rows[1]['content'][1]['type'])->toBe('tableCell')
        // The merged cell fills row 3's first column; one cell was missing.
        ->and(count($rows[2]['content']))->toBe(2);
});

it('turns footnote references into footnotes', function () {
    [, , $sections] = docxImport(docxP(docxR('Proclamée en 1804.').'<w:r><w:footnoteReference w:id="1"/></w:r>'));

    expect($sections[0]->content['content'][0]['content'][1])
        ->toBe(['type' => 'footnote', 'attrs' => ['text' => 'Archives nationales, 1804.']]);
});

it('stores pictures in the book and reports the ones it cannot show', function () {
    [$book, $report, $sections] = docxImport(
        docxP(docxR('Avant.').docxDrawing('rIdImg', 'Carte d’Haïti').docxR('Après.'))
        .docxP(docxDrawing('rIdEmf', 'Schéma'))
        .docxP(docxDrawing('rIdWeb', 'Lien externe'))
    );

    $content = $sections[0]->content['content'];

    expect(array_column($content, 'type'))->toBe(['paragraph', 'image', 'paragraph'])
        ->and($content[1]['attrs']['alt'])->toBe('Carte d’Haïti')
        ->and($content[1]['attrs']['src'])->toStartWith("/books/{$book->id}/images/")
        ->and($report->images)->toBe(1)
        ->and($report->skipped)->toBe(['image (emf)' => 1, 'drawing' => 1]);

    Storage::disk('files')->assertExists('images/book-'.$book->id.'/'.basename($content[1]['attrs']['src']));
});

it('reports equations, comments and tracked deletions instead of dropping them silently', function () {
    [, $report, $sections] = docxImport(
        docxP(docxR('Texte ').'<w:ins w:id="1"><w:r><w:t>ajouté</w:t></w:r></w:ins><w:del w:id="2"><w:r><w:delText>retiré</w:delText></w:r></w:del>')
        .docxP('<m:oMath><m:r><m:t>x</m:t></m:r></m:oMath>'),
        ['word/comments.xml' => '<?xml version="1.0"?><w:comments '.DOCX_W.'><w:comment w:id="0"><w:p><w:r><w:t>À revoir</w:t></w:r></w:p></w:comment></w:comments>'],
    );

    expect($sections[0]->plainText())->toContain('Texte ajouté')->not->toContain('retiré')
        ->and($report->skipped)->toBe(['comment' => 1, 'tracked deletion' => 1, 'equation' => 1])
        ->and($report->toArray()['lossless'])->toBeFalse();
});

it('never expands entities from the document', function () {
    // XXE: a DOCTYPE pointing at a local file must not pull it in.
    $secret = tempnam(sys_get_temp_dir(), 'secret');
    file_put_contents($secret, 'TOP-SECRET-CONTENT');

    $document = '<?xml version="1.0"?><!DOCTYPE w:document [<!ENTITY x SYSTEM "file:///'.str_replace('\\', '/', $secret).'">]>'
        .'<w:document '.DOCX_W.'><w:body>'.docxP(docxR('Avant &x; après')).'</w:body></w:document>';

    [, , $sections] = docxImport('', ['word/document.xml' => $document]);

    expect(json_encode(array_map(fn ($s) => $s->content, $sections)))->not->toContain('TOP-SECRET');

    @unlink($secret);
});

it('imports a Word file into a book over HTTP', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $path = docxFile(docxHeading('Un').docxP(docxR('Corps.')).docxP('<m:oMath/>'));

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/import", ['file' => new UploadedFile($path, 'roman.docx', null, null, true)])
        ->assertCreated()
        ->assertJsonPath('report.sections', 1)
        ->assertJsonPath('report.titles', ['Un'])
        ->assertJsonPath('report.skipped.equation', 1)
        ->assertJsonPath('report.lossless', false);

    @unlink($path);
});

it('refuses files that are not what they claim', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $before = $book->sections()->count();

    $fake = tempnam(sys_get_temp_dir(), 'fake');
    file_put_contents($fake, 'just text, not a zip');

    $binary = tempnam(sys_get_temp_dir(), 'bin');
    file_put_contents($binary, "\x89PNG\r\n\x1a\n\0\0\0binary");

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/import", ['file' => new UploadedFile($fake, 'roman.docx', null, null, true)])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'not a Word document']);

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/import", ['file' => new UploadedFile($binary, 'roman.md', null, null, true)])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'not Markdown text']);

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/import", ['file' => UploadedFile::fake()->create('roman.pdf', 10, 'application/pdf')])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/import", ['file' => UploadedFile::fake()->create('roman.docx', 30000)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    expect($book->sections()->count())->toBe($before);

    @unlink($fake);
    @unlink($binary);
});

it('can be switched off', function () {
    config(['publishing.docx_import' => false]);

    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $path = docxFile(docxP(docxR('Corps.')));

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/import", ['file' => new UploadedFile($path, 'roman.docx', null, null, true)])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'turned off']);

    @unlink($path);
});

it('creates a new book from a Markdown or Word file on the shelf', function () {
    $user = User::factory()->create(['plan' => 'studio']);

    $markdown = tempnam(sys_get_temp_dir(), 'md');
    file_put_contents($markdown, "\xEF\xBB\xBF# Premier\n\nTexte.\n\n# Second\n\nEncore.");

    $this->actingAs($user)
        ->postJson('/books/import', ['file' => new UploadedFile($markdown, 'Mon_roman.md', null, null, true)])
        ->assertCreated()
        ->assertJsonPath('book.title', 'Mon roman')
        ->assertJsonPath('report.titles', ['Premier', 'Second']);

    $path = docxFile(docxHeading('Un').docxP(docxR('Corps.')));

    $response = $this->actingAs($user)
        ->postJson('/books/import', ['file' => new UploadedFile($path, 'Essai.docx', null, null, true)])
        ->assertCreated()
        ->assertJsonPath('book.title', 'Essai')
        ->assertJsonPath('report.sections', 1);

    expect(Book::find($response->json('book.id'))->user_id)->toBe($user->id);

    @unlink($markdown);
    @unlink($path);
});

it('leaves no empty book behind when a shelf import fails', function () {
    $user = User::factory()->create(['plan' => 'studio']);
    $before = $user->books()->count();

    $fake = tempnam(sys_get_temp_dir(), 'fake');
    file_put_contents($fake, 'not a zip');

    $this->actingAs($user)
        ->postJson('/books/import', ['file' => new UploadedFile($fake, 'Essai.docx', null, null, true)])
        ->assertStatus(422);

    expect($user->books()->count())->toBe($before);

    @unlink($fake);
});

it('applies the plan limit to a shelf import of a manuscript', function () {
    $free = User::factory()->create(['plan' => 'free']);
    $free->books()->create(['title' => 'The One The Free Plan Allows']);

    $markdown = tempnam(sys_get_temp_dir(), 'md');
    file_put_contents($markdown, '# Un');

    $this->actingAs($free)
        ->postJson('/books/import', ['file' => new UploadedFile($markdown, 'x.md', null, null, true)])
        ->assertForbidden();

    expect($free->books()->count())->toBe(1);

    @unlink($markdown);
});
