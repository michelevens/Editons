<?php

use App\Import\ImportReport;
use App\Import\MarkdownImporter;
use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\ChartRenderer;
use App\Publishing\DocumentRenderer;
use App\Publishing\Exporter;
use App\Publishing\PrintBook;
use Illuminate\Support\Facades\Storage;

/**
 * Charts drawn from their data, in the editor and in every export.
 */
const ACCURACY = "Model,Traditional,Hybrid\nNaive Bayes,73.5,73.1\nLogistic regression,80.2,79.4\nDecision tree,78.6,79.0";

function chartNode(array $attrs = []): array
{
    return ['type' => 'chart', 'attrs' => array_merge(['type' => 'bar', 'title' => 'Accuracy', 'xLabel' => '', 'yLabel' => 'Accuracy (%)', 'data' => ACCURACY], $attrs)];
}

function chartBook(array $node): Book
{
    $book = Book::factory()->for(User::factory())->create(['title' => 'Charts']);

    ManuscriptSection::factory()->for($book)->create([
        'title' => 'Chapter 7: Results',
        'type' => 'chapter',
        'content' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'The models compared.']]],
            $node,
        ]],
    ]);

    return $book->fresh();
}

it('reads tables the way they are pasted: commas, tabs or semicolons', function () {
    $charts = app(ChartRenderer::class);

    foreach ([',', "\t", ';'] as $delimiter) {
        [$headers, $rows] = $charts->parse(str_replace(',', $delimiter, ACCURACY));

        expect($headers)->toBe(['Model', 'Traditional', 'Hybrid'])
            ->and($rows[1])->toBe(['Logistic regression', '80.2', '79.4']);
    }
});

it('draws every chart type as SVG and PNG', function (string $type) {
    $charts = app(ChartRenderer::class);
    $spec = ['type' => $type, 'title' => 'A chart', 'data' => $type === 'pie' ? "Group,Count\nA,5\nB,3" : ACCURACY];

    expect($charts->svg($spec))->toStartWith('<svg')->toContain('A chart')
        ->and(substr($charts->png($spec, 1), 1, 3))->toBe('PNG');
})->with(ChartRenderer::TYPES);

it('describes a chart for screen readers', function () {
    expect(app(ChartRenderer::class)->describe(chartNode()['attrs']))
        ->toBe('Accuracy. Bar chart of Traditional and Hybrid by Model, 3 rows.');
});

it('previews a chart for the editor and explains bad data', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/charts/preview', chartNode()['attrs'])
        ->assertOk()
        ->assertJsonPath('description', 'Accuracy. Bar chart of Traditional and Hybrid by Model, 3 rows.');

    $this->actingAs($user)->postJson('/charts/preview', ['type' => 'bar', 'data' => 'only a header'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.data.0', 'A chart needs a header row and at least one row of data.');
});

it('puts charts in every export: SVG for readers, PNG for print and Word', function () {
    $book = chartBook(chartNode());

    expect(app(DocumentRenderer::class)->html($book->sections()->first()))->toContain('<figure class="chart"><svg');

    $print = app(PrintBook::class)->html($book, $book->sections()->get(), 'en');
    expect($print)->toContain('<img class="chart" alt="Accuracy. Bar chart')
        ->and($print)->toContain('data:image/png;base64,');

    $exporter = app(Exporter::class);
    $zip = new ZipArchive;

    $epub = $exporter->generate($book, 'epub');
    $zip->open(Storage::disk('local')->path($epub->path));
    expect($zip->getFromName('OEBPS/content.opf'))->toContain('properties="svg"');
    $zip->close();

    $docx = $exporter->generate($book, 'docx');
    $zip->open(Storage::disk('local')->path($docx->path));
    expect($zip->getFromName('word/media/image1.png'))->not->toBeFalse()
        ->and($zip->getFromName('word/document.xml'))->toContain('descr="Accuracy. Bar chart');
    $zip->close();
});

it('round-trips a chart through Markdown as its data', function () {
    $book = chartBook(chartNode());
    $markdown = app(DocumentRenderer::class)->markdown($book->sections()->first());

    expect($markdown)->toContain('```chart');

    $importer = app(MarkdownImporter::class);
    $doc = (new ReflectionMethod($importer, 'toProseMirror'))->invoke($importer, $markdown, new ImportReport);

    expect($doc['content'][1])->toEqual(chartNode());
});

it('shows a broken chart as a message rather than failing the export', function () {
    $book = chartBook(chartNode(['data' => 'no rows']));

    expect(app(DocumentRenderer::class)->html($book->sections()->first()))->toContain('Chart: A chart needs a header row');
});
