<?php

use App\Import\ImportReport;
use App\Import\MarkdownImporter;
use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\Exporter;
use App\Publishing\InteriorDesign;
use App\Publishing\InteriorFonts;
use App\Publishing\PageSetup;
use App\Publishing\PrintBook;
use App\Publishing\PrintCheck;
use App\Publishing\PrintSpecs;
use FontLib\Font;
use Symfony\Component\Process\Process;

/**
 * Mirrored margins, trims and typography (ADR-00XX — Mirrored margins,
 * trims, typography and house styles): page settings and their limits,
 * the print services' trims and gutter bands, the print interior's
 * mirrored versos read back from the PDF, the interior faces, large print
 * at 18 pt, and paragraph and heading styles.
 */
function tyMarkdown(string $markdown): array
{
    $convert = new ReflectionMethod(app(MarkdownImporter::class), 'toProseMirror');

    return $convert->invoke(app(MarkdownImporter::class), $markdown, new ImportReport);
}

/** A book of [title, type, markdown] sections, in order. */
function tyBook(array $sections, array $book = [], ?User $owner = null): Book
{
    $model = Book::factory()->for($owner ?? User::factory())->create($book + ['title' => 'The Typography Fixture', 'genre' => 'Nonfiction', 'language' => 'English (US)', 'author_name' => 'Ada Writer']);

    foreach ($sections as $i => [$title, $type, $content]) {
        ManuscriptSection::factory()->for($model)->create([
            'title' => $title, 'type' => $type, 'position' => ($i + 1) * 1024,
            'content' => is_array($content) ? $content : tyMarkdown((string) $content),
        ]);
    }

    return $model->fresh();
}

function tyProse(int $paragraphs, string $seed = 'river'): string
{
    $sentence = "The {$seed} ran low that summer, and the town learned to measure water by the bucket and the hour. ";

    return implode("\n\n", array_fill(0, $paragraphs, str_repeat($sentence, 6)));
}

function tyWith(Book $book, array $layout): Book
{
    $book->update(['layout' => array_merge($book->layout(), $layout)]);

    return $book->fresh();
}

function tyTypeset(Book $book, bool $cover = false): array
{
    return app(PrintBook::class)->typeset($book, $book->sections()->orderBy('position')->get()->map->asPublished(), Exporter::languageCode($book->language), $cover);
}

/** PyMuPDF's reading of a PDF (tests/Support/pdfprobe.py); skipped without it. */
function tyProbe(string $pdf, bool $meta = false): array
{
    $file = tempnam(sys_get_temp_dir(), 'pdf');
    file_put_contents($file, $pdf);
    $process = new Process(array_values(array_filter(['python', '-I', base_path('tests/Support/pdfprobe.py'), $file, $meta ? '--meta' : null])));
    $process->run();
    @unlink($file);

    if (! $process->isSuccessful()) {
        test()->markTestSkipped('PyMuPDF is not available: '.mb_substr($process->getErrorOutput(), 0, 200));
    }

    return json_decode($process->getOutput(), true);
}

/** The left edge of a page's body text, points: the leftmost body-size span. */
function tyLeftEdge(array $page, float $size): ?float
{
    $xs = collect($page['spans'])
        ->filter(fn ($s) => abs($s['size'] - $size) < 0.6 && $s['bbox'][1] > 80 && $s['bbox'][3] < $page['height'] - 70)
        ->map(fn ($s) => $s['bbox'][0]);

    return $xs->isEmpty() ? null : (float) $xs->min();
}

/** The right edge of a page's body text, points. */
function tyRightEdge(array $page, float $size): ?float
{
    $xs = collect($page['spans'])
        ->filter(fn ($s) => abs($s['size'] - $size) < 0.6 && $s['bbox'][1] > 80 && $s['bbox'][3] < $page['height'] - 70)
        ->map(fn ($s) => $s['bbox'][2]);

    return $xs->isEmpty() ? null : (float) $xs->max();
}

function tyNovel(int $chapters = 3, int $paragraphs = 9): array
{
    return collect(range(1, $chapters))->map(fn ($i) => ["Chapter {$i}", 'chapter', tyProse($paragraphs, 'river'.$i)])->all();
}

// --- Settings ---------------------------------------------------------------

it('fills in the page, text and heading defaults, which reproduce the old interior', function () {
    $layout = tyBook([])->layout();

    expect($layout['page'])->toBe(['top' => null, 'bottom' => null, 'inside' => null, 'outside' => null, 'unit' => 'in'])
        ->and($layout['text']['indent'])->toBe(1.35)
        ->and($layout['heading_font'])->toBeNull()
        ->and(PageSetup::margins($layout))->toBe(['top' => 0.9, 'bottom' => 0.95, 'inside' => 0.78, 'outside' => 0.78])
        ->and(PageSetup::mirrored($layout))->toBeFalse();
});

it('saves margins, paragraph and heading styles, merging a heading level sent in part', function () {
    $book = tyBook([['One', 'chapter', 'Text.']]);

    $this->actingAs($book->user)->patchJson("/books/{$book->id}/layout", [
        'page' => ['inside' => 0.9, 'outside' => 0.6, 'top' => 0.8],
        'text' => ['paragraph' => 'block', 'align' => 'left', 'hyphenate' => false, 'orphans' => 3],
        'headings' => ['h2' => ['size' => 1.4, 'align' => 'center']],
        'heading_font' => 'lora',
        'font' => 'crimson-pro',
        'size' => 12.5,
    ])->assertOk()
        ->assertJsonPath('layout.page.inside', 0.9)
        ->assertJsonPath('margins.mirrored', true);

    $layout = $book->fresh()->layout();
    expect($layout['headings']['h2'])->toMatchArray(['size' => 1.4, 'align' => 'center', 'weight' => 'bold', 'above' => 1.75])
        ->and($layout['text'])->toMatchArray(['paragraph' => 'block', 'align' => 'left', 'hyphenate' => false, 'orphans' => 3, 'widows' => 2])
        ->and($layout['font'])->toBe('crimson-pro')
        ->and($layout['heading_font'])->toBe('lora');
});

it('refuses page, text and heading values outside the schema', function (array $change, string $field) {
    $book = tyBook([['One', 'chapter', 'Text.']]);

    $this->actingAs($book->user)->patchJson("/books/{$book->id}/layout", $change)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'margin under 0.25 in' => [['page' => ['inside' => 0.1]], 'page.inside'],
    'margin over 2 in' => [['page' => ['outside' => 2.5]], 'page.outside'],
    'margin as text' => [['page' => ['top' => 'wide']], 'page.top'],
    'unknown page key' => [['page' => ['gutter' => 1]], 'page.gutter'],
    'margins that leave no text block' => [['page' => ['inside' => 2.0, 'outside' => 2.0]], 'page.outside'],
    'unit' => [['page' => ['unit' => 'cm']], 'page.unit'],
    'paragraph' => [['text' => ['paragraph' => 'hanging']], 'text.paragraph'],
    'indent' => [['text' => ['indent' => 9]], 'text.indent'],
    'widows' => [['text' => ['widows' => 0]], 'text.widows'],
    'heading size' => [['headings' => ['h1' => ['size' => 5]]], 'headings.h1'],
    'heading key' => [['headings' => ['h1' => ['colour' => 'red']]], 'headings.h1'],
    'heading level' => [['headings' => ['h7' => ['size' => 1]]], 'headings.h7'],
    'heading face' => [['heading_font' => 'comic-sans'], 'heading_font'],
    'body face' => [['font' => 'comic-sans'], 'font'],
    'size over 24 pt' => [['size' => 25], 'size'],
    'margin preset' => [['margins' => 'Huge'], 'margins'],
]);

it('accepts type up to 24 pt and every interior face', function () {
    $book = tyBook([['One', 'chapter', 'Text.']]);

    foreach (InteriorFonts::keys() as $key) {
        $this->actingAs($book->user)->patchJson("/books/{$book->id}/layout", ['font' => $key, 'size' => 24])->assertOk();
    }

    expect((float) $book->fresh()->layout()['size'])->toBe(24.0);
});

// --- Trims -------------------------------------------------------------------

it('offers every standard KDP and IngramSpark trim, and each service lists only trims the screen offers', function () {
    foreach (['kdp', 'ingram'] as $service) {
        $spec = PrintSpecs::spec($service);

        foreach ([...$spec['trims'], ...$spec['hardcover_trims'], ...($spec['trims_by_paper']['cream'] ?? [])] as $trim) {
            expect(Book::TRIMS)->toContain($trim);
        }
    }

    expect(Book::TRIMS)->toContain('5.83 x 8.27 in', '6.14 x 9.21 in', '8.27 x 11.69 in')
        ->and(collect(PageSetup::catalog())->firstWhere('value', '5.83 x 8.27 in')['name'])->toBe('A5');
});

it('accepts a custom trim within the services’ range, normalised, and refuses one outside it', function () {
    $book = tyBook([['One', 'chapter', 'Text.']]);

    $this->actingAs($book->user)->patchJson("/books/{$book->id}/layout", ['trim' => '5.250 x 7.75'])
        ->assertOk()->assertJsonPath('layout.trim', '5.25 x 7.75 in');
    $this->actingAs($book->user)->patchJson("/books/{$book->id}/layout", ['trim' => '6.00 x 9.00 in'])
        ->assertOk()->assertJsonPath('layout.trim', '6 x 9 in');

    foreach (['3 x 5 in', '9 x 12 in', 'A5', '5 x 20 in'] as $bad) {
        $this->actingAs($book->user)->patchJson("/books/{$book->id}/layout", ['trim' => $bad])
            ->assertUnprocessable()->assertJsonValidationErrors('trim');
    }
});

it('checks the trim against each service: listed, custom within its range, or refused', function () {
    $book = tyBook([['One', 'chapter', tyProse(3)]]);
    $trim = fn (Book $b, string $service) => collect(app(PrintCheck::class)->for($b, $service)['items'])->firstWhere('key', 'trim');

    expect($trim(tyWith($book, ['trim' => '6.14 x 9.21 in']), 'kdp')['status'])->toBe('pass')
        // A5: IngramSpark lists it; KDP's US list does not, but its custom range covers it.
        ->and($trim(tyWith($book, ['trim' => '5.83 x 8.27 in']), 'ingram')['message'])->toContain('prints 5.83 x 8.27 in')
        ->and($trim(tyWith($book, ['trim' => '5.83 x 8.27 in']), 'kdp')['message'])->toContain('custom size')
        // 8.5 x 11.5 is past IngramSpark's 11 in custom height, inside KDP's 11.69.
        ->and($trim(tyWith($book, ['trim' => '8.5 x 11.5 in']), 'ingram')['status'])->toBe('fail')
        ->and($trim(tyWith($book, ['trim' => '8.5 x 11.5 in']), 'kdp')['status'])->toBe('pass');

    // On cream paper IngramSpark lists fewer sizes and a narrower custom range.
    $book->forceFill(['print' => ['paper' => 'cream']])->save();
    expect($trim(tyWith($book->fresh(), ['trim' => '7 x 10 in']), 'ingram')['status'])->toBe('fail')
        ->and($trim(tyWith($book->fresh(), ['trim' => '6 x 9 in']), 'ingram')['status'])->toBe('pass');
});

// --- The gutter band ----------------------------------------------------------

it('never sets the inside margin below the strictest service’s band for the page count, and says why', function () {
    $book = tyWith(tyBook([['One', 'chapter', 'Text.']]), ['margins' => 'Narrow', 'page' => ['inside' => 0.5, 'outside' => 0.5]]);
    $layout = $book->layout();

    expect(PrintBook::insideMargin($layout, 120))->toBe(0.5)
        ->and(PrintBook::insideMargin($layout, 400))->toBe(0.625)
        ->and(PrintBook::insideMargin($layout, 600))->toBe(0.75)
        ->and(PageSetup::gutterReason(600))->toMatchArray(['inches' => 0.75, 'service' => 'Amazon KDP'])
        // Symmetric margins widen both sides, as they always did; mirrored, only the inside.
        ->and(PageSetup::sides($layout, 0.75))->toBe([0.75, 0.75])
        ->and(PageSetup::sides(array_merge($layout, ['page' => ['inside' => 0.6, 'outside' => 0.4]]), 0.75))->toBe([0.75, 0.4]);

    $this->actingAs($book->user)->getJson("/books/{$book->id}/design?print=1")
        ->assertOk()
        ->assertJsonPath('margins.set.inside', 0.5)
        ->assertJsonStructure(['margins' => ['effective', 'required', 'service', 'pages', 'widened', 'recommended']]);
});

it('fails the print check when the typeset inside margin is under the band, and warns under IngramSpark’s recommended edges', function () {
    $book = tyWith(tyBook([['One', 'chapter', tyProse(3)]]), ['page' => ['inside' => 0.4, 'outside' => 0.3, 'top' => 0.4]]);
    $items = fn (string $service) => collect(app(PrintCheck::class)->for($book, $service, ['pages' => 620, 'exact' => true, 'at' => 'now', 'margin' => 0.4, 'outside' => 0.3])['items']);

    expect($items('kdp')->firstWhere('key', 'margins')['status'])->toBe('fail')
        ->and($items('kdp')->firstWhere('key', 'margins')['fix'])->toContain('0.75')
        ->and($items('kdp')->firstWhere('key', 'edges'))->toBeNull()
        ->and($items('ingram')->firstWhere('key', 'edges')['status'])->toBe('warn');

    $narrow = tyWith($book, ['page' => ['inside' => 0.8, 'outside' => 0.25, 'top' => 0.25]]);
    expect(collect(app(PrintCheck::class)->for($narrow, 'kdp', ['pages' => 100, 'exact' => true, 'at' => 'now', 'margin' => 0.8, 'outside' => 0.25])['items'])->firstWhere('key', 'edges'))->toBeNull();
});

it('recommends a mirrored pair that keeps the text block width', function () {
    $layout = tyBook([])->layout();
    $pair = PageSetup::recommendedMirror($layout, 200);

    expect($pair['inside'])->toBe(0.905)
        ->and($pair['outside'])->toBe(0.655)
        ->and($pair['inside'] + $pair['outside'])->toBe(2 * 0.78);
});

// --- Mirrored margins in the PDF ---------------------------------------------

it('mirrors the print interior: versos moved by exactly inside − outside, fonts embedded, outline kept', function () {
    $book = tyWith(tyBook(tyNovel(3, 9)), ['page' => ['inside' => 1.0, 'outside' => 0.6]]);
    $set = tyTypeset($book);

    expect($set['mirrored'])->toBeTrue()
        ->and($set['margin'])->toBe(1.0)
        ->and($set['outside'])->toBe(0.6);

    $pages = tyProbe($set['pdf']);
    $rectos = [];
    $versos = [];

    foreach ($pages as $i => $page) {
        expect([$page['width'], $page['height']])->toBe([432.0, 648.0]);

        if (($left = tyLeftEdge($page, 11.0)) === null) {
            continue;
        }

        // Page 1 (index 0) is the half title, a recto.
        $i % 2 === 0 ? $rectos[] = $left : $versos[] = $left;
    }

    expect(count($rectos))->toBeGreaterThan(2)->and(count($versos))->toBeGreaterThan(2)
        // Rectos: the inside margin at the left (the spine); versos: the outside.
        ->and(min($rectos))->toEqualWithDelta(72 * 1.0, 0.6)
        ->and(min($versos))->toEqualWithDelta(72 * 0.6, 0.6)
        ->and(min($rectos) - min($versos))->toEqualWithDelta(72 * (1.0 - 0.6), 0.6);

    $meta = tyProbe($set['pdf'], meta: true);
    expect($meta['pages'])->toBe($set['pages'])
        ->and(collect($meta['fonts'])->every(fn ($f) => $f['embedded']))->toBeTrue()
        ->and(collect($meta['fonts'])->keys()->implode(' '))->toContain('EBGaramond')
        ->and(collect($meta['toc'])->pluck(1))->toContain('Chapter 1')
        ->and($meta['metadata']['title'])->toBe('The Typography Fixture');
});

it('keeps the reading PDF unmirrored: the same text block, centred, on every page', function () {
    $book = tyWith(tyBook(tyNovel(2, 9)), ['page' => ['inside' => 1.0, 'outside' => 0.6]]);
    $set = tyTypeset($book, cover: true);

    expect($set['mirrored'])->toBeFalse();

    $lefts = collect(tyProbe($set['pdf']))->map(fn ($page) => tyLeftEdge($page, 11.0))->filter();
    expect($lefts->min())->toEqualWithDelta(72 * 0.8, 0.6);
});

it('does not touch the interior of a book on symmetric margins', function () {
    $book = tyBook(tyNovel(2, 4));
    $set = tyTypeset($book);

    expect($set['mirrored'])->toBeFalse()
        ->and($set['margin'])->toBe(0.78)
        ->and($set['outside'])->toBe(0.78)
        // dompdf's own file, not a rewritten one.
        ->and($set['pdf'])->toContain('/Producer (');

    $lefts = collect(tyProbe($set['pdf']))->map(fn ($page) => tyLeftEdge($page, 11.0))->filter();
    expect($lefts->min())->toEqualWithDelta(72 * 0.78, 0.6);
});

it('places running heads and folios with the mirrored text block', function () {
    $book = tyWith(tyBook(tyNovel(2, 12)), ['page' => ['inside' => 1.0, 'outside' => 0.6], 'heads' => ['align' => 'outside'] + InteriorDesign::DEFAULTS['heads'], 'folio' => 'bottom-outside']);
    $pages = tyProbe(tyTypeset($book)['pdf']);
    $checked = 0;

    foreach ($pages as $i => $page) {
        $folio = collect($page['spans'])->filter(fn ($s) => $s['baseline'] > 600 && preg_match('/^\d+$/', trim($s['text'])))->first();
        $body = tyLeftEdge($page, 11.0);

        if (! $folio || $body === null) {
            continue;
        }

        // A verso's folio at its outside (left) edge, flush with its text; a recto's at the right.
        $i % 2 === 1
            ? expect($folio['bbox'][0])->toEqualWithDelta($body, 1.0)
            : expect($folio['bbox'][2])->toEqualWithDelta(432 - 72 * 0.6, 1.0);
        $checked++;
    }

    expect($checked)->toBeGreaterThan(3);
});

it('shows the preview spread with the mirrored margins', function () {
    $book = tyWith(tyBook(tyNovel(1, 3)), ['page' => ['inside' => 0.9, 'outside' => 0.6, 'top' => 0.7, 'bottom' => 0.8]]);

    $this->actingAs($book->user)->getJson("/books/{$book->id}/design?print=1")
        ->assertOk()
        ->assertJsonPath('print.page.inside', 0.9)
        ->assertJsonPath('print.page.outside', 0.6)
        ->assertJsonPath('print.page.top', 0.7)
        ->assertJsonPath('print.page.mirrored', true)
        ->assertJsonPath('print.page.head_at', 0.3);
});

// --- Faces ---------------------------------------------------------------------

it('ships every interior face with its licence, a real italic and bold, and French and Kreyòl letters', function () {
    $needed = "Réussir l'examen — Kreyòl Ayisyen « Bonjou » ÀÂÆÇÉÈÊËÎÏÔŒÙÛÜŸàâæçéèêëîïôœùûüÿ’‘“”…–";

    foreach (InteriorFonts::FAMILIES as $key => $family) {
        expect(InteriorFonts::paths($key))->toHaveCount(4);

        foreach (InteriorFonts::paths($key) as $file) {
            expect(is_file($file))->toBeTrue("{$file} is missing");
            $font = Font::load($file);
            $font->parseTableEntries();
            $map = $font->getUnicodeCharMap();
            $font->close();

            foreach (mb_str_split(str_replace(' ', '', $needed)) as $ch) {
                expect(isset($map[mb_ord($ch)]))->toBeTrue("{$family['name']} ({$file}) lacks {$ch}");
            }
        }

        $licence = str_contains($family['files'][0], 'interior/')
            ? resource_path('fonts/interior/OFL-'.str_replace(' ', '', $family['name']).'.txt')
            : resource_path('fonts/OFL-'.str_replace(' ', '', $family['name']).'.txt');
        expect(is_file($licence))->toBeTrue("No licence for {$family['name']}")
            ->and(file_get_contents($licence))->toContain('SIL OPEN FONT LICENSE');
    }
});

it('sets and embeds the chosen body and heading faces, with no fallback glyphs for French and Kreyòl', function (string $key) {
    $book = tyWith(tyBook([['Chapit en', 'chapter', "## Réussir l'examen — Kreyòl\n\n".str_repeat("Réussir l'examen — Kreyòl ayisyen, « bonjou », œuvre, çà et là. ", 30)]],
        ['language' => 'Haitian Creole']), ['font' => $key, 'heading_font' => $key === 'serif' ? 'lora' : 'serif']);
    $html = app(PrintBook::class)->html($book, $book->sections()->get()->map->asPublished(), 'ht');

    expect($html)->toContain(InteriorFonts::stack($key))
        ->and($html)->not->toContain('<span class="glyph">');

    $meta = tyProbe(tyTypeset($book)['pdf'], meta: true);
    $fonts = collect($meta['fonts'])->keys()->implode(' ');
    expect($fonts)->toContain(str_replace(' ', '', InteriorFonts::name($key)))
        ->and(collect($meta['fonts'])->every(fn ($f) => $f['embedded']))->toBeTrue();
})->with(fn () => InteriorFonts::keys());

it('serves the interior faces to the preview, and nothing else', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/preview/fonts/Lora-Regular.ttf')->assertOk();
    $this->actingAs($user)->get('/preview/fonts/EBGaramond-Regular.ttf')->assertOk();
    $this->actingAs($user)->get('/preview/fonts/OFL-Lora.ttf')->assertNotFound();
});

// --- Large print -----------------------------------------------------------------

it('sets a large-print edition in 18 pt', function () {
    $book = tyBook(tyNovel(1, 3), [], User::factory()->subscribed('author')->create());

    $response = $this->actingAs($book->user)->postJson("/books/{$book->id}/editions", ['type' => 'large_print'])->assertCreated();

    $edition = Book::findOrFail($response->json('edition.id'));
    $layout = $edition->layout();

    expect((float) $layout['size'])->toBe(18.0)
        ->and($layout['margins'])->toBe('Large');

    $sizes = collect(tyProbe(tyTypeset($edition)['pdf']))->flatMap(fn ($p) => collect($p['spans'])->pluck('size'))->countBy()->sortDesc();
    // The text itself is 18 pt: the commonest size on the pages.
    expect((float) $sizes->keys()->first())->toEqualWithDelta(18.0, 0.05);
});

// --- Paragraphs and headings ---------------------------------------------------------

it('adds nothing to the stylesheet for the default text and headings', function () {
    $book = tyBook([['One', 'chapter', "## A heading\n\n".tyProse(1)]]);
    $css = (new ReflectionMethod(PrintBook::class, 'typographyCss'))->invoke(app(PrintBook::class), $book->layout());

    expect($css)->toBe('');
});

it('writes paragraph, alignment, orphan and heading styles, and the heading face, into the print CSS', function () {
    $book = tyWith(tyBook([['One', 'chapter', "## A heading\n\n### Smaller\n\n".tyProse(1)]]), [
        'text' => ['paragraph' => 'block', 'spacing' => 0.8, 'align' => 'left', 'orphans' => 3] + InteriorDesign::DEFAULTS['text'],
        'headings' => ['h2' => ['size' => 1.5, 'case' => 'upper', 'align' => 'center'] + InteriorDesign::DEFAULTS['headings']['h2']] + InteriorDesign::DEFAULTS['headings'],
        'heading_font' => 'literata',
    ]);
    $html = app(PrintBook::class)->html($book, $book->sections()->get()->map->asPublished(), 'en-US');

    expect($html)->toContain('.text > p { margin: 0 0 0.8em; }')
        ->and($html)->toContain('p { text-align: left; }')
        ->and($html)->toContain('p { orphans: 3; widows: 2; }')
        ->and($html)->toContain('.text h2 { font-size: 1.5em; font-weight: bold; font-style: normal; text-transform: uppercase; letter-spacing: .08em; margin: 1.75em 0 0.6em; text-align: center; font-family: \'Literata\'')
        ->and($html)->toContain('.tp-title, .opener-title, .part-title');
});

it('turns hyphenation off but keeps French spacing', function () {
    $text = "Les mathématiques exigent une méthode rigoureuse : calculatrice, raisonnement, vérification systématique.\n\n".tyProse(1, 'rivière');
    $on = tyBook([['Un', 'chapter', $text]], ['language' => 'French']);
    $off = tyWith(tyBook([['Un', 'chapter', $text]], ['language' => 'French']), ['text' => ['hyphenate' => false] + InteriorDesign::DEFAULTS['text']]);
    $html = fn (Book $b) => app(PrintBook::class)->html($b, $b->sections()->get()->map->asPublished(), 'fr');

    expect($html($on))->toContain("\u{00AD}")
        ->and($html($off))->not->toContain("\u{00AD}")
        ->and($html($off))->toContain("rigoureuse\u{00A0}:");
});

it('lists the same faces for the browser previews as the PDF uses', function () {
    $js = file_get_contents(resource_path('js/Components/Studio/preview.js'));

    foreach (InteriorFonts::FAMILIES as $family) {
        expect($js)->toContain("['{$family['name']}', [");

        foreach ($family['files'] as $file) {
            expect($js)->toContain("'".basename($file).".ttf'");
        }
    }
});
