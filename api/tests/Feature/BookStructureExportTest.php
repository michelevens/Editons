<?php

use App\Models\Book;
use App\Models\BookExport;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\Exporter;
use Illuminate\Support\Facades\Storage;

/**
 * Exporting a book that looks like a book.
 *
 * chapters() rejects containers, because every renderer wants text to lay
 * out. That is right for the body and was wrong for the book: an author who
 * arranged twenty chapters into five parts got an export with no parts in
 * it, no contents page, and no title page.
 *
 * These assert the structure survives into every format, and that the PDF is
 * a PDF — which it was not, for a long time, while being priced as one.
 */
function structuredBook(User $user): Book
{
    $book = $user->books()->create([
        'title' => 'A Structured Book',
        'subtitle' => 'With Parts And Everything',
        'author_name' => 'A Writer',
        'book_type' => 'nonfiction',
    ]);

    $make = function (string $type, string $title, int $position, array $blocks = []) use ($book) {
        $content = [];

        foreach ($blocks as [$kind, $text]) {
            $content[] = $kind === 'p'
                ? ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]
                : [
                    'type' => 'heading',
                    'attrs' => ['level' => (int) substr($kind, 1)],
                    'content' => [['type' => 'text', 'text' => $text]],
                ];
        }

        $section = $book->sections()->create([
            'type' => $type,
            'title' => $title,
            'position' => $position,
            'content' => ['type' => 'doc', 'content' => $content],
        ]);

        $section->forceFill([
            'word_count' => ManuscriptSection::countWords($section->content),
        ])->save();

        return $section;
    };

    $make('front_matter', 'Introduction', 10, [['p', 'An opening that sets out the argument.']]);

    $one = $make('part', 'Part One: Beginnings', 20);
    $make('chapter', 'The First Chapter', 30, [
        ['h2', '1.1 A Section'],
        ['p', 'Prose beneath the section heading.'],
        ['h3', '1.1.1 A Subsection'],
        ['p', 'Prose beneath the subsection heading.'],
    ])->forceFill(['parent_id' => $one->id])->save();

    $two = $make('part', 'Part Two: Endings', 40);
    $make('chapter', 'The Second Chapter', 50, [['p', 'More prose, in the second part.']])
        ->forceFill(['parent_id' => $two->id])->save();

    $make('back_matter', 'Appendix A: The Instrument', 60, [['p', 'The questionnaire, reproduced.']]);
    $make('back_matter', 'References', 70, [['p', 'The sources this book rests on.']]);

    $book->refresh()->recalculateWordCount();

    return $book->fresh();
}

/** Generate one format and return its contents. */
function exported(Book $book, string $format): string
{
    $export = app(Exporter::class)->generate($book, $format, $book->user_id);

    expect($export->status)->toBe('ready');

    return Storage::disk('local')->get($export->path);
}

it('puts the parts into the HTML export', function () {
    // The bug: containers were rejected, so a book arranged into parts
    // exported as a flat run of chapters.
    $html = exported(structuredBook(User::factory()->create()), 'html');

    expect($html)->toContain('Part One: Beginnings')
        ->and($html)->toContain('Part Two: Endings');
});

it('gives the book a contents page', function () {
    $html = exported(structuredBook(User::factory()->create()), 'html');

    expect($html)->toContain('Contents')
        ->and($html)->toContain('class="toc"')
        // Parts are marked so a renderer can set them apart.
        ->and($html)->toContain('toc-part');
});

it('gives the book a title page', function () {
    $html = exported(structuredBook(User::factory()->create()), 'html');

    expect($html)->toContain('class="title-page"')
        ->and($html)->toContain('A Structured Book')
        ->and($html)->toContain('With Parts And Everything')
        ->and($html)->toContain('A Writer');
});

it('keeps sections and subsections', function () {
    // These always survived — the renderer passes heading levels through —
    // and the test exists so a change to the pipeline cannot lose them.
    $html = exported(structuredBook(User::factory()->create()), 'html');

    expect($html)->toContain('1.1 A Section')
        ->and($html)->toContain('1.1.1 A Subsection')
        ->and(substr_count($html, '<h2'))->toBeGreaterThan(0)
        ->and(substr_count($html, '<h3'))->toBeGreaterThan(0);
});

it('keeps front and back matter', function () {
    $html = exported(structuredBook(User::factory()->create()), 'html');

    expect($html)->toContain('Introduction')
        ->and($html)->toContain('Appendix A: The Instrument')
        ->and($html)->toContain('References');
});

it('produces an actual PDF', function () {
    /*
     * The product priced and named a Print PDF for a long time while
     * writing an HTML file with a .pdf extension, because no renderer was
     * installed. The file would not open in any PDF reader.
     */
    $pdf = exported(structuredBook(User::factory()->create()), 'pdf');

    expect(substr($pdf, 0, 5))->toBe('%PDF-')
        ->and(substr_count($pdf, '/Type /Page'))->toBeGreaterThan(0);
});

it('paginates the PDF', function () {
    // A single-page PDF of a whole book means the page rules are not
    // applying, which is the failure mode that looks like success.
    $pdf = exported(structuredBook(User::factory()->create()), 'pdf');

    expect(substr_count($pdf, '/Type /Page'))->toBeGreaterThan(1);
});

it('puts the parts into the EPUB navigation', function () {
    $book = structuredBook(User::factory()->create());
    $export = app(Exporter::class)->generate($book, 'epub', $book->user_id);

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($export->path));
    $nav = $zip->getFromName('OEBPS/nav.xhtml');
    $zip->close();

    expect($nav)->toContain('Part One: Beginnings')
        ->and($nav)->toContain('Appendix A: The Instrument');
});

it('offers PDF on every plan that was already paying for it', function () {
    // 'pdf' has been an entitlement in the billing table since it was
    // written. Adding the renderer must not change who can reach it.
    foreach (config('billing.plans') as $key => $plan) {
        $this->assertContains('pdf', $plan['formats'], "[{$key}] lost the PDF format.");
    }

    expect(BookExport::FORMATS)->toHaveKey('pdf');
});

it('generates every format it offers', function () {
    // The guard that would have caught the ZipArchive outage locally, had
    // the extension been missing here too.
    $book = structuredBook(User::factory()->create());

    foreach (array_keys(BookExport::FORMATS) as $format) {
        $export = app(Exporter::class)->generate($book, $format, $book->user_id);

        $this->assertSame('ready', $export->status, "[{$format}] failed: {$export->error}");
        $this->assertGreaterThan(0, $export->byte_size, "[{$format}] produced an empty file.");
    }
});
