<?php

use App\Covers\CoverImages;
use App\Covers\CoverLayers;
use App\Covers\CoverTemplates;
use App\Models\AdminAuditLog;
use App\Models\Book;
use App\Models\CoverTemplate;
use App\Models\ManuscriptVersion;
use App\Models\User;
use App\Platform\House\HouseAccounts;
use App\Publishing\CoverRenderer;
use App\Publishing\CoverWrap;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Cover templates (ADR — Cover backgrounds and series templates): one
 * book's cover design saved under a name and applied to others, each
 * filling its bound fields with its own text; follow, detach and update;
 * a preview with overflow flags; never the manuscript; only within one
 * account; staff changes to house books audited; uploaded finished covers
 * (Écrivain Prépa) never replaced without a choice.
 */
beforeEach(function () {
    Storage::fake('files');
});

function ctBook(User $owner, string $title, array $attributes = []): Book
{
    $book = Book::factory()->for($owner)->create(array_merge(['title' => $title, 'author_name' => 'A. Author', 'language' => 'French'], $attributes));
    $book->sections()->create(['type' => 'chapter', 'title' => 'One', 'position' => 10, 'content' => platformDoc("The words of {$title}."), 'word_count' => 4]);

    return $book->fresh();
}

function ctPng(int $w = 600, int $h = 400, array $rgb = [200, 30, 30]): string
{
    $image = imagecreatetruecolor($w, $h);
    imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/** A series design: title, a big bound series number, a custom line, a gradient, back and spine layers. */
function ctDesign(Book $book, bool $logo = false): Book
{
    $items = [
        CoverLayers::make('title', ['id' => 'title', 'x' => 0.1, 'y' => 0.1, 'width' => 0.8, 'size' => 0.05, 'color' => '#ffffff']),
        CoverLayers::make('number', ['id' => 'badge', 'x' => 0.7, 'y' => 0.7, 'width' => 0.2, 'size' => 0.12, 'color' => '#ffd700']),
        CoverLayers::make('custom', ['id' => 'line', 'text' => 'Collection Écrivain Prépa', 'x' => 0.1, 'y' => 0.9, 'width' => 0.8, 'size' => 0.02, 'color' => '#ffffff']),
    ];
    $cover = [
        'layers' => ['version' => 1, 'items' => $items],
        'backgrounds' => ['front' => ['kind' => 'linear', 'angle' => 180, 'stops' => [['color' => '#102040', 'at' => 0], ['color' => '#3050a0', 'at' => 1]]]],
        'spine_direction' => 'btt',
        'font' => 'sans',
    ];

    if ($logo) {
        $entry = CoverImages::store($book, UploadedFile::fake()->createWithContent('logo.png', ctPng()));
        $cover['images'] = [$entry];
        $cover['layers']['items'][] = CoverLayers::makeImage($entry['id'], ['id' => 'logo', 'x' => 0.1, 'y' => 0.7, 'width' => 0.2, 'height' => 0.1]);
    }

    $book->update(['cover' => array_merge($book->cover ?? [], $cover)]);
    $wraps = app(CoverWrap::class);
    $g = $wraps->forBook($book->fresh());
    $book->update(['cover' => array_merge($book->cover, ['back_layers' => $wraps->backLayersFromGenerated($book->fresh(), $g)])]);
    $book->forceFill(['print' => ['spine_color' => '#102040']])->save();

    return $book->fresh();
}

it('saves a cover design as a template: every panel, the backgrounds and spine, no pictures unless asked', function () {
    $author = User::factory()->create();
    $source = ctDesign(ctBook($author, 'Source', ['series_name' => 'Prépa', 'series_number' => 1]), logo: true);

    $this->actingAs($author)->postJson("/books/{$source->id}/cover-template", ['name' => 'Série'])
        ->assertCreated()
        ->assertJsonPath('summary.following.name', 'Série');

    $template = CoverTemplate::sole();
    $design = $template->design;
    expect($template->user_id)->toBe($author->id)
        ->and(array_column($design['layers']['items'], 'id'))->toBe(['title', 'badge', 'line'])
        ->and($design['back_layers']['items'])->not->toBeEmpty()
        ->and($design['backgrounds']['front']['kind'])->toBe('linear')
        ->and($design['spine_direction'])->toBe('btt')
        ->and($design['spine_color'])->toBe('#102040')
        ->and($design['font'])->toBe('sans')
        ->and($design)->not->toHaveKey('images')
        ->and($design['measure']['front']['title']['lines'])->toBe(1);

    // With images, the picture is copied under the template.
    $this->actingAs($author)->postJson("/books/{$source->id}/cover-template", ['name' => 'Série + logo', 'include_images' => true])->assertCreated();
    $with = CoverTemplate::where('name', 'Série + logo')->sole();
    expect($with->design['images'])->toHaveCount(1)
        ->and(Storage::disk('files')->exists($with->design['images'][0]['path']))->toBeTrue()
        ->and(str_starts_with($with->design['images'][0]['path'], "cover-templates/{$with->id}/"))->toBeTrue();

    $this->actingAs($author)->postJson("/books/{$source->id}/cover-template", ['name' => 'Série'])->assertUnprocessable();
});

it('applies a template to three books: each shows its own title and number, the manuscript untouched, old renders out of date', function () {
    $author = User::factory()->create();
    $source = ctDesign(ctBook($author, 'Source', ['series_name' => 'Prépa', 'series_number' => 1]), logo: true);
    $template = app(CoverTemplates::class)->save($source, $author, 'Série', images: true);
    $books = collect([2, 3, 4])->map(fn ($n) => ctBook($author, "Tome {$n}", ['series_name' => 'Prépa', 'series_number' => $n]));
    $covers = app(CoverRenderer::class);
    $wraps = app(CoverWrap::class);
    $before = $books->map(fn ($b) => [$covers->version($b), $wraps->fingerprint($b), $b->sections()->first()->content]);
    $versions = ManuscriptVersion::count();

    $this->actingAs($author)->postJson("/cover-templates/{$template->id}/apply", ['books' => $books->pluck('id')->all()])
        ->assertOk()
        ->assertJsonPath('results.0.ok', true)
        ->assertJsonPath('results.2.ok', true);

    foreach ($books as $i => $book) {
        $book = $book->fresh();
        $texts = CoverLayers::texts($book);

        expect($book->cover_template_id)->toBe($template->id)
            ->and(array_column($book->cover['layers']['items'], 'source'))->toBe(['title', 'number', 'custom', 'image'])
            ->and($texts['title'])->toBe($book->title)
            ->and($texts['number'])->toBe((string) $book->series_number)
            ->and($book->printSettings()['spine_color'])->toBe('#102040')
            // Its own copy of the logo, under its own folder.
            ->and(CoverImages::all($book)[0]['path'])->toStartWith("covers/book-{$book->id}/images/")
            ->and($book->cover['layers']['items'][3]['image'])->toBe(CoverImages::all($book)[0]['id'])
            ->and($covers->version($book))->not->toBe($before[$i][0])
            ->and($wraps->fingerprint($book))->not->toBe($before[$i][1])
            ->and($book->sections()->first()->content)->toBe($before[$i][2]);

        // It draws: the title is set from the book's own.
        expect(strlen($covers->render($covers->settings($book))))->toBeGreaterThan(1000);
    }

    expect(ManuscriptVersion::count())->toBe($versions);

    // A layer draws the book's own number: two books' badges differ.
    $a = CoverRenderer::place($books[0]->fresh()->cover['layers']['items'][1], ['width' => 1600, 'height' => 2560], CoverLayers::texts($books[0]->fresh()));
    $b = CoverRenderer::place($books[1]->fresh()->cover['layers']['items'][1], ['width' => 1600, 'height' => 2560], CoverLayers::texts($books[1]->fresh()));
    expect($a['lines'][0]['glyphs'][0]['char'])->toBe('2')->and($b['lines'][0]['glyphs'][0]['char'])->toBe('3');
});

it('refuses design edits on a following book until detached, and rewrites followers when the template is updated', function () {
    $author = User::factory()->create();
    $source = ctDesign(ctBook($author, 'Source'));
    $template = app(CoverTemplates::class)->save($source, $author, 'Série');
    $book = ctBook($author, 'Follower');
    app(CoverTemplates::class)->apply($template, $book, $author);

    $this->actingAs($author)->patchJson("/books/{$book->id}/cover", ['layers' => null])->assertUnprocessable()->assertJsonValidationErrors('cover_template');
    $this->actingAs($author)->patchJson("/books/{$book->id}/cover", ['backgrounds' => null])->assertUnprocessable();
    $this->actingAs($author)->postJson("/books/{$book->id}/cover/back-layers")->assertUnprocessable();
    // Its own text is still its own.
    $this->actingAs($author)->patchJson("/books/{$book->id}/cover", ['tagline' => 'Mine'])->assertOk();

    // The template changes: the follower follows.
    $items = $source->cover['layers']['items'];
    $items[0]['color'] = '#ff0000';
    $source->update(['cover' => array_merge($source->cover, ['layers' => ['version' => 1, 'items' => $items]])]);
    $this->actingAs($author)->putJson("/cover-templates/{$template->id}", ['book' => $source->id])->assertOk()->assertJsonPath('updated', 2);
    expect($book->fresh()->cover['layers']['items'][0]['color'])->toBe('#ff0000')
        ->and($book->fresh()->cover_template_version)->toBe(2)
        ->and($book->fresh()->cover['tagline'])->toBe('Mine');

    // Detached, it keeps its cover and is its own again.
    $this->actingAs($author)->postJson("/books/{$book->id}/cover-template/detach")->assertOk();
    expect($book->fresh()->cover_template_id)->toBeNull()->and($book->fresh()->cover['layers']['items'][0]['color'])->toBe('#ff0000');
    $this->actingAs($author)->patchJson("/books/{$book->id}/cover", ['layers' => null])->assertOk();

    // Restoring puts back the cover from before the template.
    $this->actingAs($author)->postJson("/books/{$book->id}/cover-template/restore")->assertOk();
    expect($book->fresh()->cover)->not->toHaveKey('backgrounds')->and($book->fresh()->cover)->not->toHaveKey('pre_template');

    // Deleting a template leaves its followers' covers.
    $other = ctBook($author, 'Other');
    app(CoverTemplates::class)->apply($template, $other, $author);
    $this->actingAs($author)->deleteJson("/cover-templates/{$template->id}")->assertOk();
    expect($other->fresh()->cover_template_id)->toBeNull()->and($other->fresh()->cover['layers'])->not->toBeNull();
});

it('previews each book with a thumbnail and flags where its longer title overflows the template', function () {
    $author = User::factory()->create();
    $source = ctDesign(ctBook($author, 'Court'));
    $template = app(CoverTemplates::class)->save($source, $author, 'Série');
    $short = ctBook($author, 'Bref');
    $long = ctBook($author, 'Réussir l’examen de philosophie du baccalauréat haïtien, avec corrigés détaillés et méthode complète');

    $response = $this->actingAs($author)->postJson("/cover-templates/{$template->id}/preview", ['books' => [$short->id, $long->id]])->assertOk();
    $rows = collect($response->json('books'))->keyBy('id');

    expect($rows[$short->id]['flags'])->toBe([])
        ->and($rows[$short->id]['thumb'])->toStartWith('data:image/jpeg;base64,')
        ->and(collect($rows[$long->id]['flags'])->contains(fn ($f) => str_contains($f, 'The title wraps to')))->toBeTrue()
        ->and(collect($rows[$long->id]['flags'])->contains(fn ($f) => str_contains($f, 'runs into')))->toBeTrue();

    // Nothing changed.
    expect($long->fresh()->cover_template_id)->toBeNull()->and($long->fresh()->cover)->toBe($long->cover);
});

it('keeps templates within their account: no applying to, previewing or changing another author’s', function () {
    $author = User::factory()->create();
    $stranger = User::factory()->create();
    $template = app(CoverTemplates::class)->save(ctDesign(ctBook($author, 'Source')), $author, 'Série');
    $theirs = ctBook($stranger, 'Theirs');
    $mine = ctBook($author, 'Mine');

    $this->actingAs($author)->postJson("/cover-templates/{$template->id}/apply", ['books' => [$theirs->id]])
        ->assertOk()->assertJsonPath('results.0.ok', false);
    expect($theirs->fresh()->cover_template_id)->toBeNull();

    $this->actingAs($stranger)->postJson("/cover-templates/{$template->id}/apply", ['books' => [$mine->id]])->assertOk()->assertJsonPath('results.0.ok', false);
    $this->actingAs($stranger)->postJson("/cover-templates/{$template->id}/preview", ['books' => [$mine->id]])->assertForbidden();
    $this->actingAs($stranger)->deleteJson("/cover-templates/{$template->id}")->assertForbidden();
    $this->actingAs($stranger)->putJson("/cover-templates/{$template->id}", ['book' => $theirs->id])->assertForbidden();
    $this->actingAs($stranger)->getJson('/cover-templates')->assertOk()->assertJsonCount(0, 'templates');
    expect($mine->fresh()->cover_template_id)->toBeNull();

    // A book of the account that the person may only read is skipped.
    $this->actingAs($author)->postJson("/cover-templates/{$template->id}/preview", ['books' => [$theirs->id]])
        ->assertOk()->assertJsonPath('books.0.message', 'Not found.');
});

it('never replaces an uploaded finished front or back without a choice; keep or replace, and restore', function () {
    $house = app(HouseAccounts::class)->create('ecrivain-prepa', 'Écrivain Prépa', 'Test imprint');
    $owner = platformAdmin();
    $source = ctDesign(ctBook($house, 'Prépa source'));
    $template = app(CoverTemplates::class)->save($source, $owner, 'Prépa');
    $disk = Storage::disk('files');

    $prepa = function (string $title) use ($house, $disk) {
        $book = ctBook($house, $title, ['series_name' => 'Écrivain Prépa', 'series_number' => 7]);
        $disk->put("covers/book-{$book->id}/front.png", ctPng(800, 1280));
        $disk->put("covers/book-{$book->id}/back.png", ctPng(800, 1280, [20, 20, 20]));
        $book->update(['cover' => ['layout' => 'finished', 'artwork' => "covers/book-{$book->id}/front.png", 'back_artwork' => "covers/book-{$book->id}/back.png"]]);

        return $book->fresh();
    };
    [$a, $b, $c] = [$prepa('Prépa A'), $prepa('Prépa B'), $prepa('Prépa C')];
    $untouched = $a->cover;

    // A reason is required for house books.
    $this->actingAs($owner)->postJson("/cover-templates/{$template->id}/apply", ['books' => [$a->id]])->assertUnprocessable()->assertJsonValidationErrors('reason');

    // Without a choice: skipped, said why, nothing changed.
    $this->actingAs($owner)->postJson("/cover-templates/{$template->id}/apply", ['books' => [$a->id], 'reason' => 'Series look'])
        ->assertOk()
        ->assertJsonPath('results.0.ok', false)
        ->assertJsonPath('results.0.message', fn ($m) => str_contains($m, 'uploaded finished front and back'));
    expect($a->fresh()->cover)->toBe($untouched)->and(AdminAuditLog::where('action', 'house.cover_template_applied')->count())->toBe(0);

    // The preview says so too.
    $this->actingAs($owner)->postJson("/cover-templates/{$template->id}/preview", ['books' => [$a->id]])
        ->assertOk()->assertJsonPath('books.0.needs_choice', true)->assertJsonPath('books.0.uploaded.front', true);

    // Keep: the uploaded artwork stays as the background, the template's type over it.
    $this->actingAs($owner)->postJson("/cover-templates/{$template->id}/apply", ['books' => [$b->id], 'artwork' => 'keep', 'reason' => 'Series look'])
        ->assertOk()->assertJsonPath('results.0.ok', true);
    expect($b->fresh()->cover['artwork'])->toBe("covers/book-{$b->id}/front.png")
        ->and($b->fresh()->cover['back_artwork'])->toBe("covers/book-{$b->id}/back.png")
        ->and($b->fresh()->cover['layers']['items'])->toHaveCount(3);

    // Replace: the template's design instead; the files stay, and restore brings them back.
    $this->actingAs($owner)->postJson("/cover-templates/{$template->id}/apply", ['books' => [$c->id], 'artwork' => 'replace', 'reason' => 'Series look'])
        ->assertOk()->assertJsonPath('results.0.ok', true);
    expect($c->fresh()->cover)->not->toHaveKey('artwork')
        ->and($c->fresh()->cover)->not->toHaveKey('back_artwork')
        ->and($disk->exists("covers/book-{$c->id}/front.png"))->toBeTrue();

    $audits = AdminAuditLog::where('action', 'house.cover_template_applied')->get();
    expect($audits)->toHaveCount(2)
        ->and($audits->every(fn ($row) => $row->actor_id === $owner->id && $row->reason === 'Series look'))->toBeTrue()
        ->and($audits->last()->after['artwork_choice'])->toBe('replace')
        ->and($audits->last()->before['artwork'])->toBe("covers/book-{$c->id}/front.png");

    $this->actingAs($owner)->postJson("/books/{$c->id}/cover-template/restore")->assertOk();
    expect($c->fresh()->cover['artwork'])->toBe("covers/book-{$c->id}/front.png")
        ->and($c->fresh()->cover['layout'])->toBe('finished')
        ->and($c->fresh()->cover)->not->toHaveKey('layers');
});

it('applies from the shelf’s bulk bar and the console’s House catalog, audited for house books', function () {
    $author = User::factory()->create();
    $template = app(CoverTemplates::class)->save(ctDesign(ctBook($author, 'Source')), $author, 'Série');
    $books = [ctBook($author, 'One'), ctBook($author, 'Two')];

    $this->actingAs($author)->postJson('/shelf/bulk', ['action' => 'cover_template', 'cover_template_id' => $template->id, 'books' => array_map(fn ($b) => $b->id, $books)])
        ->assertOk()
        ->assertJsonPath('done', 2);
    expect($books[1]->fresh()->cover_template_id)->toBe($template->id)->and(AdminAuditLog::count())->toBe(0);

    $this->actingAs($author)->getJson('/cover-templates')->assertOk()->assertJsonPath('templates.0.name', 'Série')->assertJsonPath('templates.0.books', 3);

    $house = app(HouseAccounts::class)->create('ecrivain-prepa', 'Écrivain Prépa', 'Test imprint');
    $owner = platformAdmin();
    $houseTemplate = app(CoverTemplates::class)->save(ctDesign(ctBook($house, 'House source')), $owner, 'Maison');
    $houseBook = ctBook($house, 'House book');

    $this->actingAs($owner)
        ->post('/admin/house/bulk', ['action' => 'cover_template', 'cover_template_id' => $houseTemplate->id, 'books' => [$houseBook->id, $books[0]->id], 'reason' => 'One look for the series'])
        ->assertRedirect()
        ->assertSessionHas('house_results', fn ($r) => $r['rows'][0]['ok'] && ! $r['rows'][1]['ok']);

    expect($houseBook->fresh()->cover_template_id)->toBe($houseTemplate->id)
        ->and(AdminAuditLog::where('action', 'house.cover_template_applied')->count())->toBe(1);

    $this->actingAs($owner)->postJson('/shelf/bulk', ['action' => 'cover_template', 'cover_template_id' => $houseTemplate->id, 'books' => [$houseBook->id], 'follow' => false, 'reason' => 'Again, copied'])
        ->assertOk()->assertJsonPath('done', 1);
    expect(AdminAuditLog::where('action', 'house.cover_template_applied')->count())->toBe(2)
        ->and($houseBook->fresh()->cover_template_id)->toBeNull();

    // Support staff may neither save nor apply.
    $support = platformAdmin('support');
    $this->actingAs($support)->postJson("/books/{$houseBook->id}/cover-template", ['name' => 'Nope'])->assertForbidden();
});

it('shows the templates and what a book follows in Cover Studio', function () {
    $author = User::factory()->create();
    $source = ctDesign(ctBook($author, 'Source'));
    app(CoverTemplates::class)->save($source, $author, 'Série');

    $this->actingAs($author)->getJson("/books/{$source->id}/design")
        ->assertOk()
        ->assertJsonPath('cover.template.following.name', 'Série')
        ->assertJsonPath('cover.template.templates.0.summary', 'Layers on front and back · gradient on the front')
        ->assertJsonPath('cover.backgrounds.front.kind', 'linear');
});
