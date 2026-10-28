<?php

use App\Covers\CoverImages;
use App\Covers\CoverLayers;
use App\Covers\CoverText;
use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\CoverRenderer;
use App\Publishing\CoverWrap;
use App\Publishing\Publish\Freshness;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * The print wrap's back and spine as layers, image layers on every panel,
 * the spine's reading direction and the artwork's framing
 * (ADR "Editable back cover, spine and image layers").
 */
beforeEach(function () {
    Storage::fake('files');
});

function wrapBook(array $attributes = [], ?User $owner = null): Book
{
    $owner ??= User::factory()->create();
    $book = $owner->books()->create(array_merge([
        'title' => 'The Long Field',
        'author_name' => 'A Writer',
        'language' => 'English (US)',
        'description' => 'A clear and useful description of the book. It runs on for a while so that it wraps.',
        'publication' => ['imprint' => 'Maison Claire'],
    ], $attributes));

    ManuscriptSection::factory()->for($book)->create([
        'title' => 'Chapter One', 'type' => 'chapter', 'position' => 0,
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Prose.']]]]],
    ]);

    return $book;
}

function wrapGeometry(Book $book, int $pages = 300, string $service = 'kdp'): array
{
    $wraps = app(CoverWrap::class);

    return $wraps->geometry($book, $wraps->options($book, ['service' => $service, 'paper' => 'white']), $pages);
}

/** A PNG of one colour (or two halves), as bytes. */
function pngBytes(int $w, int $h, array $rgb = [255, 0, 0], ?array $right = null): string
{
    $image = imagecreatetruecolor($w, $h);
    imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));

    if ($right) {
        imagefilledrectangle($image, intdiv($w, 2), 0, $w - 1, $h - 1, imagecolorallocate($image, ...$right));
    }

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function addPicture(Book $book, int $w = 900, int $h = 600, array $rgb = [255, 0, 0]): string
{
    $entry = CoverImages::store($book, UploadedFile::fake()->createWithContent('logo.png', pngBytes($w, $h, $rgb)));
    $book->update(['cover' => array_merge($book->cover ?? [], ['images' => [...CoverImages::all($book), $entry]])]);

    return $entry['id'];
}

/** Ink box of pixels matching $is, scanning every pixel of a region. */
function wrapInk($image, callable $is, ?array $region = null): ?array
{
    [$x0, $y0, $x1, $y1] = $region ?? [0, 0, imagesx($image) - 1, imagesy($image) - 1];
    $box = null;

    for ($y = $y0; $y <= $y1; $y++) {
        for ($x = $x0; $x <= $x1; $x++) {
            $c = imagecolorat($image, $x, $y);

            if ($is(($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF)) {
                $box = $box === null ? [$x, $y, $x, $y] : [min($box[0], $x), min($box[1], $y), max($box[2], $x), max($box[3], $y)];
            }
        }
    }

    return $box;
}

$red = fn (int $r, int $g, int $b) => $r > 180 && $g < 90 && $b < 90;

/* ---------------------------------------------------------------- schema */

it('takes back sources on the back and refuses them on the front', function () {
    $isbn = CoverLayers::make('isbn', ['size' => 0.02]);

    expect(CoverLayers::validate(['version' => 1, 'items' => [$isbn]], 'back')['items'][0]['source'])->toBe('isbn');
    expect(fn () => CoverLayers::validate(['version' => 1, 'items' => [$isbn]]))->toThrow(ValidationException::class);
    expect(fn () => CoverLayers::validate(['version' => 1, 'items' => [CoverLayers::make('description')]], 'spine'))
        ->toThrow(ValidationException::class);
});

it('checks spine offsets and sizes against the spine\'s own ranges', function () {
    $ok = CoverLayers::make('title', ['y' => -0.01, 'size' => 0.01, 'x' => 0.05, 'width' => 0.5]);
    expect(CoverLayers::validate(['version' => 1, 'items' => [$ok]], 'spine')['items'][0]['y'])->toBe(-0.01);

    // A negative y is the front's error; a size past 0.05 the spine's.
    expect(fn () => CoverLayers::validate(['version' => 1, 'items' => [$ok]]))->toThrow(ValidationException::class);
    expect(fn () => CoverLayers::validate(['version' => 1, 'items' => [array_merge($ok, ['size' => 0.06])]], 'spine'))
        ->toThrow(ValidationException::class);
});

it('validates image layers: a picture the book has, a box inside the panel, a crop inside the picture', function () {
    $layer = CoverLayers::makeImage('imgabc', ['x' => 0.1, 'y' => 0.1, 'width' => 0.2, 'height' => 0.1]);
    $doc = fn (array $l) => ['version' => 1, 'items' => [$l]];

    expect(CoverLayers::validate($doc($layer), 'back', ['imgabc'])['items'][0])->toMatchArray(['source' => 'image', 'image' => 'imgabc', 'lock' => true]);

    $refused = [
        'unknown picture' => [$layer, ['other']],
        'outside the panel' => [array_merge($layer, ['x' => 0.9, 'width' => 0.2]), ['imgabc']],
        'crop outside' => [array_merge($layer, ['crop' => ['x' => 0.5, 'y' => 0, 'width' => 0.6, 'height' => 1]]), ['imgabc']],
        'unknown key' => [array_merge($layer, ['font' => 'lora']), ['imgabc']],
    ];

    foreach ($refused as $why => [$l, $ids]) {
        expect(fn () => CoverLayers::validate($doc($l), 'front', $ids))->toThrow(ValidationException::class, null, $why);
    }
});

/* ------------------------------------------------------- the back as layers */

it('converts the generated back into equivalent layers, placed where it drew them', function () {
    $book = wrapBook();
    $wraps = app(CoverWrap::class);
    $g = wrapGeometry($book);

    $doc = $wraps->backLayersFromGenerated($book, $g);
    $layers = collect($doc['items'])->keyBy('source');
    $panelW = $g['trim'][0] + $g['bleed'];

    expect($layers->keys()->all())->toBe(['title', 'description', 'publisher']);

    // The copy area starts 0.5 in inside the trim.
    expect($layers['title']['x'] * $panelW)->toEqualWithDelta($g['bleed'] + 0.5, 1e-6)
        ->and($layers['title']['size'] * $g['height'] * 72)->toEqualWithDelta(20, 1e-6)
        ->and($layers['description']['y'])->toBeGreaterThan($layers['title']['y'])
        ->and($layers['publisher']['size'] * $g['height'] * 72)->toEqualWithDelta(10, 1e-6);

    // The publisher's baseline is where the generated back sets it.
    $surface = $wraps->surfaces($g)['back'];
    $set = CoverText::layout($layers['publisher'], 'Maison Claire', $surface['width'], $surface['height']);
    expect($set['lines'][0]['baseline'] / 300)->toEqualWithDelta($g['height'] - $g['bleed'] - 0.5, 0.01);
});

it('never re-fits the back copy as it changes: the box and its size stay put', function () {
    $book = wrapBook();
    $wraps = app(CoverWrap::class);
    $g = wrapGeometry($book);
    $book->update(['cover' => ['back_layers' => $wraps->backLayersFromGenerated($book, $g)]]);
    $layer = collect(CoverLayers::stored($book, 'back')['items'])->firstWhere('source', 'description');

    $surface = $wraps->surfaces($g)['back'];
    $short = CoverRenderer::place($layer, $surface, CoverLayers::wrapTexts($book->fresh()));
    $book->update(['description' => str_repeat('Much more copy than before, typed in one go. ', 80)]);
    $long = CoverRenderer::place($layer, $surface, CoverLayers::wrapTexts($book->fresh()));

    expect($long['size'])->toBe($short['size'])
        ->and($long['box']['y'])->toBe($short['box']['y'])
        ->and($long['lines'][0]['baseline'])->toBe($short['lines'][0]['baseline'])
        ->and(count($long['lines']))->toBeGreaterThan(count($short['lines']));

    // The generated back would have shrunk the copy instead.
    expect($wraps->copyFits($book->fresh(), $g)['size'])->toBeLessThan(12);
});

it('draws back layers where the model puts them, within 2%', function () use ($red) {
    $book = wrapBook();
    $wraps = app(CoverWrap::class);
    $g = wrapGeometry($book);
    $layer = CoverLayers::make('custom', ['text' => 'BACK', 'x' => 0.2, 'y' => 0.3, 'width' => 0.6, 'size' => 0.04, 'color' => '#ff0000', 'align' => 'left']);
    $book->update(['cover' => ['back_layers' => ['version' => 1, 'items' => [$layer]]]]);

    $dpi = 60;
    $preview = imagecreatefromstring($wraps->preview($book->fresh(), $g, $dpi));
    $set = CoverText::layout(CoverLayers::normalise($layer), 'BACK', ...array_values($wraps->surfaces($g)['back']));
    $ink = wrapInk($preview, $red);
    $scale = $dpi / 300;
    $tol = 0.02 * imagesx($preview);

    expect($ink)->not->toBeNull()
        ->and($ink[0])->toEqualWithDelta($set['lines'][0]['x'] * $scale, $tol)
        ->and($ink[2])->toEqualWithDelta(($set['lines'][0]['x'] + $set['lines'][0]['width']) * $scale, $tol)
        ->and($ink[3])->toEqualWithDelta($set['lines'][0]['baseline'] * $scale, 0.02 * imagesy($preview));
});

it('keeps the barcode box clear: a warning, and white over any layer', function () use ($red) {
    $book = wrapBook();
    $wraps = app(CoverWrap::class);
    $g = wrapGeometry($book);
    $bar = $g['barcode'];
    $panelW = $g['trim'][0] + $g['bleed'];

    // A red panel right over the barcode box.
    $layer = CoverLayers::make('custom', [
        'id' => 'over', 'text' => '', 'x' => ($bar['x'] - 0.2) / $panelW, 'y' => ($bar['y'] - 0.2) / $g['height'],
        'width' => ($bar['width'] + 0.4) / $panelW, 'height' => ($bar['height'] + 0.4) / $g['height'],
        'background' => ['color' => '#ff0000', 'opacity' => 1, 'padding' => 0],
    ]);
    $book->update(['cover' => ['back_layers' => ['version' => 1, 'items' => [$layer]]]]);
    $book = $book->fresh();

    $warning = collect($wraps->warnings($book, $g))->firstWhere('kind', 'barcode');
    expect($warning['layer'])->toBe('over');

    $dpi = 50;
    $preview = imagecreatefromstring($wraps->preview($book, $g, $dpi));
    $mid = imagecolorat($preview, (int) (($bar['x'] + $bar['width'] / 2) * $dpi), (int) (($bar['y'] + $bar['height'] / 2) * $dpi));
    expect(($mid >> 16) & 0xFF)->toBeGreaterThan(240)->and(($mid >> 8) & 0xFF)->toBeGreaterThan(240);

    // The red still shows around it.
    expect(wrapInk($preview, $red))->not->toBeNull();

    // Moved clear, no warning.
    $book->update(['cover' => ['back_layers' => ['version' => 1, 'items' => [array_merge($layer, ['y' => 0.2])]]]]);
    expect(collect($wraps->warnings($book->fresh(), $g))->where('kind', 'barcode'))->toBeEmpty();
});

it('keeps an uploaded back, with layers on top when the author adds them', function () use ($red) {
    $book = wrapBook();
    Storage::disk('files')->put("covers/book-{$book->id}/back.png", pngBytes(600, 900, [20, 160, 20]));
    $layer = CoverLayers::make('custom', ['text' => '', 'x' => 0.3, 'y' => 0.3, 'width' => 0.2, 'height' => 0.1,
        'background' => ['color' => '#ff0000', 'opacity' => 1, 'padding' => 0]]);
    $book->update(['cover' => ['back_artwork' => "covers/book-{$book->id}/back.png", 'back_layers' => ['version' => 1, 'items' => [$layer]]]]);
    $wraps = app(CoverWrap::class);
    $g = wrapGeometry($book->fresh());

    $preview = imagecreatefromstring($wraps->preview($book->fresh(), $g, 40));
    $green = imagecolorat($preview, (int) (0.5 * 40), (int) (8 * 40));

    expect(($green >> 8) & 0xFF)->toBeGreaterThan(120)
        ->and(wrapInk($preview, $red))->not->toBeNull();
});

/* ------------------------------------------------------------- the spine */

it('suggests the spine direction by language: bottom to top for French and Kreyòl', function () {
    $wraps = app(CoverWrap::class);

    expect(CoverWrap::languageDirection('French'))->toBe('btt')
        ->and(CoverWrap::languageDirection('Haitian Creole'))->toBe('btt')
        ->and(CoverWrap::languageDirection('English (US)'))->toBe('ttb')
        ->and(CoverWrap::languageDirection('English (UK)'))->toBe('ttb')
        ->and(CoverWrap::languageDirection(null))->toBe('ttb');

    // Suggested, not imposed: an untouched French spine still reads top
    // to bottom, as every wrap did, until the author customises it.
    $french = wrapBook(['language' => 'French']);
    expect($wraps->direction($french))->toBe(['direction' => 'ttb', 'chosen' => null, 'suggested' => 'btt']);

    $this->actingAs($french->user)->postJson("/books/{$french->id}/cover/spine-layers", ['service' => 'kdp'])
        ->assertOk()
        ->assertJsonPath('spine.direction', 'btt');

    expect($french->fresh()->cover['spine_direction'])->toBe('btt');

    // And the author can turn it either way.
    $this->actingAs($french->user)->patchJson("/books/{$french->id}/cover", ['spine_direction' => 'ttb'])->assertOk();
    expect($wraps->direction($french->fresh())['direction'])->toBe('ttb');
    $this->actingAs($french->user)->patchJson("/books/{$french->id}/cover", ['spine_direction' => 'sideways'])->assertStatus(422);
});

it('sets spine layers in the reading frame, rotated each way onto the sheet, within 2%', function (string $direction) use ($red) {
    $book = wrapBook();
    $wraps = app(CoverWrap::class);
    $g = wrapGeometry($book, 400);
    $layer = CoverLayers::make('custom', ['text' => 'SPINE', 'x' => 0.1, 'y' => 0, 'width' => 0.4, 'size' => 0.02, 'color' => '#ff0000', 'align' => 'left']);
    $book->update(['cover' => ['spine_direction' => $direction, 'spine_layers' => ['version' => 1, 'items' => [$layer]]]]);
    $book = $book->fresh();

    $dpi = 100;
    $preview = imagecreatefromstring($wraps->preview($book, $g, $dpi));
    $surface = $wraps->surfaces($g)['spine'];
    $set = CoverText::spine(CoverLayers::validate(['version' => 1, 'items' => [$layer]], 'spine')['items'][0], 'SPINE', $surface['width'], $surface['height']);
    $line = $set['lines'][0];
    $s = $dpi / 300;
    $point = fn ($u, $v) => CoverText::spinePoint($u * $s, $v * $s, $direction, $g['spine_x'] * $dpi, $g['spine'] * $dpi, $g['height'] * $dpi);

    // The line's start and end on the baseline, mapped onto the sheet.
    [$ax, $ay] = $point($line['x'], $line['baseline']);
    [$bx, $by] = $point($line['x'] + $line['width'], $line['baseline']);
    $ink = wrapInk($preview, $red);
    $tol = 0.02 * imagesy($preview);

    expect($ink)->not->toBeNull()
        // Along the spine: from the line's start to its end.
        ->and(min($ay, $by))->toEqualWithDelta($ink[1], $tol)
        ->and(max($ay, $by))->toEqualWithDelta($ink[3], $tol)
        // Across it: the ink lies inside the spine.
        ->and($ink[0])->toBeGreaterThanOrEqual((int) floor($g['spine_x'] * $dpi) - 1)
        ->and($ink[2])->toBeLessThanOrEqual((int) ceil(($g['spine_x'] + $g['spine']) * $dpi) + 1);

    // Top to bottom starts near the top of the sheet; bottom to top near the foot.
    $direction === 'ttb'
        ? expect($ink[1])->toBeLessThan(imagesy($preview) / 2)
        : expect($ink[3])->toBeGreaterThan(imagesy($preview) / 2);

    // The baseline is on the side the letters' feet face: the back for
    // top to bottom (the tops face the front), the front for bottom to top.
    $direction === 'ttb'
        ? expect($ax)->toBeLessThan(($ink[0] + $ink[2]) / 2 + 1)
        : expect($ax)->toBeGreaterThan(($ink[0] + $ink[2]) / 2 - 1);
})->with(['ttb', 'btt']);

it('converts the generated spine, and the auto spine honours a chosen direction', function () {
    $book = wrapBook(['series_name' => 'Fields', 'series_number' => 2]);
    $wraps = app(CoverWrap::class);
    $g = wrapGeometry($book, 300);

    $items = collect($wraps->spineLayersFromGenerated($book, $g)['items'])->keyBy('source');
    expect($items->keys()->all())->toBe(['title', 'publisher', 'author', 'series'])
        ->and($items['title']['x'] * $g['height'])->toEqualWithDelta($g['bleed'] + 0.5, 1e-6)
        // The last part ends 0.5 in from the far trim.
        ->and(($items['publisher']['x'] + $items['publisher']['width']) * $g['height'])->toEqualWithDelta($g['height'] - $g['bleed'] - 0.5, 0.01)
        ->and($items['series']['x'])->toBeLessThan($items['author']['x']);

    // No layers, direction chosen: the generated spine flips.
    $ttb = $wraps->preview($book, $g, 60);
    $book->update(['cover' => ['spine_direction' => 'btt']]);
    expect($wraps->preview($book->fresh(), $g, 60))->not->toBe($ttb);
    $book->update(['cover' => ['spine_direction' => 'ttb']]);
    expect($wraps->preview($book->fresh(), $g, 60))->toBe($ttb);
});

it('draws no spine type below the service\'s minimum page count, and says why', function () use ($red) {
    $book = wrapBook();
    $wraps = app(CoverWrap::class);
    $picture = addPicture($book, 300, 300, [255, 0, 0]);
    $g = wrapGeometry($book->fresh(), 60);

    expect($g['spine_text'])->toBeFalse();

    $text = CoverLayers::make('custom', ['text' => 'TOO THIN', 'x' => 0.1, 'width' => 0.5, 'size' => 0.01, 'color' => '#ff0000', 'y' => 0]);
    $book->update(['cover' => array_merge($book->fresh()->cover, ['spine_layers' => ['version' => 1, 'items' => [$text]]])]);
    $book = $book->fresh();

    $editor = $wraps->editor($book, $g);
    expect($editor['spine']['text'])->toBeFalse()
        ->and($editor['spine']['reason'])->toContain('79 pages')
        ->and(collect($editor['warnings'])->firstWhere('kind', 'thin'))->not->toBeNull();

    expect(wrapInk(imagecreatefromstring($wraps->preview($book, $g, 100)), $red))->toBeNull();

    // A picture still prints on a thin spine.
    $image = CoverLayers::makeImage($picture, ['x' => 0.4, 'y' => 0, 'width' => 0.01, 'height' => 0.01]);
    $book->update(['cover' => array_merge($book->cover, ['spine_layers' => ['version' => 1, 'items' => [$text, $image]]])]);
    expect(wrapInk(imagecreatefromstring($wraps->preview($book->fresh(), $g, 100)), $red))->not->toBeNull();
});

/* ---------------------------------------------------------- image layers */

it('accepts PNG and JPEG pictures and refuses SVG, GIF, oversized and undecodable ones', function () {
    $book = wrapBook();
    $user = $book->user;

    $this->actingAs($user)->postJson("/books/{$book->id}/cover/images", [
        'image' => UploadedFile::fake()->createWithContent('logo.png', pngBytes(400, 200)),
    ])->assertCreated()->assertJsonPath('image.width', 400)->assertJsonCount(1, 'images');

    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="10" height="10"/></svg>';
    $gif = imagecreatetruecolor(40, 40);
    ob_start();
    imagegif($gif);
    $gifBytes = ob_get_clean();
    // A PNG header claiming 10000 × 5000: refused before anything decodes it.
    $huge = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NN', 10000, 5000)."\x08\x02\x00\x00\x00".pack('N', 0);

    foreach (['logo.svg' => $svg, 'logo.gif' => $gifBytes, 'huge.png' => $huge, 'broken.jpg' => "\xFF\xD8\xFF\xE0garbage"] as $name => $bytes) {
        $this->actingAs($user)->postJson("/books/{$book->id}/cover/images", [
            'image' => UploadedFile::fake()->createWithContent($name, $bytes),
        ])->assertStatus(422);
    }

    expect(CoverImages::all($book->fresh()))->toHaveCount(1);

    // Another author can neither add nor see them.
    $other = User::factory()->create();
    $id = CoverImages::all($book->fresh())[0]['id'];
    $this->actingAs($other)->postJson("/books/{$book->id}/cover/images", ['image' => UploadedFile::fake()->createWithContent('a.png', pngBytes(40, 40))])->assertForbidden();
    $this->actingAs($other)->get("/books/{$book->id}/cover/images/{$id}")->assertForbidden();
    $this->actingAs($user)->get("/books/{$book->id}/cover/images/{$id}")->assertOk()->assertHeader('Content-Type', 'image/png');
});

it('warns when a picture would print below 300 dpi at its size', function () {
    $book = wrapBook();
    $wraps = app(CoverWrap::class);
    $small = addPicture($book, 200, 200);
    $large = addPicture($book->fresh(), 900, 900);
    $g = wrapGeometry($book->fresh());
    $panelW = $g['trim'][0] + $g['bleed'];

    // Each 2 in wide on the back: 100 dpi and 450 dpi.
    $book->update(['cover' => array_merge($book->fresh()->cover, ['back_layers' => ['version' => 1, 'items' => [
        CoverLayers::makeImage($small, ['id' => 'small', 'x' => 0.1, 'y' => 0.1, 'width' => 2 / $panelW, 'height' => 0.2]),
        CoverLayers::makeImage($large, ['id' => 'large', 'x' => 0.1, 'y' => 0.4, 'width' => 2 / $panelW, 'height' => 0.2]),
    ]]])]);

    $dpi = collect($wraps->warnings($book->fresh(), $g))->where('kind', 'dpi');
    expect($dpi->pluck('layer')->all())->toBe(['small'])
        ->and($dpi->first()['message'])->toContain('100 dpi');
});

it('places a picture on the front, back and spine, cropped and at its opacity', function () use ($red) {
    $book = wrapBook();
    $picture = addPicture($book, 400, 400, [255, 0, 0]);
    $book = $book->fresh();
    $renderer = app(CoverRenderer::class);

    $book->update(['cover' => array_merge($book->cover, ['layers' => ['version' => 1, 'items' => [
        CoverLayers::makeImage($picture, ['x' => 0.25, 'y' => 0.5, 'width' => 0.5, 'height' => 0.25]),
    ]]])]);
    $front = imagecreatefromstring(Storage::disk('files')->get($renderer->ensure($book->fresh())));
    $box = wrapInk($front, $red, [0, 0, 1599, 2559]);

    expect($box[0])->toEqualWithDelta(400, 4)->and($box[1])->toEqualWithDelta(1280, 4)
        ->and($box[2])->toEqualWithDelta(1199, 4)->and($box[3])->toEqualWithDelta(1919, 4);

    // At half opacity on the dark Dusk ground, it is a darker red.
    $book->update(['cover' => array_merge($book->fresh()->cover, ['layers' => ['version' => 1, 'items' => [
        CoverLayers::makeImage($picture, ['x' => 0.25, 'y' => 0.5, 'width' => 0.5, 'height' => 0.25, 'opacity' => 0.5]),
    ]]])]);
    $half = imagecreatefromstring(Storage::disk('files')->get($renderer->ensure($book->fresh())));
    $c = imagecolorat($half, 800, 1600);
    expect(($c >> 16) & 0xFF)->toBeLessThan(200)->toBeGreaterThan(110);

    // Deleting the picture removes its layers everywhere.
    $this->actingAs($book->user)->deleteJson("/books/{$book->id}/cover/images/{$picture}")->assertOk()->assertJsonCount(0, 'images');
    expect($book->fresh()->cover['layers']['items'])->toBe([]);
});

/* ------------------------------------------------------ artwork framing */

it('frames the artwork around a focal point and zoom, and keeps the centre crop by default', function () {
    $book = wrapBook();
    // Left half red, right half blue.
    Storage::disk('files')->put("covers/book-{$book->id}/art.png", pngBytes(2000, 2000, [255, 0, 0], [0, 0, 255]));
    $book->update(['cover' => ['artwork' => "covers/book-{$book->id}/art.png", 'layout' => 'finished']]);
    $renderer = app(CoverRenderer::class);
    $centre = $renderer->version($book->fresh());

    expect(CoverRenderer::framing(['x' => 0.5, 'y' => 0.5, 'zoom' => 1]))->toBeNull();

    $colourAt = function (Book $book, int $x) use ($renderer) {
        $image = imagecreatefromstring(Storage::disk('files')->get($renderer->ensure($book)));
        $c = imagecolorat($image, $x, 1280);

        return (($c >> 16) & 0xFF) > 128 ? 'red' : 'blue';
    };

    // Centre crop: red on the left, blue on the right.
    expect($colourAt($book->fresh(), 100))->toBe('red')->and($colourAt($book->fresh(), 1500))->toBe('blue');

    // Focal point at the right edge, zoomed twice: all blue.
    $this->actingAs($book->user)->patchJson("/books/{$book->id}/cover", ['framing' => ['x' => 1, 'y' => 0.5, 'zoom' => 2]])->assertOk();
    expect($renderer->version($book->fresh()))->not->toBe($centre)
        ->and($colourAt($book->fresh(), 100))->toBe('blue');

    // The crop is the sum the server uses, kept inside the picture.
    [$sx, , $w] = CoverRenderer::frame(2000, 2000, 1600, 2560, ['x' => 1, 'y' => 0.5, 'zoom' => 2]);
    expect($sx + $w)->toEqualWithDelta(2000, 1e-6)->and($w)->toEqualWithDelta(625, 1e-6);

    // Back to the default: the same cover as before framing.
    $this->actingAs($book->user)->patchJson("/books/{$book->id}/cover", ['framing' => null])->assertOk();
    expect($renderer->version($book->fresh()))->toBe($centre);

    $this->actingAs($book->user)->patchJson("/books/{$book->id}/cover", ['framing' => ['x' => 2, 'y' => 0, 'zoom' => 1]])->assertStatus(422);
});

it('fills the back with an uploaded back when asked, panned and zoomed', function () {
    $book = wrapBook();
    Storage::disk('files')->put("covers/book-{$book->id}/back.png", pngBytes(1000, 1000, [255, 0, 0], [0, 0, 255]));
    $book->update(['cover' => ['back_artwork' => "covers/book-{$book->id}/back.png"]]);
    $wraps = app(CoverWrap::class);
    $g = wrapGeometry($book->fresh());
    $dpi = 40;
    $at = fn (Book $b, float $xIn) => (imagecolorat(imagecreatefromstring($wraps->preview($b, $g, $dpi)), (int) ($xIn * $dpi), (int) (4.6 * $dpi)) >> 16) & 0xFF;

    $this->actingAs($book->user)->patchJson("/books/{$book->id}/cover", ['back_framing' => ['fit' => 'fill', 'x' => 0, 'y' => 0.5, 'zoom' => 1.5]])->assertOk();
    // Focal point at the picture's left: red across the back.
    expect($at($book->fresh(), 5.5))->toBeGreaterThan(200);

    $this->actingAs($book->user)->patchJson("/books/{$book->id}/cover", ['back_framing' => ['fit' => 'fill', 'x' => 1, 'y' => 0.5, 'zoom' => 1.5]])->assertOk();
    expect($at($book->fresh(), 0.5))->toBeLessThan(60);
});

/* --------------------------------------------- endpoints, defaults, freshness */

it('customises and resets the back and spine through the endpoints, for the author only', function () {
    $book = wrapBook();
    $user = $book->user;

    $this->actingAs($user)->getJson("/books/{$book->id}/cover/wrap?service=kdp")
        ->assertOk()
        ->assertJsonPath('layers.back', null)
        ->assertJsonPath('spine.direction', 'ttb')
        ->assertJsonPath('back.barcode.width', 600)
        ->assertJsonStructure(['sheet', 'back' => ['trim', 'safe'], 'spine' => ['safe', 'margin', 'text'], 'preview', 'ground', 'services', 'texts' => ['description', 'isbn']]);

    $this->actingAs($user)->postJson("/books/{$book->id}/cover/back-layers", ['service' => 'kdp'])
        ->assertOk()
        ->assertJsonPath('layers.back.items.1.source', 'description');

    // Saving edits goes through the cover, validated as the back.
    $doc = $book->fresh()->cover['back_layers'];
    $doc['items'][1]['y'] = 0.4;
    $this->actingAs($user)->patchJson("/books/{$book->id}/cover", ['back_layers' => $doc])->assertOk();
    expect($book->fresh()->cover['back_layers']['items'][1]['y'])->toBe(0.4);
    $doc['items'][0]['source'] = 'tagline';
    $this->actingAs($user)->patchJson("/books/{$book->id}/cover", ['back_layers' => $doc])->assertStatus(422);

    $this->actingAs($user)->deleteJson("/books/{$book->id}/cover/back-layers", ['service' => 'kdp'])->assertOk();
    expect($book->fresh()->cover)->not->toHaveKey('back_layers');

    $this->actingAs($user)->get("/books/{$book->id}/cover/wrap/image?service=kdp&bare=1&dpi=30")->assertOk()->assertHeader('Content-Type', 'image/jpeg');

    $other = User::factory()->create();
    $this->actingAs($other)->postJson("/books/{$book->id}/cover/back-layers")->assertForbidden();
    $this->actingAs($other)->getJson("/books/{$book->id}/cover/wrap")->assertForbidden();
});

it('draws an untouched book exactly as before, and the same again after customise and reset', function () {
    $book = wrapBook(['language' => 'French', 'series_name' => 'Écrivain Prépa', 'series_number' => 3]);
    $wraps = app(CoverWrap::class);
    $g = wrapGeometry($book, 300);
    $before = $wraps->preview($book, $g, 60);

    $this->actingAs($book->user)->postJson("/books/{$book->id}/cover/back-layers", ['service' => 'kdp'])->assertOk();
    expect($wraps->preview($book->fresh(), $g, 60))->not->toBe($before);

    $this->actingAs($book->user)->deleteJson("/books/{$book->id}/cover/back-layers")->assertOk();
    expect($wraps->preview($book->fresh(), $g, 60))->toBe($before)
        ->and($wraps->pdf($book->fresh(), $g))->toStartWith('%PDF');
});

it('makes a wrap stale when its cover changes, or when it was drawn by the older renderer', function () {
    $book = wrapBook();
    pfFile($book, 'cover_wrap', ['service' => 'kdp', 'paper' => 'white']);

    expect(Freshness::file($book, 'cover_wrap', 'kdp')['fresh'])->toBeTrue();

    $book->update(['cover' => ['spine_direction' => 'btt']]);
    $stale = Freshness::file($book->fresh(), 'cover_wrap', 'kdp');
    expect($stale['fresh'])->toBeFalse()->and($stale['reason'])->toContain('cover design');

    // A wrap made before this version recorded no cover at all.
    $old = $book->exports()->where('format', 'cover_wrap')->latest('id')->first();
    $options = $old->options;
    unset($options['cover']);
    $old->forceFill(['options' => $options])->save();
    $book->update(['cover' => []]);
    expect(Freshness::file($book->fresh(), 'cover_wrap', 'kdp')['fresh'])->toBeFalse();
});
