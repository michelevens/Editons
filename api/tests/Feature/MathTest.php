<?php

use App\Import\ImportReport;
use App\Import\MarkdownImporter;
use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\DocumentRenderer;
use App\Publishing\Exporter;
use App\Publishing\MathRenderer;
use App\Publishing\PrintBook;
use Illuminate\Support\Facades\Storage;

/**
 * Formulas, from import to every export.
 *
 * The canonical document stores LaTeX and nothing else; each format derives
 * what it needs. These tests run the real typesetter (Node + MathJax), so a
 * broken install fails here rather than in an author's thesis.
 */
function mathDoc(string $markdown): array
{
    $importer = app(MarkdownImporter::class);

    return (new ReflectionMethod($importer, 'toProseMirror'))->invoke($importer, $markdown, new ImportReport);
}

function mathSection(string $markdown): ManuscriptSection
{
    $book = Book::factory()->for(User::factory())->create(['title' => 'Equations']);

    return ManuscriptSection::factory()->for($book)->create([
        'title' => 'Chapter 3: Method',
        'type' => 'chapter',
        'content' => mathDoc($markdown),
    ]);
}

const EQUATIONS = <<<'MD'
The mean $\bar{x}$ of $n$ values costs $25 and $30 to compute.

$$
\bar{x} = \frac{1}{n}\sum_{i=1}^{n} x_i \tag{3.1}
$$

Water is $\ce{H2O}$.
MD;

it('imports inline and display math, and leaves prices alone', function () {
    $content = mathDoc(EQUATIONS)['content'];

    expect(array_column($content, 'type'))->toBe(['paragraph', 'blockMath', 'paragraph']);

    $first = $content[0]['content'];

    expect($first[1])->toBe(['type' => 'inlineMath', 'attrs' => ['latex' => '\bar{x}']])
        ->and($first[3])->toBe(['type' => 'inlineMath', 'attrs' => ['latex' => 'n']])
        ->and($first[4]['text'])->toBe(' values costs $25 and $30 to compute.')
        ->and($content[1]['attrs']['latex'])->toBe('\bar{x} = \frac{1}{n}\sum_{i=1}^{n} x_i \tag{3.1}');
});

it('typesets LaTeX to SVG and MathML, chemistry included', function () {
    [$mean, $water, $broken] = app(MathRenderer::class)->render([
        ['latex' => '\bar{x} = \frac{1}{n}\sum x_i', 'display' => true],
        ['latex' => '\ce{2H2 + O2 -> 2H2O}', 'display' => false],
        ['latex' => '\frac{1}{', 'display' => false],
    ]);

    expect($mean['svg'])->toStartWith('<svg')
        ->and($mean['mathml'])->toContain('<mfrac>')
        ->and($water['svg'])->toStartWith('<svg')
        ->and($broken['error'])->toContain('brace');
});

it('renders MathML for readers and numbers display equations beside them', function () {
    $html = app(DocumentRenderer::class)->html(mathSection(EQUATIONS));

    expect($html)->toContain('<math xmlns="http://www.w3.org/1998/Math/MathML">')
        ->and($html)->toContain('<div class="math-display">')
        ->and($html)->toContain('<span class="math-number">(3.1)</span>')
        // The tag is set beside the equation, not inside it.
        ->and($html)->not->toContain('tag');
});

it('renders SVG for print', function () {
    $html = app(DocumentRenderer::class)->withMath('svg')->html(mathSection(EQUATIONS));

    expect($html)->toContain('<img class="math" alt="\bar{x}"')
        ->and($html)->toContain('data:image/svg+xml;base64,');
});

it('shows a broken equation as its source rather than dropping it', function () {
    $html = app(DocumentRenderer::class)->html(mathSection('Broken: $\frac{1}{$ here.'));

    expect($html)->toContain('<code class="math-tex" title="Missing close brace">$\frac{1}{$</code>');
});

it('round-trips math through Markdown', function () {
    $renderer = app(DocumentRenderer::class);
    $first = mathDoc(EQUATIONS);
    $again = mathDoc($renderer->markdown(mathSection(EQUATIONS)));

    expect($again)->toBe($first);
});

it('puts Word equations in the DOCX and typesets equations in the PDF', function () {
    $section = mathSection(EQUATIONS);
    $book = $section->book;

    $export = app(Exporter::class)->generate($book, 'docx');
    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($export->path));
    $document = $zip->getFromName('word/document.xml');
    $zip->close();

    expect($document)->toContain('<m:oMathPara><m:oMath>')
        ->and($document)->toContain('xmlns:m=')
        ->and(simplexml_load_string($document))->not->toBeFalse();

    $pdf = app(PrintBook::class)->pdf($book, $book->sections()->get(), 'en');

    expect($pdf)->toStartWith('%PDF');
});
