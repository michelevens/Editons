<?php

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\Exporter;
use App\Publishing\PrintBook;
use Illuminate\Support\Facades\Storage;

/**
 * A book is published in its own language: the file's language code, the
 * contents heading, and the chapter and part labels. A Kreyòl book once
 * came out as en-US, headed "Contents", with "PART I  PATI I" in the
 * contents and "CHAPTER 3" over a chapter its author titled "Chapit 1".
 */
function kreyolBook(): Book
{
    $book = Book::factory()->for(User::factory())->create(['title' => 'Kreyòl 9e', 'language' => 'Haitian Creole']);

    foreach ([
        ['Tès dyagnostik', 'chapter', 'Premye tès la.'],
        ['Pati I — Dyagnostik', 'part', null],
        ['Chapit 1 : Li ak konprann yon tèks', 'chapter', 'Nou li yon tèks.'],
        ['Chapit 2 : Ekri', 'chapter', 'Nou ekri.'],
    ] as $i => [$title, $type, $text]) {
        ManuscriptSection::factory()->for($book)->create([
            'title' => $title,
            'type' => $type,
            'position' => $i + 1,
            'content' => ['type' => 'doc', 'content' => $text ? [[
                'type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]],
            ]] : []],
        ]);
    }

    return $book->fresh();
}

it('gives every language the book settings offer its own code', function () {
    expect(collect(['English (US)', 'English (UK)', 'Spanish', 'French', 'Haitian Creole'])
        ->mapWithKeys(fn ($l) => [$l => Exporter::languageCode($l)])->all())
        ->toBe(['English (US)' => 'en-US', 'English (UK)' => 'en-GB', 'Spanish' => 'es', 'French' => 'fr', 'Haitian Creole' => 'ht']);
});

it('exports a Kreyòl book as Kreyòl in EPUB and HTML', function () {
    $book = kreyolBook();
    $exporter = app(Exporter::class);

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($exporter->generate($book, 'epub')->path));
    expect($zip->getFromName('OEBPS/content.opf'))->toContain('<dc:language>ht</dc:language>')
        ->and($zip->getFromName('OEBPS/ch1.xhtml'))->toContain('xml:lang="ht"')
        ->and($zip->getFromName('OEBPS/nav.xhtml'))->toContain('<h1>Tab matyè</h1>')->not->toContain('Contents');
    $zip->close();

    $html = Storage::disk('local')->get($exporter->generate($book, 'html')->path);
    expect($html)->toContain('<html lang="ht">')
        ->toContain('<h1>Tab matyè</h1>')
        ->not->toContain('Contents');
});

it('labels Kreyòl chapters and parts once, in Kreyòl, in print', function () {
    $book = kreyolBook();
    $html = app(PrintBook::class)->html($book, $book->sections()->orderBy('position')->get(), 'ht');

    expect($html)->toContain('<html lang="ht">')
        ->toContain('Tab matyè')
        ->toContain('<p class="part-label">Pati I</p>')
        ->toContain('<h1 class="part-title">Dyagnostik</h1>')
        // The author's number, not a count that includes the diagnostic test.
        ->toContain('<p class="opener-label">Chapit 1</p>')
        ->toContain('<h1 class="opener-title">Li ak konprann yon tèks</h1>')
        ->toContain('Konpoze an EB Garamond.')
        ->not->toContain('Contents')
        ->not->toContain('Chapter')
        ->not->toContain('Part I');
});

it('does not repeat a label the title already states', function () {
    $book = Book::factory()->for(User::factory())->create(['language' => 'French']);
    ManuscriptSection::factory()->for($book)->create([
        'title' => 'Chapitre 3', 'type' => 'chapter', 'position' => 1,
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Texte.']]]]],
    ]);

    $html = app(PrintBook::class)->html($book, $book->sections()->get(), 'fr');

    expect($html)->toContain('<h1 class="opener-title">Chapitre 3</h1>')
        ->not->toContain('<p class="opener-label">');
});
