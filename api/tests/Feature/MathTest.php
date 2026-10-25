<?php

use App\Import\ImportReport;
use App\Import\MarkdownImporter;
use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\DocumentRenderer;
use App\Publishing\Exporter;
use App\Publishing\MathMLToOmml;
use App\Publishing\MathRenderer;
use App\Publishing\Numbering;
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

    // Each formula carries its LaTeX as the text alternative.
    expect($html)->toContain('<math alttext="n" xmlns="http://www.w3.org/1998/Math/MathML">')
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

    expect($document)->toContain('<m:oMath><m:bar>')
        ->and($document)->toContain('xmlns:m=')
        ->and(simplexml_load_string($document))->not->toBeFalse();

    $pdf = app(PrintBook::class)->pdf($book, $book->sections()->get(), 'en');

    expect($pdf)->toStartWith('%PDF');
});

it('typesets a formula inside a bold label', function () {
    $content = mathDoc('- **$V$, the nodes.** Each customer is a node.')['content'][0]['content'][0]['content'][0]['content'];

    expect($content[0])->toBe(['type' => 'inlineMath', 'attrs' => ['latex' => 'V']])
        ->and($content[1])->toBe(['type' => 'text', 'text' => ', the nodes.', 'marks' => [['type' => 'bold']]])
        ->and($content[2]['text'])->toBe(' Each customer is a node.');
});

it('writes native Word equations, not LaTeX', function () {
    $section = mathSection(implode("\n\n", [
        'The mean $\bar{x}$ and $\sqrt{\alpha^2}$ with $\Pr(Y_i = 1 \mid \mathbf{x}_i)$.',
        '$$\n\bar{x} = \frac{1}{n}\sum_{i=1}^{n} x_i \tag{3.1}\n$$',
        '$$\nA = \begin{pmatrix} a & b \\ c & d \end{pmatrix}\n$$',
        '$$\n\lim_{x \to \infty} \int_0^1 f(x)\,dx\n$$',
    ]));

    $xml = app(DocumentRenderer::class)->docx($section);

    expect($xml)->toContain('<m:f>')->toContain('<m:nary>')->toContain('<m:chr m:val="∑"/>')
        ->toContain('<m:rad>')->toContain('<m:bar>')->toContain('<m:sSub>')
        ->toContain('<m:d><m:dPr><m:begChr m:val="("/>')->toContain('<m:m>')
        ->toContain('<m:limLow>')->toContain('<m:chr m:val="∫"/>')
        // The summand is the sum's body, not left trailing after it.
        ->toMatch('/<m:chr m:val="∑"\/>.*<m:e><m:sSub><m:e><m:r><m:t xml:space="preserve">x<\/m:t>/s')
        // Function names upright, bold symbols bold.
        ->toContain('<m:sty m:val="p"/></m:rPr><m:t xml:space="preserve">Pr</m:t>')
        ->toContain('<m:sty m:val="b"/></m:rPr><m:t xml:space="preserve">x</m:t>')
        // The number beside the equation, not inside it.
        ->toContain('<w:tab/><w:t xml:space="preserve">(3.1)</w:t>')
        ->not->toContain('\frac');

    $wrapped = '<w:body xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math">'.$xml.'</w:body>';
    expect(simplexml_load_string($wrapped))->not->toBeFalse();
});

it('keeps matrix rows and the cases brace in Word', function () {
    // Nowdoc: the row breaks must arrive as \\, exactly as an author types.
    [$matrix, $cases] = explode("\n", <<<'TEX'
A = \begin{pmatrix} a & b \\ c & d \end{pmatrix}
f(x) = \begin{cases} 1 & x > 0 \\ 0 & \text{otherwise} \end{cases}
TEX);

    $omml = fn (string $latex) => app(MathMLToOmml::class)
        ->convert(app(MathRenderer::class)->one($latex, true)['mathml']);

    expect(substr_count($omml($matrix), '<m:mr>'))->toBe(2)
        ->and($omml($matrix))->toContain('<m:begChr m:val="("/><m:endChr m:val=")"/>')
        ->and(substr_count($omml($cases), '<m:mr>'))->toBe(2)
        ->and($omml($cases))->toContain('<m:begChr m:val="{"/><m:endChr m:val=""/>');
});

it('numbers equations by chapter when the author does, even without captions', function () {
    $book = Book::factory()->for(User::factory())->create();
    ManuscriptSection::factory()->for($book)->create(['title' => 'Chapter 3: Method', 'type' => 'chapter', 'position' => 10,
        'content' => ['type' => 'doc', 'content' => [['type' => 'blockMath', 'attrs' => ['latex' => 'a = b \tag{3.1}']]]]]);

    $numbering = Numbering::forBook($book->sections()->get());

    expect($numbering['scheme'])->toBe('chapter')
        ->and(Numbering::apply($book->sections()->first()->content['content'], '3.')[0]['attrs']['latex'])
        ->toBe('a = b \tag{3.1}');
});

it('sets display maths written mid-line in display style, and keeps whole boxes', function () {
    $doc = mathDoc(implode("\n", [
        'On cherche q et r tels que : $$a = bq + r$$ avec 0 ≤ r < b.',
        '',
        '1. Calcule $$\frac{1}{2} + \frac{1}{3}$$ (25 pts)',
        '',
        '> **À retenir.** Le produit est nul si :',
        '>',
        '> $$A \times B = 0$$',
        '>',
        '> - si $A = 0$ ;',
        '> - ou si $B = 0$.',
    ]));

    $json = json_encode($doc);
    $nodes = $doc['content'];

    expect($json)->not->toContain('$$')
        ->and($json)->toContain('\\displaystyle a = bq + r')
        ->and($nodes[0]['type'])->toBe('paragraph')
        ->and($nodes[1]['type'])->toBe('orderedList')
        ->and($nodes[2]['type'])->toBe('blockquote')
        ->and(array_column($nodes[2]['content'], 'type'))->toBe(['paragraph', 'blockMath', 'bulletList']);
});

it('keeps the line breaks a backslash asks for', function () {
    $doc = mathDoc("Lapli tonbe sou lakou a,\\\nVan an chante nan pye mango,\\\nTi zwazo yo kache.\n\nNouvo paragraf\nsou de liy.");

    expect(array_column($doc['content'][0]['content'], 'type'))->toBe(['text', 'hardBreak', 'text', 'hardBreak', 'text'])
        ->and($doc['content'][0]['content'][0]['text'])->toBe('Lapli tonbe sou lakou a,')
        ->and($doc['content'][1]['content'])->toBe([['type' => 'text', 'text' => 'Nouvo paragraf sou de liy.']]);
});
