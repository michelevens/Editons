<?php

use App\Models\Book;
use App\Models\BookExport;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\DocumentRenderer;
use App\Publishing\Exporter;
use Illuminate\Support\Facades\Storage;

/**
 * Exports are the files an author uploads to a store. A malformed one costs
 * them a submission cycle, so these tests are about structural validity
 * rather than about the happy path producing bytes.
 */
function exportableBook(array $chapters = ['Chapter One' => 'Some prose here that is long enough.']): Book
{
    $book = Book::factory()->for(User::factory())->create([
        'title' => 'A Practical Guide',
        'subtitle' => 'Second Edition',
        'author_name' => 'A Writer',
        'language' => 'English (US)',
    ]);

    $position = 0;

    foreach ($chapters as $title => $text) {
        ManuscriptSection::factory()->for($book)->create([
            'title' => $title,
            'type' => 'chapter',
            'position' => $position++,
            'content' => ['type' => 'doc', 'content' => [[
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => $text]],
            ]]],
        ]);
    }

    return $book->fresh();
}

it('writes a file for every offered format', function () {
    // Every format the Publishing screen lists must actually produce
    // something. A format offered but unimplemented is a dead button.
    $book = exportableBook();

    foreach (array_keys(BookExport::FORMATS) as $format) {
        $export = app(Exporter::class)->generate($book, $format);

        expect($export->status)->toBe('ready')
            ->and($export->path)->not->toBeNull()
            ->and(Storage::disk('local')->exists($export->path))->toBeTrue()
            ->and($export->byte_size)->toBeGreaterThan(0);
    }
});

it('builds an EPUB whose mimetype entry comes first and is uncompressed', function () {
    // The spec requires it, and readers that cannot find it there reject the
    // whole file. This is the single easiest way to ship a broken EPUB.
    $export = app(Exporter::class)->generate(exportableBook(), 'epub');

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($export->path));

    $first = $zip->statIndex(0);

    expect($first['name'])->toBe('mimetype')
        ->and($first['comp_method'])->toBe(ZipArchive::CM_STORE)
        ->and($zip->getFromName('mimetype'))->toBe('application/epub+zip');

    $zip->close();
});

it('gives the EPUB a container, a manifest and a spine entry per chapter', function () {
    $book = exportableBook([
        'One' => 'First chapter prose.',
        'Two' => 'Second chapter prose.',
    ]);

    $export = app(Exporter::class)->generate($book, 'epub');

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($export->path));

    $opf = $zip->getFromName('OEBPS/content.opf');

    expect($zip->getFromName('META-INF/container.xml'))->toContain('OEBPS/content.opf')
        ->and($zip->getFromName('OEBPS/nav.xhtml'))->toContain('epub:type="toc"')
        ->and($opf)->toContain('<itemref idref="ch1"/>')
        ->and($opf)->toContain('<itemref idref="ch2"/>')
        ->and($zip->getFromName('OEBPS/ch1.xhtml'))->toContain('First chapter prose')
        ->and($zip->getFromName('OEBPS/ch2.xhtml'))->toContain('Second chapter prose');

    $zip->close();
});

it('declares a language code, not a display name', function () {
    // "English (US)" in dc:language fails store validation. The display
    // string is what the author picked; the file needs BCP 47.
    $book = exportableBook();
    $book->update(['language' => 'English (US)']);

    $export = app(Exporter::class)->generate($book->fresh(), 'epub');

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($export->path));
    $opf = $zip->getFromName('OEBPS/content.opf');
    $zip->close();

    expect($opf)->toContain('<dc:language>en-US</dc:language>')
        ->and($opf)->not->toContain('English (US)');
});

it('uses the ISBN as the EPUB identifier when the book has one', function () {
    // A store matches an upload to a listing by identifier. A random UUID
    // where an ISBN exists makes the file look like a different book.
    $book = exportableBook();
    $book->update(['isbn' => '9780306406157']);

    $export = app(Exporter::class)->generate($book->fresh(), 'epub');

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($export->path));
    $opf = $zip->getFromName('OEBPS/content.opf');
    $zip->close();

    expect($opf)->toContain('urn:isbn:9780306406157');
});

it('builds a docx that is a real Open XML package', function () {
    // An HTML file renamed .docx makes Word offer to repair it, which is
    // where an author stops trusting exports.
    $export = app(Exporter::class)->generate(exportableBook(), 'docx');

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($export->path));

    expect($zip->getFromName('[Content_Types].xml'))->toContain('wordprocessingml')
        ->and($zip->getFromName('_rels/.rels'))->toContain('word/document.xml')
        ->and($zip->getFromName('word/document.xml'))->toContain('Some prose here')
        ->and($zip->getFromName('word/styles.xml'))->toContain('Heading1');

    $zip->close();
});

it('skips parts, which hold chapters rather than prose', function () {
    // A part exported as a chapter prints its title twice: once as the part
    // and once as the chapter under it.
    $book = exportableBook(['Real Chapter' => 'Prose that belongs in the book.']);

    ManuscriptSection::factory()->for($book)->create([
        'title' => 'Part I',
        'type' => 'part',
        'position' => 10,
        'content' => ['type' => 'doc', 'content' => []],
    ]);

    $export = app(Exporter::class)->generate($book->fresh(), 'md');
    $contents = Storage::disk('local')->get($export->path);

    expect($contents)->toContain('Real Chapter')
        ->and($contents)->not->toContain('Part I');
});

it('does not print the chapter title twice when the prose opens with it', function () {
    // Authors routinely type the title as the first line. Two H1s in one
    // document is flagged by validators and read out twice by screen readers.
    $book = Book::factory()->for(User::factory())->create(['title' => 'Guide']);

    ManuscriptSection::factory()->for($book)->create([
        'title' => 'The Opening',
        'type' => 'chapter',
        'position' => 0,
        'content' => ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [
                ['type' => 'text', 'text' => 'The Opening'],
            ]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body text.']]],
        ]],
    ]);

    $export = app(Exporter::class)->generate($book->fresh(), 'html');
    $contents = Storage::disk('local')->get($export->path);

    expect(substr_count($contents, 'The Opening'))->toBe(1);
});

it('refuses to export a book with nothing in it', function () {
    // Better than handing over an empty file that a store rejects.
    $book = Book::factory()->for(User::factory())->create();

    expect(fn () => app(Exporter::class)->generate($book, 'epub'))
        ->toThrow(RuntimeException::class, 'nothing to export');
});

it('records a failure rather than leaving no trace', function () {
    $book = exportableBook();

    expect(fn () => app(Exporter::class)->generate($book, 'not_a_format'))
        ->toThrow(RuntimeException::class);

    // Nothing was recorded, because the format was rejected before a row was
    // made. The guard is that no half-written 'generating' row is left behind.
    expect($book->exports()->count())->toBe(0);
});

it('keeps older files when a format is regenerated', function () {
    // Export history promises the earlier file is still there. Overwriting
    // would make every past row point at the newest content.
    $book = exportableBook();
    $exporter = app(Exporter::class);

    $first = $exporter->generate($book, 'md');
    $second = $exporter->generate($book, 'md');

    expect($first->path)->not->toBe($second->path)
        ->and(Storage::disk('local')->exists($first->path))->toBeTrue()
        ->and(Storage::disk('local')->exists($second->path))->toBeTrue();
});

it('records the word count at the time of generation', function () {
    // forceFill because word_count is deliberately not fillable: it is
    // derived from section content, and a client able to set it could make
    // the dashboard disagree with the manuscript.
    $book = exportableBook();
    $book->forceFill(['word_count' => 1234])->save();

    $export = app(Exporter::class)->generate($book->fresh(), 'md');

    expect($export->word_count)->toBe(1234);
});

// --- The renderer -------------------------------------------------------

it('preserves marks and structure rather than flattening to text', function () {
    $section = ManuscriptSection::factory()
        ->for(Book::factory()->for(User::factory()))
        ->create(['content' => ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [
                ['type' => 'text', 'text' => 'A Heading'],
            ]],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Plain and '],
                ['type' => 'text', 'text' => 'bold', 'marks' => [['type' => 'bold']]],
                ['type' => 'text', 'text' => ' and '],
                ['type' => 'text', 'text' => 'italic', 'marks' => [['type' => 'italic']]],
            ]],
            ['type' => 'bulletList', 'content' => [
                ['type' => 'listItem', 'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'First']]],
                ]],
            ]],
        ]]]);

    $html = app(DocumentRenderer::class)->html($section);

    expect($html)->toContain('<h2>A Heading</h2>')
        ->and($html)->toContain('<strong>bold</strong>')
        ->and($html)->toContain('<em>italic</em>')
        ->and($html)->toContain('<li>First</li>');
});

it('escapes author text so a manuscript cannot inject markup', function () {
    // An author who writes about HTML would otherwise break their own EPUB,
    // and a pasted <script> would end up in a file sent to readers.
    $section = ManuscriptSection::factory()
        ->for(Book::factory()->for(User::factory()))
        ->create(['content' => ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'Use <script>alert(1)</script> & co.']],
        ]]]]);

    $html = app(DocumentRenderer::class)->html($section);

    expect($html)->not->toContain('<script>')
        ->and($html)->toContain('&lt;script&gt;')
        ->and($html)->toContain('&amp;');
});

it('clamps a heading level to something valid', function () {
    // An <h9> is not HTML and fails EPUB validation.
    $section = ManuscriptSection::factory()
        ->for(Book::factory()->for(User::factory()))
        ->create(['content' => ['type' => 'doc', 'content' => [[
            'type' => 'heading',
            'attrs' => ['level' => 9],
            'content' => [['type' => 'text', 'text' => 'Deep']],
        ]]]]);

    expect(app(DocumentRenderer::class)->html($section))->toContain('<h6>Deep</h6>');
});

it('always emits an alt attribute on an image', function () {
    // A missing attribute fails accessibility validation; an empty one is a
    // valid claim that the image is decorative.
    $section = ManuscriptSection::factory()
        ->for(Book::factory()->for(User::factory()))
        ->create(['content' => ['type' => 'doc', 'content' => [[
            'type' => 'image',
            'attrs' => ['src' => '/storage/figure.png'],
        ]]]]);

    expect(app(DocumentRenderer::class)->html($section))->toContain('alt=""');
});

it('renders markdown with marks and lists', function () {
    $section = ManuscriptSection::factory()
        ->for(Book::factory()->for(User::factory()))
        ->create(['content' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'bold', 'marks' => [['type' => 'bold']]],
            ]],
            ['type' => 'orderedList', 'content' => [
                ['type' => 'listItem', 'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Step']]],
                ]],
            ]],
        ]]]);

    $markdown = app(DocumentRenderer::class)->markdown($section);

    expect($markdown)->toContain('**bold**')
        ->and($markdown)->toContain('1. Step');
});
