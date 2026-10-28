<?php

use App\Covers\CoverFonts;
use App\Covers\CoverLayers;
use App\Models\Book;
use App\Models\User;
use App\Publishing\CoverRenderer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Cover Studio's text layers: the schema the server holds them to, the
 * endpoints that store them, and "Customise text", which turns the layout
 * the author sees into layers placed where the layout drew them.
 */
function layerBook(?User $owner = null, array $cover = []): Book
{
    $owner ??= User::factory()->create();

    return $owner->books()->create([
        'title' => 'Réussir l\'examen',
        'subtitle' => 'Kreyòl Ayisyen, pas à pas',
        'author_name' => 'Marie Joseph',
        'cover' => $cover ?: null,
    ]);
}

/** @param  array<int, array<string, mixed>>  $items */
function layerDoc(array $items): array
{
    return ['version' => CoverLayers::VERSION, 'items' => $items];
}

function refused(mixed $document): array
{
    try {
        CoverLayers::validate($document);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('The document was accepted.');
}

/* ---------------------------------------------------------------- schema */

it('accepts a well-formed layer document and normalises it', function () {
    $doc = CoverLayers::validate(layerDoc([
        array_merge(CoverLayers::make('title'), ['x' => '0.1', 'color' => '#FFAA00']),
        CoverLayers::make('custom', ['text' => "Line one\nLine two", 'background' => ['color' => '#000000', 'opacity' => 0.5, 'padding' => 0.02]]),
    ]));

    expect($doc['version'])->toBe(1)
        ->and($doc['items'][0]['x'])->toBe(0.1)
        ->and($doc['items'][0]['color'])->toBe('#ffaa00')
        ->and($doc['items'][0]['text'])->toBeNull()
        ->and($doc['items'][1]['background']['padding'])->toBe(0.02);
});

it('refuses bad colours, out-of-range positions and sizes, and unknown fonts', function (array $change, string $key) {
    $errors = refused(layerDoc([array_merge(CoverLayers::make('title'), $change)]));

    expect(array_keys($errors))->toContain($key);
})->with([
    'colour without #' => [['color' => 'ff0000'], 'layers.items.0.color'],
    'colour by name' => [['color' => 'red'], 'layers.items.0.color'],
    'three-digit hex' => [['color' => '#f00'], 'layers.items.0.color'],
    'x below zero' => [['x' => -0.1], 'layers.items.0.x'],
    'y past the foot' => [['y' => 1.2], 'layers.items.0.y'],
    'box past the edge' => [['x' => 0.5, 'width' => 0.7], 'layers.items.0.width'],
    'too narrow' => [['width' => 0.001], 'layers.items.0.width'],
    'size zero' => [['size' => 0], 'layers.items.0.size'],
    'size huge' => [['size' => 0.9], 'layers.items.0.size'],
    'opacity over one' => [['opacity' => 1.5], 'layers.items.0.opacity'],
    'unknown font' => [['font' => 'comic-sans'], 'layers.items.0.font'],
    'unknown align' => [['align' => 'justify'], 'layers.items.0.align'],
    'unknown shadow' => [['shadow' => 'glow'], 'layers.items.0.shadow'],
    'unknown source' => [['source' => 'isbn'], 'layers.items.0.source'],
    'a weight the family lacks' => [['font' => 'bebas-neue', 'weight' => 'bold'], 'layers.items.0.weight'],
    'an italic the family lacks' => [['font' => 'cinzel', 'italic' => true], 'layers.items.0.weight'],
    'a bad panel colour' => [['background' => ['color' => 'black', 'opacity' => 1, 'padding' => 0]], 'layers.items.0.background.color'],
]);

it('refuses unknown keys, text on a bound layer, a wrong version and duplicate ids', function () {
    expect(refused(layerDoc([array_merge(CoverLayers::make('title'), ['rotation' => 12])])))
        ->toHaveKey('layers.items.0');
    expect(refused(layerDoc([array_merge(CoverLayers::make('title'), ['background' => ['color' => '#000000', 'opacity' => 1, 'padding' => 0, 'blur' => 4]])])))
        ->toHaveKey('layers.items.0.background');
    expect(refused(layerDoc([array_merge(CoverLayers::make('title'), ['text' => 'Not the title'])])))
        ->toHaveKey('layers.items.0.text');
    expect(refused(['version' => 99, 'items' => []]))->toHaveKey('layers.version');
    expect(refused(['version' => 1, 'items' => [], 'theme' => 'x']))->toHaveKey('layers');
    expect(refused(layerDoc([CoverLayers::make('title', ['id' => 'a']), CoverLayers::make('author', ['id' => 'a'])])))
        ->toHaveKey('layers.items.1.id');
    expect(refused(layerDoc(array_fill(0, CoverLayers::MAX_LAYERS + 1, CoverLayers::make('custom')))))
        ->toHaveKey('layers.items');
});

/* ------------------------------------------------------------- endpoints */

it('stores layers through the cover endpoint, and null goes back to the layout', function () {
    Storage::fake('files');
    $user = User::factory()->create();
    $book = layerBook($user);

    $doc = layerDoc([CoverLayers::make('title', ['id' => 'title', 'x' => 0.2, 'y' => 0.3, 'width' => 0.6, 'font' => 'playfair-display'])]);

    $this->actingAs($user)->patchJson("/books/{$book->id}/cover", ['layers' => $doc])
        ->assertOk()
        ->assertJsonPath('cover.layers.items.0.font', 'playfair-display')
        ->assertJsonPath('cover.layers.items.0.x', 0.2)
        ->assertJsonPath('cover.texts.title', 'Réussir l\'examen');

    expect($book->fresh()->cover['layers']['items'][0]['y'])->toBe(0.3);

    $this->actingAs($user)->patchJson("/books/{$book->id}/cover", ['layers' => null])
        ->assertOk()
        ->assertJsonPath('cover.layers', null);

    expect($book->fresh()->cover)->not->toHaveKey('layers');
});

it('refuses an invalid layer document over HTTP and keeps what was there', function () {
    $user = User::factory()->create();
    $book = layerBook($user);

    $this->actingAs($user)->patchJson("/books/{$book->id}/cover", [
        'layers' => layerDoc([array_merge(CoverLayers::make('title'), ['color' => 'blue'])]),
    ])->assertUnprocessable()->assertJsonValidationErrors('layers.items.0.color');

    expect($book->fresh()->cover['layers'] ?? null)->toBeNull();
});

it('does not let anyone else change or customise a cover', function () {
    $book = layerBook();
    $other = User::factory()->create();

    $this->actingAs($other)->patchJson("/books/{$book->id}/cover", ['layers' => layerDoc([])])->assertForbidden();
    $this->actingAs($other)->postJson("/books/{$book->id}/cover/layers")->assertForbidden();
    $this->actingAs($other)->deleteJson("/books/{$book->id}/cover/layers")->assertForbidden();
});

it('binds layers to the book: renaming the book renames the cover', function () {
    Storage::fake('files');
    $user = User::factory()->create();
    $book = layerBook($user, ['layers' => layerDoc([CoverLayers::make('title'), CoverLayers::make('series')])]);
    $book->update(['series_name' => 'Écrivain Prépa', 'series_number' => 3]);
    $renderer = app(CoverRenderer::class);
    $before = $renderer->version($book->fresh());

    $this->actingAs($user)->patchJson("/books/{$book->id}/cover", ['title' => 'Bac Kreyòl'])
        ->assertJsonPath('cover.texts.title', 'Bac Kreyòl')
        ->assertJsonPath('cover.texts.series', 'Écrivain Prépa · 3');

    expect($renderer->version($book->fresh()))->not->toBe($before);
});

/* ------------------------------------------------------ customise / reset */

it('turns the classic layout into layers placed where the layout drew them', function () {
    Storage::fake('files');
    $user = User::factory()->create();
    $book = layerBook($user, ['layout' => 'classic', 'font' => 'serif', 'palette' => 1]);

    $response = $this->actingAs($user)->postJson("/books/{$book->id}/cover/layers")->assertOk();
    $items = collect($response->json('cover.layers.items'))->keyBy('id');

    expect($items->keys()->all())->toBe(['title', 'subtitle', 'rule', 'author']);

    $title = $items['title'];
    // The layout's title block: 150px margins, first baseline at 560.
    expect($title['x'])->toEqualWithDelta(150 / 1600, 0.01)
        ->and($title['width'])->toEqualWithDelta(1300 / 1600, 0.01)
        ->and($title['align'])->toBe('center')
        ->and($title['font'])->toBe('eb-garamond')
        // Sand's ink, on a palette.
        ->and($title['color'])->toBe('#2d2416')
        ->and($title['shadow'])->toBe('none');

    $metrics = CoverFonts::metrics('eb-garamond.regular');
    $baseline = $title['y'] * 2560 + $metrics['asc'] / $metrics['upm'] * $title['size'] * 2560;
    expect($baseline)->toEqualWithDelta(560, 2);

    expect($items['subtitle']['italic'])->toBeTrue()
        ->and($items['author']['uppercase'])->toBeTrue()
        ->and($items['author']['y'])->toBeGreaterThan(0.85)
        // The rule is a shape: empty text over a panel.
        ->and($items['rule']['text'])->toBe('')
        ->and($items['rule']['background'])->not->toBeNull();

    // Customising twice keeps the author's layers.
    $book->refresh();
    $layers = $book->cover['layers'];
    $layers['items'][0]['x'] = 0.05;
    $book->update(['cover' => array_merge($book->cover, ['layers' => $layers])]);

    $this->actingAs($user)->postJson("/books/{$book->id}/cover/layers")
        ->assertJsonPath('cover.layers.items.0.x', 0.05);

    $this->actingAs($user)->deleteJson("/books/{$book->id}/cover/layers")
        ->assertOk()->assertJsonPath('cover.layers', null);
});

it('converts bold to left-aligned capitals and band to a band shape under its type', function () {
    Storage::fake('files');
    $renderer = app(CoverRenderer::class);

    $bold = collect($renderer->layersFromLayout(layerBook(cover: ['layout' => 'bold', 'font' => 'sans']))['items'])->keyBy('id');
    expect($bold['title']['align'])->toBe('left')
        ->and($bold['title']['uppercase'])->toBeTrue()
        ->and($bold['title']['font'])->toBe('source-sans')
        ->and($bold['title']['x'])->toEqualWithDelta(150 / 1600, 0.001);

    $band = collect($renderer->layersFromLayout(layerBook(cover: ['layout' => 'band']))['items'])->keyBy('id');
    expect($band->keys()->first())->toBe('band')
        ->and($band['band']['width'])->toBe(1.0)
        ->and($band['band']['y'] + $band['band']['height'])->toEqualWithDelta(1, 0.001)
        // The title sits on the band.
        ->and($band['title']['y'])->toBeGreaterThan($band['band']['y']);
});

it('starts a finished cover with no layers, and one without artwork from classic', function () {
    Storage::fake('files');
    $user = User::factory()->create();
    $renderer = app(CoverRenderer::class);

    $plain = layerBook($user, ['layout' => 'finished']);
    expect(collect($renderer->layersFromLayout($plain)['items'])->pluck('id')->all())->toContain('title');

    $this->actingAs($user)->post("/books/{$plain->id}/cover/artwork", [
        'artwork' => UploadedFile::fake()->image('cover.jpg', 400, 640),
    ])->assertOk();

    expect($renderer->layersFromLayout($plain->fresh())['items'])->toBe([]);
});

it('keeps a layout cover drawn by the layout', function () {
    Storage::fake('files');
    $book = layerBook(cover: ['layout' => 'bold']);
    $settings = app(CoverRenderer::class)->settings($book);

    expect($settings['layers'])->toBeNull()
        ->and($settings['texts'])->toBeNull();
});

it('falls back to the layout when stored layers no longer validate', function () {
    Storage::fake('files');
    $book = layerBook(cover: ['layers' => layerDoc([array_merge(CoverLayers::make('title'), ['font' => 'withdrawn-face'])])]);

    expect(app(CoverRenderer::class)->settings($book)['layers'])->toBeNull();
    // And it still draws.
    expect(getimagesizefromstring(Storage::disk('files')->get(app(CoverRenderer::class)->ensure($book)))[0])->toBe(1600);
});

/* ----------------------------------------------------------------- fonts */

it('serves the cover faces and their metrics, and nothing else', function () {
    $this->get('/cover-fonts/montserrat.regular')->assertRedirect();

    $user = User::factory()->create();

    $face = CoverFonts::urls('montserrat.bold-italic');
    $this->actingAs($user)->get($face['font'])->assertOk()->assertHeader('Content-Type', 'font/ttf');

    $metrics = $this->actingAs($user)->getJson($face['metrics'])->assertOk()->json();
    expect($metrics['upm'])->toBe(1000)
        ->and($metrics['adv'][(string) mb_ord('é')])->toBeInt();

    $this->actingAs($user)->get('/cover-fonts/..%2F..%2F.env')->assertNotFound();
    $this->actingAs($user)->get('/cover-fonts/montserrat.black')->assertNotFound();
});

it('bundles every face with its licence, and every face sets French and Kreyòl', function () {
    $sample = 'Réussir l\'examen — Kreyòl Ayisyen éèêëçòàâôûùîïœ ÉÈÇÒÀÔ «»’';

    foreach (CoverFonts::FAMILIES as $key => $family) {
        foreach ($family['styles'] as $style => $file) {
            expect(is_file(resource_path("fonts/{$file}.ttf")))->toBeTrue("{$file} is missing");

            $metrics = CoverFonts::metrics("{$key}.{$style}");
            $missing = array_filter(mb_str_split($sample), fn ($c) => ! isset($metrics['adv'][mb_ord($c)]));

            expect($missing)->toBe([], "{$key}.{$style} lacks ".implode('', $missing));
        }

        $licence = str_contains(reset($family['styles']), 'cover/')
            ? resource_path('fonts/cover/OFL-'.explode('-', basename(reset($family['styles'])))[0].'.txt')
            : null;

        if ($licence) {
            expect(is_file($licence))->toBeTrue("{$licence} is missing")
                ->and(file_get_contents($licence))->toContain('SIL Open Font License, Version 1.1');
        }
    }
});
