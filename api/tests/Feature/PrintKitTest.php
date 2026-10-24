<?php

use App\Jobs\GenerateExport;
use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\CoverRenderer;
use App\Publishing\CoverWrap;
use App\Publishing\Exporter;
use App\Publishing\PrintCheck;
use App\Publishing\PrintPages;
use App\Publishing\PrintSpecs;
use App\Publishing\Royalties;
use App\Publishing\StoreReadiness;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * The print-on-demand kit: cover wraps, print checks, the price and
 * royalty calculator and store readiness. Every expected number below is
 * worked from the figures in config/publishing.php (docs/POD-SPECS.md).
 */
function podBook(?User $owner = null, array $attributes = []): Book
{
    $owner ??= User::factory()->create();
    $book = $owner->books()->create(array_merge([
        'title' => 'The Long Field',
        'author_name' => 'A Writer',
        'list_price' => 9.99,
        'meta' => [
            'description' => str_repeat('A clear and useful description of the book. ', 5),
            'category' => 'Fiction / Literary',
            'keywords' => 'fields, harvest',
        ],
    ], $attributes));

    ManuscriptSection::factory()->for($book)->create([
        'title' => 'Chapter One',
        'type' => 'chapter',
        'position' => 0,
        'content' => ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => str_repeat('Prose that belongs in a book. ', 80)]],
        ]]],
    ]);

    return declared($book);
}

/** Record a typeset page count for the book as it stands now. */
function typesetAt(Book $book, int $pages): void
{
    $book->exports()->create(['format' => 'pdf_interior', 'status' => 'ready', 'path' => 'exports/x.pdf'])
        ->forceFill(['pages' => $pages, 'options' => ['fingerprint' => PrintPages::fingerprint($book)]])->save();
}

function mediaBox(string $pdf): array
{
    preg_match('#/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]#', $pdf, $m);

    return [(float) $m[1], (float) $m[2]];
}

function podWrap(Book $book, array $options, int $pages): array
{
    $wraps = app(CoverWrap::class);

    return $wraps->geometry($book, $wraps->options($book, $options), $pages);
}

/* ------------------------------------------------------------ dimensions */

it('sizes a KDP wrap as two trims, the spine and the bleed, from the paper', function () {
    $book = podBook();

    // 6 x 9, 300 pages, white: spine 300 x 0.002252 = 0.6756 in.
    $white = podWrap($book, ['service' => 'kdp', 'paper' => 'white'], 300);
    expect($white['spine'])->toBe(0.6756)
        ->and($white['width'])->toBe(round(2 * 6 + 0.6756 + 2 * 0.125, 4))
        ->and($white['height'])->toBe(9.25);

    // Cream is thicker: 300 x 0.0025 = 0.75 in.
    expect(podWrap($book, ['service' => 'kdp', 'paper' => 'cream'], 300)['spine'])->toBe(0.75);

    // An odd interior is printed with a blank at the end.
    expect(podWrap($book, ['service' => 'kdp', 'paper' => 'white'], 301)['pages'])->toBe(302);

    // The PDF page is that size in points, with the trim inside the bleed.
    $pdf = app(CoverWrap::class)->pdf($book, $white);
    [$w, $h] = mediaBox($pdf);
    expect($w)->toEqualWithDelta(12.9256 * 72, 0.01)
        ->and($h)->toEqualWithDelta(9.25 * 72, 0.01)
        ->and($pdf)->toContain('/TrimBox [9 9 ')
        ->and($pdf)->toStartWith('%PDF-1.4');
});

it('follows the trim size and the ink', function () {
    $book = podBook(attributes: ['layout' => ['trim' => '8.5 x 11 in']]);
    $book->print = ['ink' => 'premium_color', 'paper' => 'cream'];
    $book->save();

    $g = podWrap($book->fresh(), ['service' => 'kdp'], 200);

    // Colour prints on white, at the premium colour caliper.
    expect($g['paper'])->toBe('white')
        ->and($g['spine'])->toBe(round(200 * 0.002347, 4))
        ->and($g['width'])->toBe(round(17 + 200 * 0.002347 + 0.25, 4))
        ->and($g['height'])->toBe(11.25);
});

it('uses Lulu\'s published formula, which matches its own API example', function () {
    // Lulu's cover-dimensions example: 6 x 9, 210 pages -> 920 x 666 pt.
    $g = podWrap(podBook(), ['service' => 'lulu', 'paper' => 'white'], 210);

    expect($g['width'] * 72)->toEqualWithDelta(920, 1)
        ->and($g['height'] * 72)->toEqualWithDelta(666, 0.01)
        ->and($g['barcode'])->toBeNull();

    expect(fn () => podWrap(podBook(), ['service' => 'lulu', 'paper' => 'cream'], 210))
        ->toThrow(RuntimeException::class, 'white paper only');
});

it('asks for IngramSpark\'s spine width rather than inventing one', function () {
    $book = podBook();

    expect(fn () => podWrap($book, ['service' => 'ingram'], 200))
        ->toThrow(RuntimeException::class, 'cover template generator');

    $g = podWrap($book, ['service' => 'ingram', 'spine' => 0.5], 200);
    expect($g['spine'])->toBe(0.5)
        ->and($g['width'])->toBe(12.75)
        ->and($g['barcode'])->toMatchArray(['width' => 1.75, 'height' => 1.0]);
});

it('places the KDP barcode box at the lower right of the back, clear of the spine and trim', function () {
    $g = podWrap(podBook(), ['service' => 'kdp'], 300);

    expect($g['barcode']['x'] + $g['barcode']['width'])->toEqualWithDelta($g['spine_x'] - 0.25, 1e-9)
        ->and($g['barcode']['y'] + $g['barcode']['height'])->toEqualWithDelta($g['height'] - 0.125 - 0.25, 1e-9)
        ->and($g['barcode']['width'])->toBe(2.0)
        ->and($g['barcode']['height'])->toBe(1.2);
});

/* ------------------------------------------------------------ the spine */

it('puts text on the spine only from the service\'s minimum page count', function () {
    expect(PrintSpecs::spineText('kdp', 78))->toBeFalse()
        ->and(PrintSpecs::spineText('kdp', 79))->toBeTrue() // printed as 80
        ->and(PrintSpecs::spineText('ingram', 46))->toBeFalse()
        ->and(PrintSpecs::spineText('ingram', 48))->toBeTrue()
        ->and(PrintSpecs::spineText('lulu', 80))->toBeFalse()
        ->and(PrintSpecs::spineText('lulu', 82))->toBeTrue();

    expect(podWrap(podBook(), ['service' => 'kdp'], 60)['spine_text'])->toBeFalse();
});

it('keeps IngramSpark\'s narrower spine margin for thin spines', function () {
    expect(PrintSpecs::spineMargin('ingram', 0.3))->toBe(0.03125)
        ->and(PrintSpecs::spineMargin('ingram', 0.4))->toBe(0.0625)
        ->and(PrintSpecs::spineMargin('kdp', 0.3))->toBe(0.0625);
});

it('sets the title, series, author and publisher on the spine', function () {
    $book = podBook(attributes: ['series_name' => 'Fields', 'series_number' => 2, 'publication' => ['imprint' => 'Maison Claire']]);
    [$title, $rest] = app(CoverWrap::class)->spineLines($book, podWrap($book, ['service' => 'kdp'], 300));

    expect($title)->toBe('The Long Field')
        ->and($rest)->toContain('Fields · 2')
        ->and($rest)->toContain('A Writer')
        ->and($rest)->toContain('Maison Claire');
});

it('prints no author anywhere for an anonymous book', function () {
    $book = podBook(attributes: ['author_name' => null, 'cover' => ['author' => '']]);
    $wraps = app(CoverWrap::class);
    $g = podWrap($book, ['service' => 'kdp'], 300);
    [$title, $rest] = $wraps->spineLines($book, $g);

    expect($title)->toBe('The Long Field')
        ->and($rest)->toBe('')
        ->and($wraps->backCopy($book))->not->toContain('Writer')
        ->and(app(CoverRenderer::class)->settings($book)['author'])->toBe('');

    // And it still renders.
    expect($wraps->pdf($book, $g))->toStartWith('%PDF');
});

it('says a series named for its publisher once on the spine', function () {
    // "Écrivain Prépa 15 · Écrivain Prépa": the series is the publisher.
    $book = podBook(attributes: [
        'author_name' => null,
        'series_name' => 'Écrivain Prépa',
        'series_number' => 15,
        'publication' => ['publisher' => 'Ecrivain Prépa'],
    ]);
    [, $rest] = app(CoverWrap::class)->spineLines($book, podWrap($book, ['service' => 'kdp'], 300));

    expect($rest)->toBe('Écrivain Prépa · 15');
});

it('takes a chosen spine colour, which rebuilds the wrap, and refuses one that is not a colour', function () {
    $user = User::factory()->create();
    $book = podBook($user);
    $wraps = app(CoverWrap::class);

    expect($wraps->options($book, ['service' => 'kdp']))->not->toHaveKey('spine_color');

    $this->actingAs($user)->patchJson("/books/{$book->id}/print-kit", ['spine_color' => '#06243E'])
        ->assertOk()
        ->assertJsonPath('print.spine_color', '#06243E');
    $this->actingAs($user)->patchJson("/books/{$book->id}/print-kit", ['spine_color' => 'navy'])->assertStatus(422);

    // Part of the wrap's options, so a wrap made before the change is not reused.
    expect($wraps->options($book->fresh(), ['service' => 'kdp'])['spine_color'])->toBe('#06243e');

    // The spine is drawn in it: sample the middle of the spine.
    $book = $book->fresh();
    $g = podWrap($book, ['service' => 'kdp'], 300);
    $image = imagecreatefromstring($wraps->preview($book, $g, 48));
    $dpi = imagesx($image) / $g['width'];
    $rgb = imagecolorat($image, (int) (($g['spine_x'] + $g['spine'] / 2) * $dpi), (int) (0.5 * $dpi));
    [$r, $gr, $b] = [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];

    expect(abs($r - 6) + abs($gr - 36) + abs($b - 62))->toBeLessThan(24);

    // And back to matching the front.
    $this->actingAs($user)->patchJson("/books/{$book->id}/print-kit", ['spine_color' => null])
        ->assertOk()
        ->assertJsonPath('print.spine_color', null);
});

/* --------------------------------------------------------- the back cover */

it('builds the back from the description, which is its back-cover copy', function () {
    $book = podBook();
    $wraps = app(CoverWrap::class);

    expect($wraps->backCopy($book))->toStartWith('A clear and useful description');

    // The book's own description comes first, as in the anatomy.
    $book->update(['description' => 'Written for the back.']);

    expect($wraps->backCopy($book->fresh()))->toBe('Written for the back.')
        ->and($wraps->copyFits($book->fresh(), podWrap($book, ['service' => 'kdp'], 300))['fits'])->toBeTrue();

    // Too much copy for the back at a readable size is reported, not cut silently.
    $book->update(['description' => str_repeat('A sentence that goes on for a while. ', 400)]);
    expect($wraps->copyFits($book->fresh(), podWrap($book, ['service' => 'kdp'], 300))['fits'])->toBeFalse();
});

it('says when a book has no back-cover copy, and still makes the cover', function () {
    $book = podBook(attributes: ['meta' => ['category' => 'Fiction / Literary']]);
    $wraps = app(CoverWrap::class);

    expect($wraps->backCopy($book))->toBe('');
    expect(collect(app(PrintCheck::class)->for($book, 'kdp')['items'])->firstWhere('key', 'back')['status'])->toBe('warn');
    expect($wraps->pdf($book, podWrap($book, ['service' => 'kdp'], 120)))->toStartWith('%PDF');
});

it('uses an uploaded back cover when the book has one', function () {
    $book = podBook();
    $image = imagecreatetruecolor(600, 900);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 30, 30));
    ob_start();
    imagejpeg($image);
    Storage::disk('files')->put("covers/book-{$book->id}/back.jpg", ob_get_clean());
    $book->update(['cover' => ['back_artwork' => "covers/book-{$book->id}/back.jpg"]]);

    $wraps = app(CoverWrap::class);
    $g = podWrap($book->fresh(), ['service' => 'kdp'], 300);
    $preview = imagecreatefromstring($wraps->preview($book->fresh(), $g, 30));
    $rgb = imagecolorat($preview, (int) (1.5 * 30), (int) (4 * 30));

    // The back panel shows the upload, red, not a designed ground.
    expect(($rgb >> 16) & 0xFF)->toBeGreaterThan(150)
        ->and(($rgb >> 8) & 0xFF)->toBeLessThan(80);

    $back = collect(app(PrintCheck::class)->for($book->fresh(), 'kdp')['items'])->firstWhere('key', 'back');
    expect($back['status'])->toBe('warn')->and($back['fix'])->toContain('barcode');
});

/* ----------------------------------------------------------- print checks */

it('fails narrow margins on a long book at KDP, with the fix', function () {
    $book = podBook(attributes: ['layout' => ['margins' => 'Narrow']]);
    typesetAt($book, 400);

    $margins = collect(app(PrintCheck::class)->for($book, 'kdp')['items'])->firstWhere('key', 'margins');

    // 301-500 pages need 0.625 in inside; Narrow is 0.6.
    expect($margins['status'])->toBe('fail')
        ->and($margins['message'])->toContain('0.625')
        ->and($margins['fix'])->toBe('Switch margins to Standard in Design.');

    // The same margins pass at IngramSpark's 0.5 in.
    expect(collect(app(PrintCheck::class)->for($book, 'ingram')['items'])->firstWhere('key', 'margins')['status'])->toBe('pass');
});

it('sends a very long book to Wide margins, and only warns on an estimate', function () {
    $book = podBook();
    typesetAt($book, 760);
    $margins = collect(app(PrintCheck::class)->for($book, 'kdp')['items'])->firstWhere('key', 'margins');
    expect($margins['status'])->toBe('fail')->and($margins['fix'])->toBe('Switch margins to Wide in Design.');

    // An estimate is not a fact: the same rule only warns.
    $estimated = podBook(attributes: ['layout' => ['margins' => 'Narrow']]);
    $estimated->forceFill(['word_count' => 160_000])->save();
    $result = app(PrintCheck::class)->for($estimated->fresh(), 'kdp');
    expect($result['pages']['exact'])->toBeFalse()
        ->and(collect($result['items'])->firstWhere('key', 'margins')['status'])->toBe('warn');
});

it('checks page limits by paper and trim', function () {
    $cream = podBook();
    $cream->print = ['paper' => 'cream'];
    $cream->save();
    typesetAt($cream->fresh(), 800);
    expect(collect(app(PrintCheck::class)->for($cream->fresh(), 'kdp')['items'])->firstWhere('key', 'pages')['status'])->toBe('fail');

    $letter = podBook(attributes: ['layout' => ['trim' => '8.5 x 11 in', 'margins' => 'Wide']]);
    typesetAt($letter, 600);
    $pages = collect(app(PrintCheck::class)->for($letter, 'kdp')['items'])->firstWhere('key', 'pages');
    expect($pages['status'])->toBe('fail')->and($pages['message'])->toContain('24–590');

    $short = podBook();
    typesetAt($short, 120);
    expect(app(PrintCheck::class)->for($short, 'kdp')['status'])->not->toBe('fail');
});

it('uses a typeset count only while the book is unchanged', function () {
    $book = podBook();
    typesetAt($book, 222);
    expect(PrintPages::for($book)['pages'])->toBe(222)->and(PrintPages::for($book)['exact'])->toBeTrue();

    $book->update(['layout' => ['trim' => '5 x 8 in']]);
    expect(PrintPages::for($book->fresh())['exact'])->toBeFalse();
});

it('flags pictures that print below 300 dpi', function () {
    $book = podBook();
    $small = imagecreatetruecolor(400, 300);
    ob_start();
    imagepng($small);
    Storage::disk('files')->put("images/book-{$book->id}/abcdefgh12.png", ob_get_clean());
    ManuscriptSection::factory()->for($book)->create([
        'title' => 'Pictures', 'type' => 'chapter', 'position' => 10,
        'content' => ['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['src' => "/books/{$book->id}/images/abcdefgh12.png"]]]],
    ]);

    $images = collect(app(PrintCheck::class)->for($book->fresh(), 'kdp')['items'])->firstWhere('key', 'images');
    expect($images['status'])->toBe('warn')->and($images['message'])->toContain('1 picture');
});

it('says KDP can assign a free ISBN and IngramSpark needs your own', function () {
    $book = podBook();
    $kdp = collect(app(PrintCheck::class)->for($book, 'kdp')['items'])->firstWhere('key', 'isbn');
    $ingram = collect(app(PrintCheck::class)->for($book, 'ingram')['items'])->firstWhere('key', 'isbn');

    expect($kdp['status'])->toBe('pass')->and($kdp['message'])->toContain('only be used on KDP')
        ->and($ingram['status'])->toBe('warn');
});

it('blocks Prepare for stores when a print rule fails for a chosen print store', function () {
    $user = User::factory()->create();
    $book = podBook($user, ['layout' => ['margins' => 'Narrow']]);
    $book->forceFill(['word_count' => 20_000])->save();
    $book->channels()->create(['channel' => 'kdp_paperback', 'format' => 'print']);
    typesetAt($book->fresh(), 420);

    $this->actingAs($user)->postJson("/books/{$book->id}/submit")
        ->assertStatus(422)
        ->assertJsonPath('errors.print_kdp_margins', fn ($message) => str_contains($message, 'Switch margins to Standard'));

    $book->update(['layout' => ['margins' => 'Standard']]);
    typesetAt($book->fresh(), 420);
    $this->actingAs($user)->postJson("/books/{$book->id}/submit")->assertOk();
});

/* ------------------------------------------------------------- calculator */

it('works out KDP paperback print cost, royalty and the lowest price', function () {
    // 300 pages, black ink, regular trim: $1.00 + 300 x $0.012 = $4.60.
    $r = Royalties::kdpPaperback(14.99, 300, 'bw', '6 x 9 in');
    expect($r['print_cost'])->toBe(4.6)
        ->and($r['rate'])->toBe(0.6)
        ->and($r['royalty'])->toBe(round(0.6 * 14.99 - 4.60, 2))
        ->and($r['expanded_royalty'])->toBe(round(0.4 * 14.99 - 4.60, 2))
        // $4.60 / 50% = $9.20, under the $9.99 threshold.
        ->and($r['minimum_price'])->toBe(9.2);

    // Below $9.99 the rate is 50%.
    expect(Royalties::kdpPaperback(9.5, 300, 'bw', '6 x 9 in')['rate'])->toBe(0.5);

    // 7 x 10 is a large trim: $1.00 + 300 x $0.017 = $6.10; $6.10 / 50%
    // is past the threshold, so the minimum is $6.10 / 60% = $10.17.
    $large = Royalties::kdpPaperback(null, 300, 'bw', '7 x 10 in');
    expect($large['print_cost'])->toBe(6.1)->and($large['minimum_price'])->toBe(10.17);

    // Short books pay the flat cost.
    expect(Royalties::kdpPaperback(null, 100, 'bw', '6 x 9 in')['print_cost'])->toBe(2.3);
});

it('works out Kindle royalties inside and outside the 70% band', function () {
    // $9.99, 2 MB: 70% x ($9.99 - 2 x $0.15) = $6.78.
    expect(Royalties::kindle(9.99, 2.0)['royalty'])->toBe(6.78);
    // The band runs to $12.99 since July 2026.
    expect(Royalties::kindle(12.99, 1.0)['rate'])->toBe(0.7);
    // Above it: 35% of list.
    $high = Royalties::kindle(20.00, 1.0);
    expect($high['rate'])->toBe(0.35)->and($high['royalty'])->toBe(7.0)->and($high['problem'])->toContain('35%');
    // Not 20% under the paperback: 35%.
    expect(Royalties::kindle(9.00, 1.0, 10.00)['rate'])->toBe(0.35);
    // Under the minimum for the file size.
    expect(Royalties::kindle(0.99, 5.0)['problem'])->toContain('$1.99');
});

it('works out Apple, Kobo, Google and IngramSpark from their published terms', function () {
    expect(Royalties::ebookStore('apple_books', 4.99)['royalty'])->toBe(round(0.7 * 4.99, 2))
        ->and(Royalties::ebookStore('kobo', 1.99)['rate'])->toBe(0.45)
        ->and(Royalties::ebookStore('kobo', 4.99)['rate'])->toBe(0.7)
        ->and(Royalties::ebookStore('google_play', 4.99)['rate'])->toBe(0.7);

    $ingram = Royalties::ingramPrint(20.00, 0.55);
    expect($ingram['wholesale'])->toBe(9.0)->and($ingram['royalty'])->toBeNull()
        ->and(Royalties::ingramPrint(20.00, 0.10)['discount'])->toBe(0.4);
});

it('takes every number from config/publishing.php', function () {
    config(['publishing.print.kdp.print_cost.bw.regular.per_page' => 0.02]);
    expect(PrintSpecs::printCost('kdp', 300, 'bw', '6 x 9 in'))->toBe(7.0);

    config(['publishing.print.kdp.spine_per_page.white' => 0.003]);
    expect(PrintSpecs::spineWidth('kdp', 100, 'white'))->toBe(0.3);

    config(['publishing.print.kdp.gutter' => [[PHP_INT_MAX, 0.7]]]);
    expect(PrintSpecs::requiredGutter('kdp', 50))->toBe(0.7);

    config(['publishing.ebook.amazon_kdp.band' => [2.99, 9.99]]);
    expect(Royalties::kindle(12.99, 1.0)['rate'])->toBe(0.35);
});

it('reports per-copy store terms from the same rates', function () {
    $user = User::factory()->create();
    $book = podBook($user, ['list_price' => 20.00, 'meta' => ['print_price' => 15.00]]);
    $book->channels()->create(['channel' => 'amazon_kdp', 'format' => 'ebook']);
    $book->channels()->create(['channel' => 'ingram_print', 'format' => 'print']);

    $channels = collect($this->actingAs($user)->getJson("/books/{$book->id}/sales")->json('channels'))->keyBy('channel');

    expect($channels['amazon_kdp']['rate'])->toBe(0.35)
        ->and((float) $channels['amazon_kdp']['per_copy'])->toBe(7.0)
        ->and($channels['ingram_print']['per_copy'])->toBeNull()
        ->and($channels['ingram_print']['note'])->toContain('Print & Ship Calculator');
});

/* ------------------------------------------------------- store readiness */

it('lists what each ebook store still needs', function () {
    $book = podBook(attributes: ['meta' => [
        'description' => str_repeat('Words for readers. ', 10),
        'category' => 'Fiction / Literary',
        'keywords' => 'a, b, c, d, e, f, g, h',
    ]]);
    $stores = collect(app(StoreReadiness::class)->all($book))->keyBy('channel');
    $kdp = collect($stores['amazon_kdp']['items'])->keyBy('key');

    expect($kdp['keywords']['status'])->toBe('warn')
        ->and($kdp['epub']['status'])->toBe('warn')
        ->and($kdp['cover']['status'])->toBe('pass')
        ->and(collect($stores['apple_books']['items'])->firstWhere('key', 'cover')['message'])->toContain('1400');

    $book->exports()->create(['format' => 'epub', 'status' => 'ready', 'path' => 'exports/x.epub']);
    expect(collect(app(StoreReadiness::class)->for($book, 'kobo', true)['items'])->firstWhere('key', 'epub')['status'])->toBe('pass');
});

/* --------------------------------------------------------- HTTP and jobs */

it('makes the cover wrap on the worker, sized from the typeset interior', function () {
    Queue::fake();
    $user = User::factory()->create();
    $book = podBook($user);

    $this->actingAs($user)->postJson("/books/{$book->id}/exports", ['format' => 'pdf_interior'])->assertStatus(202);
    $this->actingAs($user)->postJson("/books/{$book->id}/exports", ['format' => 'cover_wrap', 'options' => ['service' => 'kdp', 'paper' => 'cream']])
        ->assertStatus(202)
        ->assertJsonPath('export.label', 'Print cover (full wrap) · Amazon KDP, cream paper');

    Queue::assertPushed(GenerateExport::class, 2);

    $interior = $book->exports()->where('format', 'pdf_interior')->first();
    (new GenerateExport($interior->id))->handle(app(Exporter::class));
    $interior->refresh();

    expect($interior->status)->toBe('ready')
        ->and($interior->pages)->toBeGreaterThan(4)
        ->and(Storage::disk('files')->get($interior->path))->toStartWith('%PDF');

    $cover = $book->exports()->where('format', 'cover_wrap')->first();
    (new GenerateExport($cover->id))->handle(app(Exporter::class));
    $cover->refresh();

    $spine = PrintSpecs::evenPages($interior->pages) * 0.0025;
    [$w, $h] = mediaBox(Storage::disk('files')->get($cover->path));

    expect($cover->status)->toBe('ready')
        ->and($cover->pages)->toBe($interior->pages)
        ->and($cover->options['service'])->toBe('kdp')
        ->and($cover->path)->toContain('-cover-kdp-')
        ->and($w)->toEqualWithDelta((12 + $spine + 0.25) * 72, 0.05)
        ->and($h)->toEqualWithDelta(9.25 * 72, 0.01);

    $this->actingAs($user)->get("/books/{$book->id}/exports/{$cover->id}/download")->assertOk();
});

it('refuses a cover that cannot be made before taking a worker', function () {
    Queue::fake();
    $user = User::factory()->create();
    $book = podBook($user);

    $this->actingAs($user)->postJson("/books/{$book->id}/exports", ['format' => 'cover_wrap', 'options' => ['service' => 'ingram']])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'cover template generator'));

    Queue::assertNothingPushed();
});

it('serves the print kit, its prices and a cover preview to the author', function () {
    $user = User::factory()->create();
    $book = podBook($user);

    $kit = $this->actingAs($user)->getJson("/books/{$book->id}/print-kit")->assertOk();
    expect(collect($kit->json('checks'))->pluck('service')->all())->toBe(['kdp', 'ingram'])
        ->and(collect($kit->json('services'))->firstWhere('service', 'ingram')['spine_entered'])->toBeTrue()
        ->and($kit->json('checked'))->toBe('2026-10-23');

    $this->actingAs($user)->patchJson("/books/{$book->id}/print-kit", ['paper' => 'cream', 'finish' => 'glossy'])
        ->assertOk()
        ->assertJsonPath('print.paper', 'cream')
        ->assertJsonPath('print.finish', 'glossy');
    $this->actingAs($user)->patchJson("/books/{$book->id}/print-kit", ['paper' => 'pink'])->assertStatus(422);

    $prices = $this->actingAs($user)->getJson("/books/{$book->id}/print-kit/prices?ebook=4.99&print=12.99")->assertOk();
    expect($prices->json('ebook.0.rate'))->toBe(0.7);

    $preview = $this->actingAs($user)->get("/books/{$book->id}/print-kit/cover-preview?service=kdp")->assertOk();
    expect($preview->headers->get('Content-Type'))->toBe('image/jpeg');
    $this->actingAs($user)->getJson("/books/{$book->id}/print-kit/cover-preview?service=ingram")->assertStatus(422);
});

it('keeps the print kit to the book\'s author', function () {
    $book = podBook();
    $other = User::factory()->create();

    $this->actingAs($other)->getJson("/books/{$book->id}/print-kit")->assertForbidden();
    $this->actingAs($other)->patchJson("/books/{$book->id}/print-kit", ['paper' => 'cream'])->assertForbidden();
    $this->actingAs($other)->getJson("/books/{$book->id}/print-kit/prices")->assertForbidden();
    $this->actingAs($other)->get("/books/{$book->id}/print-kit/cover-preview?service=kdp")->assertForbidden();
    $this->actingAs($other)->postJson("/books/{$book->id}/exports", ['format' => 'cover_wrap', 'options' => ['service' => 'kdp']])->assertForbidden();
});

it('takes a back cover upload and removes it again', function () {
    $user = User::factory()->create();
    $book = podBook($user);
    $file = UploadedFile::fake()->image('back.jpg', 600, 900);

    $this->actingAs($user)->post("/books/{$book->id}/print-kit/back", ['back' => $file], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('back.uploaded', true);

    $path = $book->fresh()->cover['back_artwork'];
    expect(Storage::disk('files')->exists($path))->toBeTrue();

    $svg = UploadedFile::fake()->createWithContent('back.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
    $this->actingAs($user)->post("/books/{$book->id}/print-kit/back", ['back' => $svg], ['Accept' => 'application/json'])->assertStatus(422);

    $this->actingAs($user)->deleteJson("/books/{$book->id}/print-kit/back")->assertOk()->assertJsonPath('back.uploaded', false);
    expect(Storage::disk('files')->exists($path))->toBeFalse();

    $this->actingAs(User::factory()->create())->deleteJson("/books/{$book->id}/print-kit/back")->assertForbidden();
});
