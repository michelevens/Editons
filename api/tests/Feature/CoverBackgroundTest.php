<?php

use App\Covers\CoverBackground;
use App\Covers\CoverContrast;
use App\Covers\CoverImages;
use App\Covers\CoverLayers;
use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\AccessibilityAudit;
use App\Publishing\CoverRenderer;
use App\Publishing\CoverWrap;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Cover backgrounds: any colour or gradient on the front, back, spine or
 * across the whole wrap; one picture across the wrap; contrast per text
 * layer (ADR — Cover backgrounds and series templates).
 */
beforeEach(function () {
    Storage::fake('files');
});

function bgBook(array $attributes = [], ?User $owner = null): Book
{
    $owner ??= User::factory()->create();
    $book = $owner->books()->create(array_merge([
        'title' => 'The Long Field',
        'author_name' => 'A Writer',
        'language' => 'English (US)',
        'description' => 'A clear and useful description of the book.',
    ], $attributes));

    ManuscriptSection::factory()->for($book)->create([
        'title' => 'Chapter One', 'type' => 'chapter', 'position' => 0,
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Prose.']]]]],
    ]);

    return $book;
}

function bgSetCover(Book $book, array $cover): Book
{
    $book->update(['cover' => array_merge($book->cover ?? [], $cover)]);

    return $book->fresh();
}

/** The front as GD, drawn without type. */
function bgFront(Book $book, bool $text = false)
{
    $covers = app(CoverRenderer::class);

    return imagecreatefromstring($covers->render($covers->settings($book), null, withText: $text));
}

function bgPixel($image, int $x, int $y): array
{
    $c = imagecolorat($image, $x, $y);

    return [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
}

function bgNear(array $got, array $want, int $tolerance = 6): bool
{
    return abs($got[0] - $want[0]) <= $tolerance && abs($got[1] - $want[1]) <= $tolerance && abs($got[2] - $want[2]) <= $tolerance;
}

function bgGeometry(Book $book, int $pages = 300, string $service = 'kdp'): array
{
    $wraps = app(CoverWrap::class);

    return $wraps->geometry($book, $wraps->options($book, ['service' => $service, 'paper' => 'white']), $pages);
}

/** A PNG: left half one colour, right half another. */
function bgPng(int $w, int $h, array $left, array $right): string
{
    $image = imagecreatetruecolor($w, $h);
    imagefill($image, 0, 0, imagecolorallocate($image, ...$left));
    imagefilledrectangle($image, intdiv($w, 2), 0, $w - 1, $h - 1, imagecolorallocate($image, ...$right));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

const BG_RED_BLUE = ['kind' => 'linear', 'angle' => 180, 'stops' => [['color' => '#ff0000', 'at' => 0], ['color' => '#0000ff', 'at' => 1]]];

it('validates backgrounds: colours, 2 to 4 stops in order, angles, known keys, pictures on the wrap only', function () {
    expect(CoverBackground::validate(['kind' => 'solid', 'color' => '#AABBCC'], 'front'))->toBe(['kind' => 'solid', 'color' => '#aabbcc'])
        ->and(CoverBackground::validate(BG_RED_BLUE, 'back')['stops'][1])->toBe(['color' => '#0000ff', 'at' => 1.0])
        ->and(CoverBackground::validate(['kind' => 'radial', 'x' => 0.5, 'y' => 0.3, 'stops' => [['color' => '#000000', 'at' => 0], ['color' => '#ffffff', 'at' => 0.5], ['color' => '#ff0000', 'at' => 0.8], ['color' => '#00ff00', 'at' => 1]]], 'spine')['kind'])->toBe('radial');

    $refused = [
        [['kind' => 'solid', 'color' => 'red'], 'front'],
        [['kind' => 'linear', 'angle' => 90, 'stops' => [['color' => '#ff0000', 'at' => 0]]], 'front'],
        [['kind' => 'linear', 'angle' => 90, 'stops' => array_fill(0, 5, ['color' => '#ff0000', 'at' => 0.5])], 'front'],
        [['kind' => 'linear', 'angle' => 90, 'stops' => [['color' => '#ff0000', 'at' => 0.8], ['color' => '#0000ff', 'at' => 0.2]]], 'front'],
        [['kind' => 'linear', 'angle' => 400, 'stops' => BG_RED_BLUE['stops']], 'front'],
        [['kind' => 'solid', 'color' => '#ffffff', 'script' => 'x'], 'front'],
        [['kind' => 'image', 'image' => 'imgabc', 'x' => 0.5, 'y' => 0.5, 'zoom' => 1], 'front'],
        [['kind' => 'image', 'image' => 'imgabc', 'x' => 0.5, 'y' => 0.5, 'zoom' => 1], 'wrap', ['imgother']],
        [['kind' => 'pattern'], 'front'],
    ];

    foreach ($refused as $case) {
        expect(fn () => CoverBackground::validate($case[0], $case[1], $case[2] ?? null))->toThrow(ValidationException::class);
    }
});

it('saves, replaces and removes backgrounds through the cover endpoint, for the author only', function () {
    $book = bgBook();
    $owner = $book->user;

    $this->actingAs($owner)->patchJson("/books/{$book->id}/cover", ['backgrounds' => ['front' => BG_RED_BLUE, 'spine' => ['kind' => 'solid', 'color' => '#112233']]])
        ->assertOk()
        ->assertJsonPath('cover.backgrounds.front.kind', 'linear')
        ->assertJsonPath('cover.backgrounds.spine.color', '#112233');

    // One scope at a time: null removes that one, the other stays.
    $this->actingAs($owner)->patchJson("/books/{$book->id}/cover", ['backgrounds' => ['front' => null]])->assertOk();
    expect(array_keys($book->fresh()->cover['backgrounds']))->toBe(['spine']);

    $this->actingAs($owner)->patchJson("/books/{$book->id}/cover", ['backgrounds' => ['back' => ['kind' => 'solid', 'color' => 'blue']]])->assertUnprocessable();
    $this->actingAs(User::factory()->create())->patchJson("/books/{$book->id}/cover", ['backgrounds' => ['front' => BG_RED_BLUE]])->assertForbidden();

    $this->actingAs($owner)->patchJson("/books/{$book->id}/cover", ['backgrounds' => null])->assertOk();
    expect($book->fresh()->cover)->not->toHaveKey('backgrounds');
});

it('draws a linear gradient at its angle: known colours at known points', function () {
    $book = bgSetCover(bgBook(), ['backgrounds' => ['front' => BG_RED_BLUE]]);
    $image = bgFront($book);

    // 180° runs top to bottom: red at the top, blue at the foot, purple between.
    expect(bgNear(bgPixel($image, 800, 2), [255, 0, 0], 3))->toBeTrue()
        ->and(bgNear(bgPixel($image, 800, 2557), [0, 0, 255], 3))->toBeTrue()
        ->and(bgNear(bgPixel($image, 800, 1280), [128, 0, 127], 4))->toBeTrue();

    // 90° runs left to right; on a 5:8 cover the line is the width.
    $book = bgSetCover($book, ['backgrounds' => ['front' => ['kind' => 'linear', 'angle' => 90, 'stops' => [['color' => '#000000', 'at' => 0], ['color' => '#ffffff', 'at' => 1]]]]]);
    $image = bgFront($book);
    expect(bgNear(bgPixel($image, 2, 1280), [0, 0, 0], 3))->toBeTrue()
        ->and(bgNear(bgPixel($image, 1597, 1280), [255, 255, 255], 3))->toBeTrue()
        ->and(bgNear(bgPixel($image, 400, 100), [64, 64, 64], 4))->toBeTrue()
        ->and(bgNear(bgPixel($image, 400, 2400), [64, 64, 64], 4))->toBeTrue();

    // 45°: CSS's gradient line reaches the corners exactly.
    $bg = ['kind' => 'linear', 'angle' => 45, 'stops' => [['color' => '#000000', 'at' => 0], ['color' => '#ffffff', 'at' => 1]]];
    $book = bgSetCover($book, ['backgrounds' => ['front' => $bg]]);
    $image = bgFront($book);
    expect(bgNear(bgPixel($image, 1, 2558), [0, 0, 0], 4))->toBeTrue()
        ->and(bgNear(bgPixel($image, 1598, 1), [255, 255, 255], 4))->toBeTrue()
        ->and(bgNear(bgPixel($image, 800, 1280), CoverBackground::colorAt($bg, 0.5, 0.5, 1600, 2560), 3))->toBeTrue();
});

it('draws radial gradients, four stops and solid colours', function () {
    $radial = ['kind' => 'radial', 'x' => 0.5, 'y' => 0.5, 'stops' => [['color' => '#ffff00', 'at' => 0], ['color' => '#00ff00', 'at' => 1]]];
    $image = bgFront(bgSetCover(bgBook(), ['backgrounds' => ['front' => $radial]]));

    expect(bgNear(bgPixel($image, 800, 1280), [255, 255, 0], 6))->toBeTrue()
        ->and(bgNear(bgPixel($image, 3, 3), [0, 255, 0], 8))->toBeTrue()
        ->and(bgNear(bgPixel($image, 800, 640), CoverBackground::colorAt($radial, 0.5, 0.25, 1600, 2560), 6))->toBeTrue();

    $four = ['kind' => 'linear', 'angle' => 180, 'stops' => [
        ['color' => '#ff0000', 'at' => 0], ['color' => '#00ff00', 'at' => 0.25], ['color' => '#0000ff', 'at' => 0.75], ['color' => '#ffffff', 'at' => 1],
    ]];
    $image = bgFront(bgSetCover(bgBook(), ['backgrounds' => ['front' => $four]]));
    expect(bgNear(bgPixel($image, 800, 640), [0, 255, 0], 4))->toBeTrue()
        ->and(bgNear(bgPixel($image, 800, 1920), [0, 0, 255], 4))->toBeTrue()
        ->and(bgNear(bgPixel($image, 800, 1280), [0, 128, 128], 4))->toBeTrue();

    $image = bgFront(bgSetCover(bgBook(), ['backgrounds' => ['front' => ['kind' => 'solid', 'color' => '#336699']]]));
    expect(bgNear(bgPixel($image, 10, 10), [0x33, 0x66, 0x99], 2))->toBeTrue()->and(bgNear(bgPixel($image, 1590, 2550), [0x33, 0x66, 0x99], 2))->toBeTrue();
});

it('sets a layout cover in ink that reads on a custom background', function () {
    $light = bgSetCover(bgBook(), ['backgrounds' => ['front' => ['kind' => 'solid', 'color' => '#f4f0e0']]]);
    $dark = bgSetCover(bgBook(), ['backgrounds' => ['front' => ['kind' => 'solid', 'color' => '#101820']]]);

    expect(CoverRenderer::palette(app(CoverRenderer::class)->settings($light))['ink'])->toBe('#1a1a1a')
        ->and(CoverRenderer::palette(app(CoverRenderer::class)->settings($dark))['ink'])->toBe('#ffffff');
});

it('paints the back and the spine their own backgrounds in the wrap', function () {
    $book = bgSetCover(bgBook(), ['backgrounds' => [
        'back' => ['kind' => 'solid', 'color' => '#00ff00'],
        'spine' => ['kind' => 'solid', 'color' => '#ff00ff'],
    ]]);
    $g = bgGeometry($book);
    $dpi = 40;
    $image = imagecreatefromstring(app(CoverWrap::class)->preview($book, $g, $dpi, bare: true));

    expect(bgNear(bgPixel($image, (int) (0.3 * $dpi), (int) ($g['height'] * $dpi / 2)), [0, 255, 0]))->toBeTrue()
        ->and(bgNear(bgPixel($image, (int) (($g['spine_x'] + $g['spine'] / 2) * $dpi), (int) ($g['height'] * $dpi / 2)), [255, 0, 255]))->toBeTrue();

    // The generated back sets dark type on the light green.
    $back = app(CoverWrap::class)->backLayersFromGenerated($book, $g);
    expect($back['items'][0]['color'])->toBe('#1a1a1a');
});

it('runs one gradient across the whole wrap, and gives the store front the same share of it', function () {
    $book = bgBook();
    $g = bgGeometry($book, 300);
    $bg = ['kind' => 'linear', 'angle' => 90, 'stops' => [['color' => '#ff0000', 'at' => 0], ['color' => '#0000ff', 'at' => 1]]];
    $book = bgSetCover($book, ['backgrounds' => ['wrap' => $bg], 'wrap_ref' => CoverWrap::reference($g)]);
    $dpi = 40;
    $wrap = imagecreatefromstring(app(CoverWrap::class)->preview($book, $g, $dpi));
    $mid = (int) ($g['height'] * $dpi / 2);
    $at = fn (float $inches) => CoverBackground::colorAt($bg, $inches / $g['width'], 0.5, $g['width'], $g['height']);

    expect(bgNear(bgPixel($wrap, 1, $mid), [255, 0, 0], 8))->toBeTrue()
        ->and(bgNear(bgPixel($wrap, imagesx($wrap) - 2, $mid), [0, 0, 255], 8))->toBeTrue()
        ->and(bgNear(bgPixel($wrap, (int) (($g['spine_x'] + $g['spine'] / 2) * $dpi), $mid), $at($g['spine_x'] + $g['spine'] / 2), 8))->toBeTrue();

    // The front alone (the store's JPEG) shows its share: its left edge is
    // the colour the wrap has at the front's box.
    $box = CoverWrap::frontBox($g);
    $front = bgFront($book);
    expect(bgNear(bgPixel($front, 2, 1280), $at($box['x']), 6))->toBeTrue()
        ->and(bgNear(bgPixel($front, 1597, 1280), $at($box['x'] + $box['width']), 6))->toBeTrue();

    // Its hash follows the reference geometry, and the wrap's fingerprint too.
    $version = app(CoverRenderer::class)->version($book);
    $print = app(CoverWrap::class)->fingerprint($book);
    $moved = bgSetCover($book, ['wrap_ref' => CoverWrap::reference(bgGeometry($book, 600))]);
    expect(app(CoverRenderer::class)->version($moved))->not->toBe($version)
        ->and(app(CoverWrap::class)->fingerprint($moved))->not->toBe($print);
});

it('records where a wrap background was placed when it is chosen', function () {
    $book = bgBook();

    $this->actingAs($book->user)->patchJson("/books/{$book->id}/cover", ['backgrounds' => ['wrap' => BG_RED_BLUE]])->assertOk();

    $ref = $book->fresh()->cover['wrap_ref'];
    $g = app(CoverWrap::class)->forBook($book->fresh());
    expect($ref['spine'])->toBe($g['spine'])->and($ref['service'])->toBe('kdp');

    // Removing it removes the reference.
    $this->actingAs($book->user)->patchJson("/books/{$book->id}/cover", ['backgrounds' => ['wrap' => null]])->assertOk();
    expect($book->fresh()->cover)->not->toHaveKey('wrap_ref');
});

it('spans one picture across back, spine and front, re-fitted when the page count changes, with a warning and a refit', function () {
    $book = bgBook();
    $entry = CoverImages::store($book, UploadedFile::fake()->createWithContent('wrap.png', bgPng(2400, 1200, [255, 0, 0], [0, 0, 255])));
    $book = bgSetCover($book, ['images' => [$entry]]);
    $thin = bgGeometry($book, 120);
    $book = bgSetCover($book, ['backgrounds' => ['wrap' => ['kind' => 'image', 'image' => $entry['id'], 'x' => 0.5, 'y' => 0.5, 'zoom' => 1]], 'wrap_ref' => CoverWrap::reference($thin)]);
    $wraps = app(CoverWrap::class);
    $dpi = 40;

    foreach ([120, 500] as $pages) {
        $g = bgGeometry($book, $pages);
        $image = imagecreatefromstring($wraps->preview($book, $g, $dpi, bare: true));
        $mid = (int) ($g['height'] * $dpi / 2);

        // The picture fills the sheet: its left half on the back, its right
        // half on the front, whatever the spine's width.
        expect(bgNear(bgPixel($image, 2, $mid), [255, 0, 0], 10))->toBeTrue()
            ->and(bgNear(bgPixel($image, imagesx($image) - 3, $mid), [0, 0, 255], 10))->toBeTrue();
    }

    $kinds = fn (array $g, ?Book $on = null) => array_column(array_filter($wraps->warnings($on ?? $book, $g), fn ($w) => $w['panel'] === 'wrap'), 'kind');

    // 2400 px over a wrap about 12.6 in wide is under 300 dpi.
    expect($kinds($thin))->toBe(['dpi'])
        ->and($kinds(bgGeometry($book, 500)))->toContain('refit');

    // "Looks right" takes the new geometry.
    $this->actingAs($book->user)->postJson("/books/{$book->id}/cover/wrap/refit", ['service' => 'kdp', 'paper' => 'white'])->assertOk();
    expect($book->fresh()->cover['wrap_ref']['pages'])->toBe(app(CoverWrap::class)->forBook($book->fresh())['pages']);

    // A big enough picture prints without a resolution warning.
    $big = CoverImages::store($book, UploadedFile::fake()->createWithContent('big.png', bgPng(6000, 4000, [10, 10, 10], [200, 200, 200])));
    $book = bgSetCover($book->fresh(), ['images' => [...CoverImages::all($book->fresh()), $big], 'backgrounds' => ['wrap' => ['kind' => 'image', 'image' => $big['id'], 'x' => 0.5, 'y' => 0.5, 'zoom' => 1]], 'wrap_ref' => CoverWrap::reference($thin)]);
    expect($kinds($thin, $book))->toBe([]);

    // Deleting the picture takes the background with it.
    $this->actingAs($book->user)->deleteJson("/books/{$book->id}/cover/images/{$big['id']}")->assertOk();
    expect($book->fresh()->cover['backgrounds'] ?? [])->not->toHaveKey('wrap');
});

it('computes WCAG contrast, and each text layer against what is under it', function () {
    expect(round(CoverContrast::ratio('#ffffff', '#000000'), 2))->toBe(21.0)
        ->and(round(CoverContrast::ratio('#777777', '#ffffff'), 2))->toBe(4.48)
        ->and(CoverContrast::large(['weight' => 'regular'], 18))->toBeTrue()
        ->and(CoverContrast::large(['weight' => 'bold'], 14))->toBeTrue()
        ->and(CoverContrast::large(['weight' => 'regular'], 14))->toBeFalse();

    $book = bgBook();
    $layers = ['version' => 1, 'items' => [
        CoverLayers::make('title', ['id' => 'title', 'x' => 0.1, 'y' => 0.1, 'width' => 0.8, 'size' => 0.06, 'color' => '#ffffff']),
        CoverLayers::make('author', ['id' => 'author', 'x' => 0.1, 'y' => 0.85, 'width' => 0.8, 'size' => 0.012, 'color' => '#ffffff']),
        CoverLayers::make('subtitle', ['id' => 'sub', 'x' => 0.1, 'y' => 0.85, 'width' => 0.8, 'size' => 0.012, 'color' => '#ffffff', 'background' => ['color' => '#000000', 'opacity' => 1, 'padding' => 0.01]]),
    ]];
    $book = bgSetCover($book, ['subtitle' => 'Sub', 'layers' => $layers,
        'backgrounds' => ['front' => ['kind' => 'linear', 'angle' => 180, 'stops' => [['color' => '#000000', 'at' => 0], ['color' => '#ffffff', 'at' => 1]]]]]);

    $rows = collect(CoverContrast::front($book))->keyBy('id');

    // White on the dark top passes; small white type on the light foot does
    // not; the same type on its own black panel does.
    expect($rows['title']['ok'])->toBeTrue()->and($rows['title']['large'])->toBeTrue()
        ->and($rows['author']['ok'])->toBeFalse()->and($rows['author']['needed'])->toBe(4.5)
        ->and($rows['sub']['ok'])->toBeTrue()->and($rows['sub']['ratio'])->toBeGreaterThan(15);

    $this->actingAs($book->user)->getJson("/books/{$book->id}/cover/contrast")
        ->assertOk()
        ->assertJsonPath('front.1.id', 'author')
        ->assertJsonPath('front.1.ok', false);

    // The Accessibility screen reports the worst layer.
    $check = collect(app(AccessibilityAudit::class)->run($book)['checks'])->firstWhere('key', 'cover_contrast');
    expect($check['passed'])->toBeFalse()->and($check['detail'])->toContain('the author');
});

it('keeps the palette check for a layout cover with no background of its own', function () {
    $book = bgSetCover(bgBook(), ['palette' => 3]);
    $check = collect(app(AccessibilityAudit::class)->run($book)['checks'])->firstWhere('key', 'cover_contrast');

    expect($check['detail'])->toBe('6.2:1 (4.5:1 is the minimum)')->and($check)->not->toHaveKey('layers');
});

it('measures back layers on the wrap', function () {
    $book = bgSetCover(bgBook(), ['backgrounds' => ['back' => ['kind' => 'solid', 'color' => '#ffffff']]]);
    $g = bgGeometry($book);
    $back = app(CoverWrap::class)->backLayersFromGenerated($book, $g);
    $back['items'][0]['color'] = '#dddddd';
    $book = bgSetCover($book, ['back_layers' => $back]);

    $rows = collect(CoverContrast::wrap($book, $g)['back'])->keyBy('id');
    expect($rows['title']['ok'])->toBeFalse()->and($rows['description']['ok'])->toBeTrue();
});

it('binds a series number layer to each book’s own number', function () {
    $book = bgBook(['series_name' => 'Écrivain Prépa', 'series_number' => 3]);
    expect(CoverLayers::texts($book)['number'])->toBe('3')
        ->and(CoverLayers::validate(['version' => 1, 'items' => [CoverLayers::make('number', ['id' => 'n', 'y' => 0])]], 'spine')['items'][0]['source'])->toBe('number');
});

it('draws an untouched book exactly as before: no new settings, the same hashes after a background is set and removed', function () {
    $book = bgBook();
    $covers = app(CoverRenderer::class);
    $wraps = app(CoverWrap::class);
    $settings = $covers->settings($book);
    [$version, $fingerprint, $background] = [$covers->version($book), $wraps->fingerprint($book), $covers->backgroundPath($book)];
    $bytes = $covers->render($settings);

    expect($settings)->not->toHaveKeys(['background', 'slice']);

    $this->actingAs($book->user)->patchJson("/books/{$book->id}/cover", ['backgrounds' => ['front' => BG_RED_BLUE, 'wrap' => BG_RED_BLUE]])->assertOk();
    expect($covers->version($book->fresh()))->not->toBe($version);

    $this->actingAs($book->user)->patchJson("/books/{$book->id}/cover", ['backgrounds' => null])->assertOk();
    $book = $book->fresh();

    expect($covers->version($book))->toBe($version)
        ->and($wraps->fingerprint($book))->toBe($fingerprint)
        ->and($covers->backgroundPath($book))->toBe($background)
        ->and(md5($covers->render($covers->settings($book))))->toBe(md5($bytes));
});
