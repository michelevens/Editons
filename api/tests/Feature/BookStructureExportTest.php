<?php

use App\Models\Book;
use App\Models\BookExport;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\Exporter;
use App\Publishing\PrintBook;
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

/*
 * The printed book. The PDF was a real PDF but did not look like a book:
 * Times, because dompdf ignores fonts it has not been given; no page numbers,
 * because it ignores @page margin boxes; and chapters running on from one
 * another, because h1:first-of-type matched every chapter's heading.
 */
function typeset(Book $book): array
{
    return app(PrintBook::class)->typeset($book, $book->sections()->orderBy('position')->get(), 'en-US');
}

function printHtml(Book $book): string
{
    return app(PrintBook::class)->html($book, $book->sections()->orderBy('position')->get(), 'en-US');
}

it('sets the PDF in its own typeface rather than Times', function () {
    $book = structuredBook(User::factory()->create());

    $serif = typeset($book)['pdf'];

    $book->update(['layout' => ['font' => 'sans'] + $book->layout()]);
    $sans = typeset($book->fresh())['pdf'];

    expect($serif)->toContain('EBGaramond')
        ->and($serif)->not->toContain('Times-Roman')
        ->and($sans)->toContain('SourceSans3');
});

it('lands every section on the page the contents promises', function () {
    // The contents numbers come from a first pass; the second pass inserts
    // blank pages. If the arithmetic between them drifts, the contents lies.
    $result = typeset(structuredBook(User::factory()->create()));

    foreach ($result['blocks'] as $block) {
        expect($result['landed'][$block['id']])->toBe($block['physical'], "{$block['kind']} moved");
    }
});

it('opens every part on a right-hand page with a blank behind it', function () {
    $blocks = typeset(structuredBook(User::factory()->create()))['blocks'];

    $parts = 0;

    foreach ($blocks as $i => $block) {
        if ($block['kind'] !== 'part') {
            continue;
        }

        $parts++;
        expect($block['physical'] % 2)->toBe(1)
            ->and($blocks[$i + 1]['physical'])->toBe($block['physical'] + 2);
    }

    expect($parts)->toBe(2);
});

it('numbers the pages from the first page of the text', function () {
    $blocks = collect(typeset(structuredBook(User::factory()->create()))['blocks']);

    // The Introduction is front matter, numbered in roman; the text starts
    // at 1 with the first part, on a recto.
    $body = $blocks->skipUntil(fn ($b) => $b['restart'])->values();
    $intro = $blocks->firstWhere('title', 'Introduction');

    expect($body->first()['page'])->toBe('1')
        ->and($body->first()['title'])->toBe('Beginnings')
        ->and($body->first()['physical'] % 2)->toBe(1)
        ->and($intro['page'])->toMatch('/^[ivx]+$/')
        ->and($body->pluck('page')->map(fn ($p) => (int) $p)->all())->toBe($body->pluck('page')->map(fn ($p) => (int) $p)->sort()->values()->all())
        // The title page, copyright and contents come before page one.
        ->and($blocks->first()['kind'])->toBe('half-title');
});

it('opens chapters with a label and sets the title once', function () {
    $book = structuredBook(User::factory()->create());

    // Authors commonly type the chapter title as its first line.
    $chapter = $book->sections()->where('title', 'The Second Chapter')->first();
    $chapter->update(['content' => ['type' => 'doc', 'content' => [
        ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => '2. The Second Chapter']]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'More prose, in the second part.']]],
    ]]]);

    $html = printHtml($book->fresh());

    expect($html)->toContain('Chapter One')
        ->and($html)->toContain('Chapter Two')
        ->and($html)->toContain('Part One')
        // Once in the contents, once on the opener; not again in the prose.
        ->and(substr_count($html, 'The Second Chapter'))->toBe(2)
        ->and($html)->not->toContain('2. The Second Chapter')
        // The part keeps its title without its "Part One:" prefix.
        ->and($html)->toContain('>Beginnings<');
});

it('hyphenates running text and leaves headings whole', function () {
    $book = structuredBook(User::factory()->create());

    $chapter = $book->sections()->where('title', 'The First Chapter')->first();
    $chapter->update(['content' => ['type' => 'doc', 'content' => [
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Extraordinary Responsibilities']]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Extraordinary responsibilities accumulate &quot; unpredictably.']]],
    ]]]);

    $html = printHtml($book->fresh());

    preg_match('/<h2>(.*?)<\/h2>/s', $html, $heading);

    expect($html)->toContain("\u{00AD}")
        ->and($heading[1])->toBe('Extraordinary Responsibilities')
        // Entities in the prose are never broken by a hyphen.
        ->and($html)->toContain('&amp;quot;');
});

it('keeps a dedication off the contents', function () {
    $book = structuredBook(User::factory()->create());

    $book->sections()->create([
        'type' => 'front_matter',
        'title' => 'Dedication',
        'position' => 5,
        'content' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'For the ones who asked.']]],
        ]],
    ]);

    $html = printHtml($book->fresh());
    preg_match('/<table class="toc">.*?<\/table>/s', $html, $toc);

    expect($html)->toContain('For the ones who asked.')
        ->and($toc[0])->not->toContain('Dedication');
});

it('gives browsers the leading the author chose', function () {
    // The PDF corrects line height for dompdf's font metrics; the HTML
    // must not, or a browser would set it far too tight.
    $book = structuredBook(User::factory()->create());

    expect(printHtml($book))->toContain('line-height: 1.45;');
});
