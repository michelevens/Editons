<?php

use App\Models\Book;
use App\Models\BookExport;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\DocumentRenderer;
use App\Publishing\Exporter;
use App\Publishing\Numbering;
use App\Publishing\Placement;
use App\Publishing\PrintBook;
use App\Publishing\PrintCheck;
use App\Publishing\PrintPages;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Image and table placement (ADR-0044 — Image and table placement): the
 * attributes and their checking, pictures sized, aligned, wrapped and set
 * on a page of their own in print (read back from the PDF), the EPUB and
 * Word; full bleed refused; the 300 dpi warning; table styles, widths,
 * alignment and repeated headers; the numbering scheme a book chooses; and
 * books that use none of it rendering as before.
 */
beforeEach(function () {
    Storage::fake('files');
});

function plText(string $text, array $marks = []): array
{
    return ['type' => 'text', 'text' => $text] + ($marks ? ['marks' => array_map(fn ($m) => ['type' => $m], $marks)] : []);
}

function plPara(string $text): array
{
    return ['type' => 'paragraph', 'content' => [plText($text)]];
}

function plCaption(string $label, string $text): array
{
    return ['type' => 'paragraph', 'content' => [plText($label, ['bold']), plText(' '.$text)]];
}

function plProse(int $sentences = 12, string $seed = 'river'): array
{
    return plPara(str_repeat("The {$seed} ran low that summer, and the town measured water by the bucket. ", $sentences));
}

/** A grey PNG of the given size, stored as the book's picture; its src. */
function plPicture(Book $book, int $width, int $height, string $name = 'pictureaaaa1'): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 90, 90, 90));
    ob_start();
    imagepng($image);
    Storage::disk('files')->put("images/book-{$book->id}/{$name}.png", ob_get_clean());

    return "/books/{$book->id}/images/{$name}.png";
}

function plImage(string $src, array $attrs = []): array
{
    return ['type' => 'image', 'attrs' => ['src' => $src, 'alt' => 'A grey block', 'title' => null] + $attrs];
}

function plCell(string $text, string $type = 'tableCell', array $attrs = []): array
{
    return ['type' => $type, 'attrs' => $attrs + ['colspan' => 1, 'rowspan' => 1, 'colwidth' => null], 'content' => [plPara($text)]];
}

/** A table: a header row and the body rows given, every cell's attrs from $cell(row, column). */
function plTable(array $header, array $rows, array $attrs = [], ?callable $cell = null): array
{
    $cell ??= fn () => [];
    $out = [['type' => 'tableRow', 'content' => array_map(fn ($t, $c) => plCell($t, 'tableHeader', $cell(0, $c)), $header, array_keys($header))]];

    foreach ($rows as $r => $row) {
        $out[] = ['type' => 'tableRow', 'content' => array_map(fn ($t, $c) => plCell($t, 'tableCell', $cell($r + 1, $c)), $row, array_keys($row))];
    }

    return ['type' => 'table', 'attrs' => $attrs ?: null, 'content' => $out];
}

/** A book of [title, type, nodes] sections. */
function plBook(array $sections = [], array $book = []): Book
{
    $model = Book::factory()->for(User::factory())->create($book + ['title' => 'The Placement Fixture', 'genre' => 'Nonfiction', 'language' => 'English (US)', 'author_name' => 'Ada Writer']);

    foreach ($sections as $i => [$title, $type, $nodes]) {
        ManuscriptSection::factory()->for($model)->create([
            'title' => $title, 'type' => $type, 'position' => ($i + 1) * 1024,
            'content' => ['type' => 'doc', 'content' => $nodes],
        ]);
    }

    return $model->fresh();
}

/** Put nodes into the book's first chapter. */
function plChapter(Book $book, array $nodes): Book
{
    $book->sections()->orderBy('position')->first()->update(['content' => ['type' => 'doc', 'content' => $nodes]]);

    return $book->fresh();
}

function plSections(Book $book)
{
    return $book->sections()->orderBy('position')->get()->map->asPublished();
}

function plHtml(Book $book): string
{
    return app(PrintBook::class)->html($book, plSections($book), 'en');
}

function plTypeset(Book $book): array
{
    return app(PrintBook::class)->typeset($book, plSections($book), 'en');
}

/** PyMuPDF's reading of a PDF (tests/Support/pdfprobe.py); skipped without it. */
function plProbe(string $pdf): array
{
    $file = tempnam(sys_get_temp_dir(), 'pdf');
    file_put_contents($file, $pdf);
    $process = new Process(['python', '-I', base_path('tests/Support/pdfprobe.py'), $file]);
    $process->run();
    @unlink($file);

    if (! $process->isSuccessful()) {
        test()->markTestSkipped('PyMuPDF is not available: '.mb_substr($process->getErrorOutput(), 0, 200));
    }

    return json_decode($process->getOutput(), true);
}

/** The pages holding a raster picture. */
function plPicturePages(array $pages): array
{
    return array_values(array_filter($pages, fn ($p) => $p['images'] !== []));
}

/** @return array<string, string> entry => contents */
function plZip(BookExport $export): array
{
    $zip = new ZipArchive;
    $zip->open(Storage::disk('files')->path($export->path));
    $files = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $files[$zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
    }

    $zip->close();

    return $files;
}

// The text block of a 6 x 9 in book on Standard margins (0.78 in a side),
// points: the left and right edges of the measure.
const PL_LEFT = 56.16;
const PL_RIGHT = 375.84;
const PL_MEASURE = 319.68;

// --- Attributes ----------------------------------------------------------

it('reads every placement value, dropping unknown ones and holding widths to 10–100', function () {
    expect(Placement::image(['size' => 50, 'align' => 'right', 'wrap' => 'left', 'page' => 'full', 'captionPosition' => 'above', 'keep' => true]))
        ->toBe(['size' => 50, 'align' => 'right', 'wrap' => 'left', 'page' => 'full', 'captionPosition' => 'above', 'keep' => true])
        ->and(Placement::image(['size' => 4, 'align' => 'middle', 'wrap' => 'both', 'page' => 'spread', 'captionPosition' => 'side', 'keep' => 'yes']))
        ->toBe(['size' => 10, 'align' => null, 'wrap' => null, 'page' => null, 'captionPosition' => null, 'keep' => null])
        ->and(Placement::image(['size' => 250])['size'])->toBe(100)
        ->and(Placement::image(['size' => '33.4'])['size'])->toBe(33)
        ->and(Placement::image(['size' => 'wide'])['size'])->toBeNull()
        ->and(Placement::image([]))->toBe(['size' => null, 'align' => null, 'wrap' => null, 'page' => null, 'captionPosition' => null, 'keep' => null])
        ->and(Placement::table(['tableStyle' => 'zebra', 'fontSize' => 'normal', 'split' => 'avoid', 'repeatHeader' => false, 'captionPosition' => 'below']))
        ->toBe(['tableStyle' => 'zebra', 'fontSize' => 'normal', 'split' => 'avoid', 'repeatHeader' => false, 'captionPosition' => 'below'])
        ->and(Placement::table(['tableStyle' => 'tartan', 'fontSize' => 'huge', 'split' => 'never', 'repeatHeader' => 1]))
        ->toBe(['tableStyle' => null, 'fontSize' => null, 'split' => null, 'repeatHeader' => null, 'captionPosition' => null])
        ->and(Placement::cellAlign(['align' => 'justify']))->toBeNull()
        ->and(Placement::cellAlign(['align' => 'right']))->toBe('right');
});

it('checks placement values on save: unknown values dropped, bounds held, everything else kept', function () {
    $book = plBook([['One', 'chapter', [plPara('Text.')]]]);
    $section = $book->sections()->first();
    $src = plPicture($book, 300, 200);

    $this->actingAs($book->user)->putJson("/sections/{$section->id}/content", ['content' => ['type' => 'doc', 'content' => [
        plImage($src, ['size' => 400, 'align' => 'sideways', 'wrap' => 'right', 'keep' => 'true', 'width' => null, 'height' => null]),
        plTable(['A', 'B'], [['1', '2']], ['tableStyle' => 'zebra', 'fontSize' => 'giant'], fn ($r, $c) => [
            'align' => $c === 1 ? 'right' : 'diagonal',
            'colwidth' => $r === 0 ? ($c === 0 ? [120] : ['x']) : null,
        ]),
    ]]])->assertOk();

    $content = $section->fresh()->content['content'];

    expect($content[0]['attrs'])->toMatchArray(['src' => $src, 'alt' => 'A grey block', 'size' => 100, 'align' => null, 'wrap' => 'right', 'keep' => null, 'width' => null])
        ->and($content[1]['attrs'])->toMatchArray(['tableStyle' => 'zebra', 'fontSize' => null])
        ->and($content[1]['content'][0]['content'][0]['attrs'])->toMatchArray(['align' => null, 'colwidth' => [120]])
        ->and($content[1]['content'][0]['content'][1]['attrs'])->toMatchArray(['align' => 'right', 'colwidth' => null])
        // Nodes without placement keys are stored as they came.
        ->and(Placement::sanitize(['type' => 'doc', 'content' => [plImage($src)]]))->toBe(['type' => 'doc', 'content' => [plImage($src)]]);
});

// --- Pictures in print -----------------------------------------------------

it('sets a placed picture with its caption as one figure in the print HTML', function () {
    $book = plBook([['Chapter 1', 'chapter', []]]);
    $src = plPicture($book, 600, 400);
    $book = plChapter($book, [
        plProse(),
        plImage($src, ['size' => 40, 'wrap' => 'right', 'keep' => true]),
        plCaption('Figure 1.', 'A grey block.'),
        plProse(),
        plCaption('Figure 2.', 'Set above it.'),
        plImage($src, ['size' => 30, 'align' => 'left', 'captionPosition' => 'above']),
        plImage($src),
        plCaption('Figure 3.', 'Not placed.'),
    ]);

    $html = plHtml($book);

    expect($html)->toMatch('#<div class="placed placed-wrap-right placed-keep" style="width:40%"><img src="data:image/png;base64,[^"]+" alt="A grey block" />\s*<p class="caption caption-below"[^>]*><strong>Figure 1\.</strong> A grey block\.</p>\s*</div>#')
        ->and($html)->toMatch('#<div class="placed placed-left" style="width:30%"><p class="caption caption-above"[^>]*><strong>Figure 2\.</strong> Set above it\.</p>\s*<img #')
        // An unplaced picture is the old markup, its caption a paragraph after it.
        ->and($html)->toMatch('#\n<img src="data:image/png;base64,[^"]+" alt="A grey block" />\s*<p class="caption"[^>]*><strong>Figure 3\.</strong>#')
        ->and($html)->toContain('.text div.placed-wrap-right { float: right;');
});

it('prints a picture at its width share of the measure, aligned left, centre or right', function (string $align, float $x0) {
    $book = plBook([['Chapter 1', 'chapter', []]]);
    $book = plChapter($book, [plProse(), plImage(plPicture($book, 1600, 800), ['size' => 50, 'align' => $align]), plProse()]);

    $page = plPicturePages(plProbe(plTypeset($book)['pdf']))[0];
    [$left, , $right] = $page['images'][0];

    expect($page['width'])->toBe(432.0)
        ->and(abs(($right - $left) - PL_MEASURE * 0.5))->toBeLessThan(PL_MEASURE * 0.02)
        ->and(abs($left - $x0))->toBeLessThan(2.0);
})->with([
    'left' => ['left', PL_LEFT],
    'center' => ['center', PL_LEFT + PL_MEASURE * 0.25],
    'right' => ['right', PL_LEFT + PL_MEASURE * 0.5],
]);

it('prints a picture at each width preset', function (int $size) {
    $book = plBook([['Chapter 1', 'chapter', []]]);
    $book = plChapter($book, [plProse(), plImage(plPicture($book, 2000, 600), ['size' => $size]), plProse()]);

    [$left, , $right] = plPicturePages(plProbe(plTypeset($book)['pdf']))[0]['images'][0];

    expect(abs(($right - $left) - PL_MEASURE * $size / 100))->toBeLessThan(PL_MEASURE * 0.02);
})->with([25, 33, 66, 100]);

it('wraps text beside a picture set to the right, and sets it alone in a narrow reader', function () {
    $book = plBook([['Chapter 1', 'chapter', []]]);
    $book = plChapter($book, [plProse(3), plImage(plPicture($book, 800, 800), ['size' => 40, 'wrap' => 'right']), plProse(20, 'lake')]);

    $page = plPicturePages(plProbe(plTypeset($book)['pdf']))[0];
    [$x0, $y0, $x1, $y1] = $page['images'][0];

    // At the right edge, and body text on its left within its height.
    $beside = collect($page['spans'])->filter(fn ($s) => $s['bbox'][1] > $y0 + 2 && $s['bbox'][3] < $y1 - 2 && $s['size'] > 9);

    expect(abs($x1 - PL_RIGHT))->toBeLessThan(2.0)
        ->and($beside)->not->toBeEmpty()
        ->and($beside->every(fn ($s) => $s['bbox'][2] <= $x0 + 0.5))->toBeTrue();

    // The reader's stylesheet floats it only on a screen 30 em or wider.
    expect(Placement::readerCss())->toContain('@media (max-width: 30em) { div.placed-wrap-left, div.placed-wrap-right { float: none;');
});

it('sets a full-page picture on a page of its own, scaled to fit the text block', function () {
    $book = plBook([['Chapter 1', 'chapter', []]]);
    $book = plChapter($book, [
        plProse(4),
        plImage(plPicture($book, 600, 1200), ['page' => 'full']),
        plCaption('Figure 1.', 'A tall grey block.'),
        plProse(4, 'lake'),
    ]);

    $pages = plProbe(plTypeset($book)['pdf']);
    $page = plPicturePages($pages)[0];
    [$x0, $y0, $x1, $y1] = $page['images'][0];
    $body = collect($page['spans'])->filter(fn ($s) => str_contains($s['text'], 'ran low that summer'));

    // Inside the text block (0.9 in from the top, 0.95 from the foot), as
    // tall as it allows, centred; the prose before and after on other pages.
    expect($y0)->toBeGreaterThanOrEqual(0.9 * 72 - 1)
        ->and($y1)->toBeLessThanOrEqual(648 - 0.95 * 72 + 1)
        ->and($y1 - $y0)->toBeGreaterThan((9 - 0.9 - 0.95 - 0.6 - 0.15) * 72 - 4)
        ->and(abs(($x0 + $x1) / 2 - (PL_LEFT + PL_RIGHT) / 2))->toBeLessThan(1.5)
        ->and($body)->toBeEmpty()
        ->and($page['text'])->toContain('A tall grey block.');
});

it('refuses full bleed with the reason, printing the picture as a full page', function () {
    $book = plBook([['Chapter 1', 'chapter', []]]);
    $book = plChapter($book, [plProse(4), plImage(plPicture($book, 1600, 1000), ['page' => 'bleed']), plProse(4, 'lake')]);

    $html = plHtml($book);
    expect($html)->toContain('<div class="placed placed-page" style="width:100%">');

    $page = plPicturePages(plProbe(plTypeset($book)['pdf']))[0];
    [$x0, , $x1] = $page['images'][0];

    // Within the margins, never past the trim.
    expect($x0)->toBeGreaterThanOrEqual(PL_LEFT - 1)->and($x1)->toBeLessThanOrEqual(PL_RIGHT + 1);

    $items = collect(app(PrintCheck::class)->for($book, 'kdp')['items'])->keyBy('key');
    expect($items['bleed']['status'])->toBe('warn')
        ->and($items['bleed']['message'])->toContain('set without bleed');

    // In a reader it is simply the full width.
    $epub = app(DocumentRenderer::class)->html($book->sections()->first()->asPublished());
    expect($epub)->toContain('<div class="placed placed-page" style="width:100%">');
});

it('keeps a picture with its caption across a page break', function () {
    $book = plBook([['Chapter 1', 'chapter', []]]);
    $src = plPicture($book, 1200, 900);
    // Prose to push the picture to the foot of the first page.
    $nodes = [plProse(29)];
    $nodes[] = plImage($src, ['size' => 60, 'keep' => true]);
    $nodes[] = plCaption('Figure 1.', 'Kept with it.');
    $nodes[] = plProse(4, 'lake');
    $book = plChapter($book, $nodes);

    $pages = plProbe(plTypeset($book)['pdf']);
    $withPicture = plPicturePages($pages)[0];

    expect($withPicture['text'])->toContain('Kept with it.');
});

// --- Resolution ---------------------------------------------------------------

it('warns of pictures below 300 dpi at the width they are set at, and refuses nothing', function () {
    $block = [4.44, 7.15];

    expect(Placement::dpi(Placement::image(['size' => 25]), 600, 400, $block))->toBe(540)
        ->and(Placement::dpi(Placement::image(['size' => 100]), 600, 400, $block))->toBe(135)
        // At its own size it prints a pixel at 1/96 in.
        ->and(Placement::dpi(Placement::image([]), 300, 200, $block))->toBe(96)
        ->and(Placement::dpi(Placement::image([]), 600, 400, $block))->toBe(135)
        ->and(Placement::dpi(Placement::image([]), 3000, 400, $block))->toBe(675)
        // Wrapped with no width chosen: half the measure.
        ->and(Placement::dpi(Placement::image(['wrap' => 'left']), 700, 400, $block))->toBe(315);

    $book = plBook([['Chapter 1', 'chapter', []]]);
    $src = plPicture($book, 600, 400);
    $check = fn (Book $b) => collect(app(PrintCheck::class)->for($b, 'kdp')['items'])->keyBy('key')['images'];

    expect($check(plChapter($book, [plImage($src)]))['status'])->toBe('warn')
        ->and($check(plChapter($book, [plImage($src, ['size' => 25])]))['status'])->toBe('pass')
        ->and($check(plChapter($book, [plImage($src, ['size' => 25]), plImage($src, ['size' => 100])]))['message'])->toContain('1 picture will print below 300 dpi');

    // A warning, not a refusal: the interior still typesets.
    expect(plTypeset(plChapter($book, [plImage($src, ['size' => 100])]))['pages'])->toBeGreaterThan(0);
});

it('sends the editor the text block and the chosen numbering', function () {
    $book = plBook([['Chapter 1', 'chapter', [plPara('Text.')]]]);

    $this->actingAs($book->user)->getJson("/books/{$book->id}/cross-references?section={$book->sections()->first()->id}")
        ->assertOk()
        ->assertJsonPath('text_block.0', 4.44)
        ->assertJsonPath('text_block.1', 7.15)
        ->assertJsonPath('numbering', null);
});

// --- Tables -------------------------------------------------------------------

it('sets table styles, widths, alignment and size as classes the stylesheets know', function () {
    $book = plBook([['Chapter 1', 'chapter', [
        plTable(['Item', 'Count'], [['Apples', '12'], ['Pears', '7'], ['Plums', '30']], ['tableStyle' => 'zebra', 'fontSize' => 'normal', 'repeatHeader' => false],
            fn ($r, $c) => ['align' => $c === 1 ? 'right' : null, 'colwidth' => $r === 0 ? [$c === 0 ? 300 : 100] : null]),
        plTable(['A', 'B'], [['1', '2']], ['tableStyle' => 'grid', 'split' => 'allow']),
        plTable(['A', 'B'], [['1', '2']], ['tableStyle' => 'plain', 'split' => 'avoid']),
        plTable(['A', 'B'], [['1', '2']], ['tableStyle' => 'shaded']),
    ]]]);

    $html = plHtml($book);

    expect($html)->toContain('<table class="keep tbl-zebra tbl-normal tbl-norepeat tbl-widths">')
        ->and($html)->toContain('<th style="width:75%">Item</th><th class="ar" style="width:25%">Count</th>')
        ->and($html)->toContain('<tr><td>Apples</td><td class="ar">12</td></tr>')
        ->and($html)->toContain('<tr class="zebra"><td>Pears</td><td class="ar">7</td></tr>')
        ->and($html)->toContain('<table class="tbl-grid">')
        ->and($html)->toContain('<table class="keep tbl-plain">')
        ->and($html)->toContain('<table class="keep tbl-shaded">')
        ->and($html)->toContain('.text table.tbl-norepeat thead { display: table-row-group; }');
});

it('prints table widths, right-aligned figures and zebra rows in the PDF', function () {
    $book = plBook([['Chapter 1', 'chapter', [
        plProse(2),
        plTable(['Item', 'Count'], [['Apples', '12'], ['Pears', '7'], ['Plums', '30'], ['Figs', '4']], ['tableStyle' => 'zebra'],
            fn ($r, $c) => ['align' => $c === 1 ? 'right' : null, 'colwidth' => $r === 0 ? [$c === 0 ? 300 : 100] : null]),
    ]]]);

    $page = collect(plProbe(plTypeset($book)['pdf']))->first(fn ($p) => str_contains($p['text'], 'Apples'));
    $span = fn (string $t) => collect($page['spans'])->first(fn ($s) => trim($s['text']) === $t);

    // The second column starts three quarters of the way across, and its
    // figures end at the same right edge.
    $rights = collect(['12', '7', '30', '4'])->map(fn ($t) => $span($t)['bbox'][2]);
    expect($rights->max() - $rights->min())->toBeLessThan(0.6)
        ->and($span('7')['bbox'][0])->toBeGreaterThan(PL_LEFT + PL_MEASURE * 0.75)
        ->and($span('Count')['bbox'][2])->toBeGreaterThan($rights->max() - 0.6);

    // Every other body row tinted grey, the width of the table.
    $tints = collect($page['fills'])->filter(fn ($f) => abs($f['fill'][0] - 0.933) < 0.01 && $f['rect'][2] - $f['rect'][0] > 10);
    expect($tints)->not->toBeEmpty();
});

it('repeats a header row on each page a long table breaks across, unless turned off', function (?bool $repeat, int $expected) {
    $rows = array_map(fn ($i) => ["Row {$i}", (string) ($i * 3)], range(1, 70));
    $book = plBook([['Chapter 1', 'chapter', [
        plTable(['Heading Alpha', 'Heading Beta'], $rows, $repeat === null ? [] : ['repeatHeader' => $repeat]),
    ]]]);

    $pages = collect(plProbe(plTypeset($book)['pdf']))->filter(fn ($p) => str_contains($p['text'], 'Row '));

    expect($pages->count())->toBeGreaterThan(1)
        ->and($pages->filter(fn ($p) => str_contains($p['text'], 'Heading Alpha'))->count())->toBe($expected === 0 ? $pages->count() : $expected);
})->with([
    'repeats by default' => [null, 0],
    'turned off' => [false, 1],
]);

it('writes table styles, widths, alignment and size into the EPUB and Word', function () {
    $book = plBook([['Chapter 1', 'chapter', [
        plCaption('Table 1.', 'Fruit.'),
        plTable(['Item', 'Count'], [['Apples', '12'], ['Pears', '7']], ['tableStyle' => 'zebra', 'fontSize' => 'normal'],
            fn ($r, $c) => ['align' => $c === 1 ? 'right' : null, 'colwidth' => $r === 0 ? [$c === 0 ? 300 : 100] : null]),
        plTable(['A', 'B'], [['1', '2']], ['tableStyle' => 'grid', 'repeatHeader' => false]),
    ]]]);

    $epub = plZip(app(Exporter::class)->generate($book, 'epub'));
    $chapter = collect($epub)->first(fn ($c, $n) => str_contains($c, 'Apples'));

    expect($chapter)->toContain('<table class="tbl-zebra tbl-normal tbl-widths">')
        ->and($chapter)->toContain('<th class="ar" style="width:25%">Count</th>')
        ->and($chapter)->toContain('<tr class="zebra">')
        ->and($epub['OEBPS/style.css'])->toContain('table.tbl-zebra tr.zebra td { background-color: #eee; }');

    $docx = plZip(app(Exporter::class)->generate($book, 'docx'));
    $xml = $docx['word/document.xml'];

    expect($xml)->toContain('<w:tblGrid><w:gridCol w:w="6480"/><w:gridCol w:w="2160"/></w:tblGrid>')
        ->and($xml)->toContain('<w:tcW w:w="3750" w:type="pct"/>')
        ->and($xml)->toContain('<w:pStyle w:val="TableTextLarge"/><w:jc w:val="right"/>')
        ->and($xml)->toContain('<w:shd w:val="clear" w:color="auto" w:fill="EEEEEE"/>')
        ->and($xml)->toContain('<w:insideV w:val="single" w:sz="4" w:space="0" w:color="000000"/>')
        ->and(substr_count($xml, '<w:tblHeader/>'))->toBe(1)
        ->and($docx['word/styles.xml'])->toContain('w:styleId="TableTextLarge"');

    expect((new DOMDocument)->loadXML($xml))->toBeTrue();
});

it('writes a placed picture into Word with its width, alignment, wrap and kept caption', function () {
    $book = plBook([['Chapter 1', 'chapter', []]]);
    $src = plPicture($book, 1200, 600);
    $book = plChapter($book, [
        plImage($src, ['size' => 50, 'align' => 'right', 'keep' => true]),
        plCaption('Figure 1.', 'Right.'),
        plImage($src, ['size' => 40, 'wrap' => 'left']),
        plProse(),
        plImage($src, ['page' => 'full']),
        plProse(2, 'lake'),
    ]);

    $xml = plZip(app(Exporter::class)->generate($book, 'docx'))['word/document.xml'];
    // 50% of 6 in: 2,743,200 EMU wide.
    expect($xml)->toContain('<w:pPr><w:keepNext/><w:jc w:val="right"/></w:pPr><w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="2743200" cy="1371600"/>')
        ->and($xml)->toContain('<wp:positionH relativeFrom="column"><wp:align>left</wp:align></wp:positionH>')
        ->and($xml)->toContain('<wp:wrapSquare wrapText="right"/>')
        ->and($xml)->toContain('<w:pageBreakBefore/><w:jc w:val="center"/>')
        ->and((new DOMDocument)->loadXML($xml))->toBeTrue();
});

it('writes a placed picture into the EPUB, still structurally valid', function () {
    $book = plBook([['Chapter 1', 'chapter', []]]);
    $src = plPicture($book, 1200, 600);
    $book = plChapter($book, [plImage($src, ['size' => 50, 'wrap' => 'right']), plCaption('Figure 1.', 'Right.'), plProse()]);

    $epub = plZip(app(Exporter::class)->generate($book, 'epub'));
    $chapter = collect($epub)->first(fn ($c, $n) => str_ends_with($n, '.xhtml') && str_contains($c, 'Right.'));

    expect($chapter)->toMatch('#<div class="placed placed-wrap-right" style="width:50%"><img src="images/[a-z0-9]+\.png" alt="A grey block"\s*/>#')
        ->and($epub['OEBPS/style.css'])->toContain('div.placed-wrap-right { float: right;');

    foreach ($epub as $name => $contents) {
        if (preg_match('/\.(xhtml|opf|xml)$/', $name)) {
            expect(@(new DOMDocument)->loadXML($contents))->toBeTrue("{$name} is not well-formed");
        }
    }
});

it('writes column alignment into Markdown tables', function () {
    $book = plBook([['Chapter 1', 'chapter', [plTable(['Item', 'Count'], [['Apples', '12']], [], fn ($r, $c) => ['align' => $c === 1 ? 'right' : null])]]]);

    $markdown = app(DocumentRenderer::class)->markdown($book->sections()->first());

    expect($markdown)->toContain("| Item | Count |\n| --- | ---: |\n| Apples | 12 |");
});

// --- Numbering ----------------------------------------------------------------

it('numbers figures and tables continuously, by chapter or by part as the book chooses', function () {
    $book = plBook([
        ['Part One', 'part', []],
        ['First', 'chapter', [plCaption('Table 1', 'A.'), plPara('x'), plCaption('Table 2', 'B.')]],
        ['Second', 'chapter', [plCaption('Table 3', 'C.')]],
        ['Part Two', 'part', []],
        ['Third', 'chapter', [plCaption('Table 4', 'D.')]],
    ]);
    $sections = $book->sections()->orderBy('position')->get();
    $numbers = fn (?string $chosen) => collect(Numbering::forBook($sections, $chosen)['captions'])->pluck('number')->all();

    // Inferred: no dotted caption, so continuous.
    expect($numbers(null))->toBe(['1', '2', '3', '4'])
        ->and($numbers('continuous'))->toBe(['1', '2', '3', '4'])
        ->and($numbers('chapter'))->toBe(['1.1', '1.2', '2.1', '3.1'])
        ->and($numbers('part'))->toBe(['1.1', '1.2', '1.3', '2.1']);

    // The design setting reaches every export.
    $this->actingAs($book->user)->patchJson("/books/{$book->id}/layout", ['numbering' => 'part'])->assertOk();
    $html = plHtml($book->fresh());

    expect($html)->toContain('<strong>Table 1.3</strong>')
        ->and($html)->toContain('<strong>Table 2.1</strong>');

    // And the editor: the open chapter's prefix and offsets in its part.
    $third = $sections->firstWhere('title', 'Second');
    $this->actingAs($book->user)->getJson("/books/{$book->id}/cross-references?section={$third->id}")
        ->assertJsonPath('numbering', 'part')
        ->assertJsonPath('scheme', 'part')
        ->assertJsonPath('section.prefix', '1.')
        ->assertJsonPath('section.offsets.table', 2);
});

it('refuses an unknown numbering scheme and keeps print files current when none is chosen', function () {
    $book = plBook([['One', 'chapter', [plPara('Text.')]]]);

    $this->actingAs($book->user)->patchJson("/books/{$book->id}/layout", ['numbering' => 'alphabetical'])
        ->assertStatus(422)->assertJsonValidationErrors('numbering');

    // Unset, the setting leaves the fingerprint as it was before it existed.
    $before = PrintPages::fingerprint($book);
    $layout = $book->layout();
    unset($layout['numbering']);
    expect($before)->toBe(substr(md5(json_encode([
        \App\Publishing\InteriorDesign::VERSION, $layout, $book->publication(),
        [$book->title, $book->subtitle, $book->author_name, $book->series_name, $book->series_number, $book->language],
        (function () use ($book) {
            $s = $book->sections()->selectRaw('count(*) as n, max(updated_at) as changed, sum(word_count) as words')->first();

            return [$s?->n, (string) $s?->changed, $s?->words];
        })(),
    ])), 0, 16));

    $this->actingAs($book->user)->patchJson("/books/{$book->id}/layout", ['numbering' => 'chapter'])->assertOk();
    expect(PrintPages::fingerprint($book->fresh()))->not->toBe($before);
});

// --- Defaults -----------------------------------------------------------------

it('renders books that use no placement exactly as before', function () {
    $book = plBook([['Chapter 1', 'chapter', []]]);
    $src = plPicture($book, 600, 400);
    $book = plChapter($book, [
        plProse(),
        plImage($src, ['size' => null, 'align' => null, 'wrap' => null, 'page' => null, 'captionPosition' => null, 'keep' => null]),
        plCaption('Figure 1.', 'A grey block.'),
        plTable(['A', 'B'], [['1', '2']]),
    ]);

    $html = plHtml($book);

    expect(Placement::bookUses($book))->toBeFalse()
        ->and($html)->not->toContain('placed')
        ->and($html)->not->toContain('tbl-')
        ->and($html)->toMatch('#\n<img src="data:image/png;base64,[^"]+" alt="A grey block" />\n<p class="caption"#')
        ->and($html)->toContain("<table class=\"keep\">\n<thead>\n<tr><th>A</th><th>B</th></tr>");

    $epub = plZip(app(Exporter::class)->generate($book, 'epub'));
    expect($epub['OEBPS/style.css'])->not->toContain('placed')->not->toContain('tbl-');

    $docx = plZip(app(Exporter::class)->generate($book, 'docx'));
    expect($docx['word/styles.xml'])->not->toContain('TableTextLarge')
        ->and($docx['word/document.xml'])->toContain('<w:tblBorders><w:top w:val="single" w:sz="8"/><w:bottom w:val="single" w:sz="8"/><w:insideH w:val="single" w:sz="2" w:color="BFBFBF"/></w:tblBorders></w:tblPr><w:tr><w:trPr><w:tblHeader/></w:trPr>');
});
