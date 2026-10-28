<?php

use App\Covers\CoverLayers;
use App\Covers\CoverText;
use App\Models\User;
use App\Publishing\CoverRenderer;
use App\Publishing\CoverWrap;
use Illuminate\Support\Facades\Storage;

/**
 * Layers as the server draws them: every glyph where the model says, in
 * the face asked for, at any size. The model is CoverText, which the live
 * canvas runs too (CoverTextParityTest), so this is the check that what
 * the author places is what ships.
 */

/** The bounding box of "ink" pixels (near white on the dark Dusk palette). */
function inkBox($image, ?callable $is = null): ?array
{
    $is ??= fn (int $r, int $g, int $b) => $r > 200 && $g > 200 && $b > 200;
    [$w, $h] = [imagesx($image), imagesy($image)];
    $box = null;

    for ($y = 0; $y < $h; $y += 2) {
        for ($x = 0; $x < $w; $x += 2) {
            $c = imagecolorat($image, $x, $y);

            if ($is(($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF)) {
                $box = $box === null
                    ? [$x, $y, $x, $y]
                    : [min($box[0], $x), min($box[1], $y), max($box[2], $x), max($box[3], $y)];
            }
        }
    }

    return $box;
}

/** @param  array<int, array<string, mixed>>  $items */
function drawLayers(array $items, array $texts = [], ?int $width = null)
{
    $jpeg = app(CoverRenderer::class)->render([
        'palette' => 0, 'layout' => 'classic', 'shade' => 0, 'artwork' => null,
        'layers' => CoverLayers::validate(['version' => 1, 'items' => $items]),
        'texts' => $texts,
    ], null, $width);

    return imagecreatefromstring($jpeg);
}

it('draws a layer\'s text where the model puts it, within 2% of the cover', function (string $font, string $align) {
    $layer = CoverLayers::make('custom', [
        'text' => 'HELLO WORLD', 'font' => $font, 'weight' => 'regular', 'align' => $align,
        'x' => 0.12, 'y' => 0.31, 'width' => 0.7, 'size' => 0.05, 'color' => '#ffffff',
    ]);
    $layer = CoverLayers::validate(['version' => 1, 'items' => [$layer]])['items'][0];

    $ink = inkBox(drawLayers([$layer]));
    $model = CoverText::layout($layer, $layer['text'], 1600, 2560);
    $line = $model['lines'][0];
    [$dx, $dy] = [0.02 * 1600, 0.02 * 2560];

    expect($ink)->not->toBeNull()
        // Left and right ink edges at the line's ends.
        ->and($ink[0])->toEqualWithDelta($line['x'], $dx)
        ->and($ink[2])->toEqualWithDelta($line['x'] + $line['width'], $dx)
        // Capitals sit on the baseline and inside the box.
        ->and($ink[3])->toEqualWithDelta($line['baseline'], $dy)
        ->and($ink[1])->toBeGreaterThanOrEqual($model['box']['y'] - $dy)
        ->and($ink[3])->toBeLessThanOrEqual($model['box']['y'] + $model['box']['height'] + $dy);
})->with([
    ['eb-garamond', 'left'],
    ['montserrat', 'center'],
    ['oswald', 'right'],
    ['playfair-display', 'center'],
    ['bebas-neue', 'left'],
]);

it('wraps inside the box and grows downward, never moving the box', function () {
    $short = CoverLayers::make('custom', ['text' => 'SHORT', 'x' => 0.2, 'y' => 0.2, 'width' => 0.6, 'size' => 0.04]);
    $long = array_merge($short, ['text' => 'A MUCH LONGER TITLE THAT HAS TO WRAP ONTO SEVERAL LINES']);

    $a = CoverText::layout(CoverLayers::normalise($short), $short['text'], 1600, 2560);
    $b = CoverText::layout(CoverLayers::normalise($long), $long['text'], 1600, 2560);

    expect(count($b['lines']))->toBeGreaterThan(1)
        ->and($b['box']['x'])->toBe($a['box']['x'])
        ->and($b['box']['y'])->toBe($a['box']['y'])
        ->and($b['lines'][0]['baseline'])->toBe($a['lines'][0]['baseline']);

    foreach ($b['lines'] as $line) {
        expect($line['width'])->toBeLessThanOrEqual($b['box']['width'] + 0.01);
    }

    $ink = inkBox(drawLayers([$long]));
    expect($ink[0])->toBeGreaterThanOrEqual((int) (0.2 * 1600) - 32)
        ->and($ink[2])->toBeLessThanOrEqual((int) (0.8 * 1600) + 32);
});

it('leaves a hidden layer off the cover', function () {
    $layer = CoverLayers::make('custom', ['text' => 'HIDDEN', 'size' => 0.08, 'visible' => false]);

    expect(inkBox(drawLayers([$layer])))->toBeNull();
});

it('honours colour, opacity, panel and shadow', function () {
    $red = fn (int $r, int $g, int $b) => $r > 180 && $g < 80 && $b < 80;

    $layer = CoverLayers::make('custom', ['text' => 'RED', 'size' => 0.08, 'color' => '#ff0000']);
    expect(inkBox(drawLayers([$layer]), $red))->not->toBeNull();

    // At zero opacity there is nothing to see.
    expect(inkBox(drawLayers([array_merge($layer, ['opacity' => 0])]), $red))->toBeNull();

    // A panel fills its box plus padding.
    $panel = CoverLayers::make('custom', [
        'text' => '', 'x' => 0.25, 'y' => 0.5, 'width' => 0.5, 'height' => 0.1,
        'background' => ['color' => '#ff0000', 'opacity' => 1, 'padding' => 0.01],
    ]);
    $box = inkBox(drawLayers([$panel]), $red);
    expect($box[0])->toEqualWithDelta(0.24 * 1600, 4)
        ->and($box[2])->toEqualWithDelta(0.76 * 1600, 4)
        ->and($box[1])->toEqualWithDelta(0.5 * 2560 - 16, 4)
        ->and($box[3])->toEqualWithDelta(0.6 * 2560 + 16, 4);

    // A strong shadow darkens below and right of the type.
    $white = CoverLayers::make('custom', ['text' => 'I', 'size' => 0.2, 'color' => '#ffffff', 'x' => 0.4, 'width' => 0.2, 'align' => 'left']);
    $plain = drawLayers([$white]);
    $shadowed = drawLayers([array_merge($white, ['shadow' => 'strong'])]);
    $ink = inkBox($plain);
    // The darkest pixel just right of the stem (the serifs reach further),
    // where the shadow falls.
    $y = (int) (($ink[1] + $ink[3]) / 2);
    $stem = $ink[0];

    for ($x = $ink[0]; $x <= $ink[2]; $x++) {
        if (((imagecolorat($plain, $x, $y) >> 8) & 0xFF) > 200) {
            $stem = $x;
        }
    }

    $lum = fn ($img) => min(array_map(fn ($x) => (imagecolorat($img, $x, $y) >> 8) & 0xFF, range($stem + 2, $stem + 10)));
    expect($lum($shadowed))->toBeLessThan($lum($plain));
});

it('draws layers in proportion at any size', function () {
    $layer = CoverLayers::make('custom', ['text' => 'SCALE', 'x' => 0.3, 'y' => 0.4, 'width' => 0.4, 'size' => 0.06]);

    $big = inkBox(drawLayers([$layer]));
    $small = drawLayers([$layer], [], 800);
    expect(imagesx($small))->toBe(800)->and(imagesy($small))->toBe(1280);

    $half = inkBox($small);
    foreach ([0, 1, 2, 3] as $i) {
        expect($half[$i] * 2)->toEqualWithDelta($big[$i], 0.02 * 1600);
    }
});

it('sets characters a face lacks in the fallback face rather than as boxes', function () {
    $layer = CoverLayers::normalise(CoverLayers::make('custom', ['text' => 'Note ★ ♭', 'font' => 'bebas-neue']));
    $set = CoverText::layout($layer, $layer['text'], 1600, 2560);
    $faces = collect($set['lines'][0]['glyphs'])->mapWithKeys(fn ($g) => [$g['char'] => $g['face']]);

    expect($faces['N'])->toBe('bebas-neue.regular')
        ->and($faces['★'])->toBe('fallback');

    expect(inkBox(drawLayers([$layer])))->not->toBeNull();
});

it('draws bound layers from the book\'s own text', function () {
    Storage::fake('files');
    $user = User::factory()->create();
    $book = $user->books()->create(['title' => 'Kreyòl', 'author_name' => 'A Writer', 'cover' => [
        'layers' => ['version' => 1, 'items' => [CoverLayers::make('title', ['size' => 0.08, 'color' => '#ffffff'])]],
    ]]);

    $renderer = app(CoverRenderer::class);
    $one = $renderer->ensure($book);
    $book->update(['title' => 'A Much Longer Kreyòl Title For Wrapping']);
    $two = $renderer->ensure($book->fresh());

    expect($two)->not->toBe($one);

    $image = imagecreatefromstring(Storage::disk('files')->get($two));
    $ink = inkBox($image);
    // Wrapped inside the 70% box, starting at its top.
    expect($ink[0])->toBeGreaterThanOrEqual((int) (0.15 * 1600) - 32)
        ->and($ink[1])->toBeGreaterThanOrEqual((int) (0.45 * 2560) - 51);
});

it('puts a layered front on the print cover wrap, at print resolution', function () {
    Storage::fake('files');
    $user = User::factory()->create();
    $book = $user->books()->create(['title' => 'Wrap', 'author_name' => 'A Writer', 'cover' => [
        'layers' => ['version' => 1, 'items' => [CoverLayers::make('custom', [
            'text' => '', 'x' => 0.5, 'y' => 0.5, 'width' => 0.4, 'height' => 0.3,
            'background' => ['color' => '#ff0000', 'opacity' => 1, 'padding' => 0],
        ])]],
    ]]);

    $renderer = app(CoverRenderer::class);
    $front = imagecreatefromstring($renderer->front($book, 2000));
    expect(imagesx($front))->toBe(2000)->and(imagesy($front))->toBe(3200);

    $red = fn (int $r, int $g, int $b) => $r > 180 && $g < 80 && $b < 80;
    $box = inkBox($front, $red);
    expect($box[0])->toEqualWithDelta(1000, 6)->and($box[1])->toEqualWithDelta(1600, 6);

    // A layout cover still uses its stored file.
    $plain = $user->books()->create(['title' => 'Plain', 'author_name' => 'A Writer']);
    expect(getimagesizefromstring($renderer->front($plain, 2000))[0])->toBe(1600);

    // And the wrap's front panel carries the layer: red in the right half.
    $wraps = app(CoverWrap::class);
    $g = $wraps->geometry($book, $wraps->options($book, ['service' => 'kdp', 'paper' => 'white']), 200);
    $preview = imagecreatefromstring($wraps->preview($book, $g, 72));
    $wrapRed = inkBox($preview, $red);
    expect($wrapRed)->not->toBeNull()
        ->and($wrapRed[0])->toBeGreaterThan(imagesx($preview) / 2);
});
