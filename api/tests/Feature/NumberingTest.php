<?php

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\DocumentRenderer;
use App\Publishing\Exporter;
use App\Publishing\Numbering;
use App\Publishing\PrintBook;
use App\Services\ManuscriptWriter;
use Illuminate\Support\Facades\Storage;

/**
 * Captions and equations number from position; references follow the
 * thing they point at, not the number typed when they were written.
 */
function nbCaption(string $kind, string $number, string $text, ?string $label = null): array
{
    return array_filter([
        'type' => 'paragraph',
        'attrs' => $label ? ['label' => $label] : null,
        'content' => [
            ['type' => 'text', 'text' => "{$kind} {$number}", 'marks' => [['type' => 'bold']]],
            ['type' => 'text', 'text' => " {$text}"],
        ],
    ]);
}

function nbProse(array ...$inline): array
{
    return ['type' => 'paragraph', 'content' => $inline];
}

function nbXref(string $target): array
{
    return ['type' => 'xref', 'attrs' => ['target' => $target, 'text' => 'stale']];
}

function numberedBook(): Book
{
    $book = Book::factory()->for(User::factory())->create(['title' => 'Numbered']);
    $chapter = fn (int $position, string $title, array $content) => ManuscriptSection::factory()->for($book)->create([
        'title' => $title, 'type' => 'chapter', 'position' => $position, 'content' => ['type' => 'doc', 'content' => $content],
    ]);

    $chapter(10, 'Background', [
        nbProse(['type' => 'text', 'text' => 'Results are in '], nbXref('tbl-results'), ['type' => 'text', 'text' => ', and the model is '], nbXref('eq-model'), ['type' => 'text', 'text' => '.']),
    ]);
    $chapter(20, 'Method', [
        nbCaption('Table', '9.9', 'A table typed with the wrong number.'),
        ['type' => 'blockMath', 'attrs' => ['label' => 'eq-model', 'latex' => 'y = mx + b \\tag{7.7}']],
        // An author's own tag is left alone.
        ['type' => 'blockMath', 'attrs' => ['latex' => 'z = 1 \\tag{*}']],
        nbCaption('Table', '1.1', 'The results.', 'tbl-results'),
        nbCaption('Figure', '3.4', 'A figure.'),
    ]);
    $book->sections()->create(['title' => 'Appendix A: Extra', 'type' => 'back_matter', 'position' => 30,
        'content' => ['type' => 'doc', 'content' => [nbCaption('Table', '2.1', 'Appendix table.')]]]);

    return $book->fresh();
}

it('renumbers captions and owned equation tags by chapter and appendix', function () {
    $book = numberedBook();
    $numbering = Numbering::forBook($book->sections()->orderBy('position')->get());

    expect($numbering['scheme'])->toBe('chapter')
        ->and($numbering['targets']['tbl-results']['text'])->toBe('Table 2.2')
        ->and($numbering['targets']['eq-model']['text'])->toBe('Equation (2.1)');

    $method = $book->sections()->where('title', 'Method')->first();
    $html = app(DocumentRenderer::class)->withNumbering($numbering)->html($method);

    expect($html)->toContain('<strong>Table 2.1</strong>')
        ->and($html)->toContain('<strong>Table 2.2</strong>')
        ->and($html)->toContain('<strong>Figure 2.1</strong>')
        ->and($html)->toContain('(2.1)')
        ->and($html)->toContain('(*)')
        ->and($html)->toContain('<p class="caption" id="tbl-results">');

    $appendix = app(DocumentRenderer::class)->withNumbering($numbering)
        ->html($book->sections()->where('title', 'like', 'Appendix%')->first());

    expect($appendix)->toContain('<strong>Table A.1</strong>');
});

it('numbers continuously when the author does', function () {
    $book = Book::factory()->for(User::factory())->create();

    foreach ([10, 20] as $position) {
        ManuscriptSection::factory()->for($book)->create(['type' => 'chapter', 'position' => $position,
            'content' => ['type' => 'doc', 'content' => [nbCaption('Table', '1', 'A.'), nbCaption('Table', '1', 'B.', "tbl-p{$position}")]]]);
    }

    $numbering = Numbering::forBook($book->sections()->orderBy('position')->get());

    expect($numbering['scheme'])->toBe('continuous')
        ->and($numbering['targets']['tbl-p10']['text'])->toBe('Table 2')
        ->and($numbering['targets']['tbl-p20']['text'])->toBe('Table 4');
});

it('resolves cross-references in every export, and flags a missing one', function () {
    $book = numberedBook();
    $intro = $book->sections()->where('title', 'Background')->first();
    $intro->update(['content' => ['type' => 'doc', 'content' => [
        ...$intro->content['content'],
        nbProse(nbXref('tbl-gone')),
    ]]]);

    $pdf = app(PrintBook::class)->html($book, $book->sections()->orderBy('position')->get(), 'en');
    expect($pdf)->toContain('<a class="xref" href="#tbl-results">Table 2.2</a>')
        // Print hyphenates link text like any other, so match the link itself.
        ->and($pdf)->toContain('<a class="xref" href="#eq-model">')
        ->and($pdf)->toContain('<table class="math-display" id="eq-model">')
        ->and($pdf)->toContain('<span class="xref-missing">');

    $exporter = app(Exporter::class);
    $zip = new ZipArchive;

    $epub = $exporter->generate($book, 'epub');
    $zip->open(Storage::disk('local')->path($epub->path));
    expect($zip->getFromName('OEBPS/ch1.xhtml'))->toContain('href="ch2.xhtml#tbl-results">Table 2.2</a>');
    $zip->close();

    $docx = $exporter->generate($book, 'docx');
    $zip->open(Storage::disk('local')->path($docx->path));
    expect($zip->getFromName('word/document.xml'))->toContain('Table 2.2')->toContain('Equation (2.1)');
    $zip->close();
});

it('labels targets when they are saved and lists them for the editor', function () {
    $book = numberedBook();
    $intro = $book->sections()->where('title', 'Background')->first();

    // As the author's editor would save them.
    foreach ($book->sections()->get() as $section) {
        app(ManuscriptWriter::class)->save($section, $section->content);
    }

    $response = $this->actingAs($book->user)
        ->getJson("/books/{$book->id}/cross-references?section={$intro->id}")
        ->assertOk();

    $targets = collect($response->json('targets'));

    expect($response->json('section.prefix'))->toBe('1.')
        ->and($targets->pluck('text'))->toContain('Table 2.1', 'Table 2.2', 'Figure 2.1', 'Equation (2.1)', 'Chapter 2', 'Table A.1');

    // The table written without a label got one when it was saved,
    // quietly, without a new version.
    $method = $book->sections()->where('title', 'Method')->first();
    expect($method->content['content'][0]['attrs']['label'])->toStartWith('tbl-')
        ->and($method->versions()->count())->toBe(0);
});

it('numbers captions written in French, Spanish and Kreyòl', function () {
    $nodes = Numbering::apply([
        nbCaption('Tableau', '9.9', 'Fréquence des thèmes'),
        nbCaption('Figure', '?', 'Évolution'),
        nbCaption('Tablo', '1', 'Rezilta'),
        nbCaption('Figura', '7', 'Gráfico'),
    ], '3.');

    expect(collect($nodes)->map(fn ($n) => $n['content'][0]['text'])->all())
        ->toBe(['Tableau 3.1', 'Figure 3.1', 'Tablo 3.2', 'Figura 3.2'])
        ->and(Numbering::captionKind($nodes[0]))->toBe(['Tableau', '3.1', 'table'])
        ->and(Numbering::captionKind($nodes[3]))->toBe(['Figura', '3.2', 'figure']);
});

it('keeps the prefix an author gave captions in an unnumbered section', function () {
    $nodes = Numbering::apply([
        nbCaption('Tableau', '0.1', 'Fréquences'),
        nbCaption('Tableau', '0.7', 'Plan sur 8 semaines'),
        nbCaption('Table', 'D.4', 'Grille'),
        nbCaption('Figure', '3', 'Sans préfixe'),
        nbCaption('Tableau', '70.5.4', 'Barème'),
    ], '');

    expect(collect($nodes)->map(fn ($n) => $n['content'][0]['text'])->all())
        ->toBe(['Tableau 0.1', 'Tableau 0.2', 'Table D.1', 'Figure 1', 'Tableau 70.5.1']);
});
