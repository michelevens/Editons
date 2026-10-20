<?php

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\Exporter;
use App\Publishing\PrintBook;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

/**
 * Lists of tables and figures, generated from captions: a thesis requirement
 * nobody should have to keep in step by hand.
 */
function listCaption(string $kind, string $number, string $text): array
{
    return ['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => "{$kind} {$number}", 'marks' => [['type' => 'bold']]],
        ['type' => 'text', 'text' => " {$text}"],
    ]];
}

function listedBook(): Book
{
    $book = Book::factory()->for(User::factory())->create(['title' => 'Listed']);
    $add = fn (int $position, string $type, string $title, array $content) => ManuscriptSection::factory()->for($book)->create([
        'title' => $title, 'type' => $type, 'position' => $position, 'content' => ['type' => 'doc', 'content' => $content],
    ]);

    $add(10, 'front_matter', 'List of Tables', []);
    $add(20, 'front_matter', 'List of Figures', []);
    $add(30, 'chapter', 'Methods', [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => str_repeat('Long methods prose. ', 400)]]],
        listCaption('Table', '1.1', 'Participant characteristics.'),
        listCaption('Figure', '1.1', 'The study design.'),
    ]);
    $add(40, 'chapter', 'Results', [
        listCaption('Table', '9.9', 'Descriptive statistics.'),
    ]);

    return $book->fresh();
}

it('typesets the lists with each caption\'s page', function () {
    $book = listedBook();
    $exporter = app(Exporter::class);
    $sections = (new ReflectionMethod($exporter, 'structure'))->invoke($exporter, $book);

    // Empty in the binder, still in the book.
    expect($sections->pluck('title'))->toContain('List of Tables', 'List of Figures');

    $result = app(PrintBook::class)->typeset($book, $sections, 'en');
    $pdf = new Parser;

    $text = collect($pdf->parseContent($result['pdf'])->getPages())->map->getText()->implode("\f");

    expect($text)->toContain('Participant characteristics.')
        ->and($text)->toContain('Descriptive statistics.')
        // Renumbered from position, in the list as in the text.
        ->and($text)->toContain('Table 2.1')
        ->and($text)->not->toContain('Table 9.9');
})->skip(! class_exists(Parser::class), 'no PDF text parser installed');

it('records the page each caption lands on', function () {
    $book = listedBook();
    $exporter = app(Exporter::class);
    $sections = (new ReflectionMethod($exporter, 'structure'))->invoke($exporter, $book);
    $html = app(PrintBook::class)->html($book, $sections, 'en');

    expect($html)->toContain('data-block="cap-')
        ->and($html)->toContain('<td class="toc-cap">Table 1.1</td><td class="toc-title">Participant characteristics.</td>')
        ->and($html)->toContain('<td class="toc-cap">Table 2.1</td><td class="toc-title">Descriptive statistics.</td>')
        ->and($html)->toContain('<td class="toc-cap">Figure 1.1</td>');

    // After pagination every caption has a page, and later ones are no earlier.
    $typeset = app(PrintBook::class)->typeset($book, $sections, 'en');

    expect($typeset['pages'])->toBeGreaterThan(4);
});

it('lists captions in HTML, Word and Markdown', function () {
    $book = listedBook();
    $exporter = app(Exporter::class);

    $html = Storage::disk('local')->get($exporter->generate($book, 'html')->path);
    expect($html)->toContain('<table class="caption-list"><tr><td class="cl-number">Table 1.1</td><td class="cl-text">Participant characteristics.</td>');

    $markdown = Storage::disk('local')->get($exporter->generate($book, 'md')->path);
    expect($markdown)->toContain('- **Figure 1.1** The study design.');

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($exporter->generate($book, 'docx')->path));
    // In the list, before the chapter that holds the table.
    $document = $zip->getFromName('word/document.xml');
    expect(strpos($document, 'Descriptive statistics.'))->toBeLessThan(strpos($document, 'Long methods prose.'));
    $zip->close();
});

it('gives doctoral templates the lists', function () {
    expect(config('booktypes.phd_dissertation.front_matter'))->toContain('List of Tables', 'List of Figures')
        ->and(config('booktypes.phd_publication.front_matter'))->toContain('List of Tables', 'List of Figures');
});
