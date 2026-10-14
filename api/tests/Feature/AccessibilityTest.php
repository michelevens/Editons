<?php

use App\Http\Controllers\DesignController;
use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\AccessibilityAudit;

/**
 * Accessibility checks.
 *
 * Each is computed from the manuscript on every request, so these are mostly
 * about catching the real defect rather than about storage.
 */
function auditedBook(array $blocks, array $attributes = []): Book
{
    $book = Book::factory()->for(User::factory())->create(array_merge([
        'language' => 'English (US)',
    ], $attributes));

    ManuscriptSection::factory()->for($book)->create([
        'title' => 'Chapter One',
        'type' => 'chapter',
        'position' => 0,
        'content' => ['type' => 'doc', 'content' => $blocks],
    ]);

    return $book->fresh();
}

function checkFor(Book $book, string $key): array
{
    return collect(app(AccessibilityAudit::class)->run($book)['checks'])
        ->firstWhere('key', $key);
}

function para(string $text): array
{
    return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
}

it('passes a clean manuscript', function () {
    $book = auditedBook([
        ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'One']]],
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Two']]],
        para('Some prose.'),
    ]);

    $checks = app(AccessibilityAudit::class)->run($book)['checks'];

    expect(collect($checks)->every(fn (array $c) => $c['passed']))->toBeTrue();
});

it('catches a skipped heading level', function () {
    // Screen reader users navigate by heading; a skipped level reads as a
    // section that is missing.
    $book = auditedBook([
        ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'One']]],
        ['type' => 'heading', 'attrs' => ['level' => 3], 'content' => [['type' => 'text', 'text' => 'Three']]],
    ]);

    $check = checkFor($book, 'heading_order');

    expect($check['passed'])->toBeFalse()
        ->and($check['detail'])->toContain('heading 1 to heading 3');
});

it('allows a heading level going back up', function () {
    // h3 then h2 starts a new sibling section, which is correct. Only a
    // descending jump leaves a level with nothing in it.
    $book = auditedBook([
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'A']]],
        ['type' => 'heading', 'attrs' => ['level' => 3], 'content' => [['type' => 'text', 'text' => 'B']]],
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'C']]],
    ]);

    expect(checkFor($book, 'heading_order')['passed'])->toBeTrue();
});

it('finds images with no description', function () {
    $book = auditedBook([
        ['type' => 'image', 'attrs' => ['src' => '/a.png', 'alt' => 'A chart of closures']],
        ['type' => 'image', 'attrs' => ['src' => '/b.png']],
    ]);

    $result = app(AccessibilityAudit::class)->run($book);

    expect(collect($result['checks'])->firstWhere('key', 'image_alt')['passed'])->toBeFalse()
        ->and($result['images'])->toHaveCount(2)
        ->and($result['images'][1]['alt'])->toBe('');
});

it('treats empty alt as a decision, not a gap, once it is set', function () {
    // An empty alt declares the image decorative. That is a real answer, and
    // the only way to say it — but it is indistinguishable from unset in the
    // document, so the check reports it as missing and the author decides.
    $book = auditedBook([['type' => 'image', 'attrs' => ['src' => '/a.png', 'alt' => '']]]);

    expect(checkFor($book, 'image_alt')['passed'])->toBeFalse();
});

it('passes when there are no images at all', function () {
    $book = auditedBook([para('Just prose.')]);

    $check = checkFor($book, 'image_alt');

    expect($check['passed'])->toBeTrue()
        ->and($check['detail'])->toContain('No images');
});

it('catches a table with no header row', function () {
    $book = auditedBook([[
        'type' => 'table',
        'content' => [[
            'type' => 'tableRow',
            'content' => [['type' => 'tableCell', 'content' => [para('Data')]]],
        ]],
    ]]);

    expect(checkFor($book, 'table_headers')['passed'])->toBeFalse();
});

it('passes a table that has one', function () {
    $book = auditedBook([[
        'type' => 'table',
        'content' => [[
            'type' => 'tableRow',
            'content' => [['type' => 'tableHeader', 'content' => [para('Year')]]],
        ]],
    ]]);

    expect(checkFor($book, 'table_headers')['passed'])->toBeTrue();
});

it('catches link text that says nothing', function () {
    // Links are often read as a list on their own, out of context.
    $book = auditedBook([[
        'type' => 'paragraph',
        'content' => [[
            'type' => 'text',
            'text' => 'click here',
            'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com']]],
        ]],
    ]]);

    expect(checkFor($book, 'link_text')['passed'])->toBeFalse();
});

it('catches a bare URL used as its own link text', function () {
    $book = auditedBook([[
        'type' => 'paragraph',
        'content' => [[
            'type' => 'text',
            'text' => 'https://example.com',
            'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com']]],
        ]],
    ]]);

    expect(checkFor($book, 'link_text')['passed'])->toBeFalse();
});

it('passes a link that describes where it goes', function () {
    $book = auditedBook([[
        'type' => 'paragraph',
        'content' => [[
            'type' => 'text',
            'text' => 'the Copyright Office registration page',
            'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://copyright.gov']]],
        ]],
    ]]);

    expect(checkFor($book, 'link_text')['passed'])->toBeTrue();
});

it('always has a language, because the column cannot be empty', function () {
    // The check stays — dc:language is what a screen reader reads to pick a
    // voice, and it is worth showing the author what will go into the file.
    // But it cannot currently fail: the column is NOT NULL with a default,
    // so this asserts the guarantee rather than pretending to test a branch
    // that no book can reach.
    $book = auditedBook([para('Prose.')]);

    $check = checkFor($book, 'language');

    expect($check['passed'])->toBeTrue()
        ->and($check['detail'])->toBe('English (US)');
});

it('measures contrast between the cover title and its background', function () {
    // Sand is a pale background with dark ink and passes; every dark palette
    // with white ink passes too. The check exists for a future palette that
    // would not.
    $book = auditedBook([para('Prose.')], ['cover' => ['palette' => 1]]);

    $check = checkFor($book, 'cover_contrast');

    expect($check['passed'])->toBeTrue()
        ->and($check['detail'])->toContain(':1');
});

it('declines to measure contrast over uploaded artwork', function () {
    // The title sits over a photograph whose luminance varies behind the
    // text. One number would be a guess presented as a measurement.
    $book = auditedBook([para('Prose.')], ['cover' => ['artwork' => 'covers/x.jpg']]);

    $check = checkFor($book, 'cover_contrast');

    expect($check['passed'])->toBeTrue()
        ->and($check['detail'])->toContain('cannot be measured');
});

it('keeps every shipped palette above the contrast threshold', function () {
    // A palette an author can choose must not fail the check the same screen
    // then shows them.
    foreach (AccessibilityAudit::PALETTES as $index => $palette) {
        $book = auditedBook([para('Prose.')], ['cover' => ['palette' => $index]]);

        expect(checkFor($book, 'cover_contrast')['passed'])
            ->toBeTrue("The {$palette['name']} palette fails its own contrast check.");
    }
});

it('uses the same palettes the cover editor offers', function () {
    // They are defined in two places so the audit does not depend on a
    // controller. This is what stops them drifting apart.
    $reflection = new ReflectionClass(DesignController::class);

    expect($reflection->getConstant('PALETTES'))->toBe(AccessibilityAudit::PALETTES);
});

it('ignores parts, which hold chapters rather than prose', function () {
    $book = auditedBook([para('Real prose.')]);

    ManuscriptSection::factory()->for($book)->create([
        'title' => 'Part I',
        'type' => 'part',
        'position' => 10,
        'content' => ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'P']]],
            ['type' => 'heading', 'attrs' => ['level' => 4], 'content' => [['type' => 'text', 'text' => 'Q']]],
        ]],
    ]);

    expect(checkFor($book->fresh(), 'heading_order')['passed'])->toBeTrue();
});

// --- Over HTTP ----------------------------------------------------------

it('writes a description and sees the check pass', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create(['language' => 'English (US)']);

    $section = ManuscriptSection::factory()->for($book)->create([
        'type' => 'chapter',
        'content' => ['type' => 'doc', 'content' => [
            ['type' => 'image', 'attrs' => ['src' => '/figure.png']],
        ]],
    ]);

    $response = $this->actingAs($user)
        ->patchJson("/sections/{$section->id}/image-description", [
            'index' => 0,
            'alt' => 'A line chart of practice closures over three years',
        ])
        ->assertOk();

    expect(collect($response->json('checks'))->firstWhere('key', 'image_alt')['passed'])
        ->toBeTrue()
        ->and($section->fresh()->content['content'][0]['attrs']['alt'])
        ->toBe('A line chart of practice closures over three years');
});

it('versions a description like any other edit', function () {
    // It is a change to the manuscript, not a setting, so it should be
    // revertible the same way.
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    $section = ManuscriptSection::factory()->for($book)->create([
        'type' => 'chapter',
        'content' => ['type' => 'doc', 'content' => [
            ['type' => 'image', 'attrs' => ['src' => '/figure.png']],
        ]],
    ]);

    $this->actingAs($user)
        ->patchJson("/sections/{$section->id}/image-description", ['index' => 0, 'alt' => 'A chart'])
        ->assertOk();

    expect($section->versions()->latest('version_number')->first()->label)
        ->toContain('Image description');
});

it('refuses to describe the wrong image when the manuscript has moved', function () {
    // The index addresses a position, not an identity. If the client's view
    // is stale that position may now hold a different image, and writing
    // somebody's caption onto the wrong figure is worse than refusing.
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    $section = ManuscriptSection::factory()->for($book)->create([
        'type' => 'chapter',
        'content' => ['type' => 'doc', 'content' => [
            ['type' => 'image', 'attrs' => ['src' => '/now-a-different-figure.png']],
        ]],
    ]);

    $this->actingAs($user)
        ->patchJson("/sections/{$section->id}/image-description", [
            'index' => 0,
            'alt' => 'A chart',
            'src' => '/the-one-i-was-looking-at.png',
        ])
        ->assertStatus(409);

    expect($section->fresh()->content['content'][0]['attrs']['alt'] ?? null)->toBeNull();
});

it('accepts an empty description, which means decorative', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    $section = ManuscriptSection::factory()->for($book)->create([
        'type' => 'chapter',
        'content' => ['type' => 'doc', 'content' => [
            ['type' => 'image', 'attrs' => ['src' => '/border.png', 'alt' => 'Something']],
        ]],
    ]);

    $this->actingAs($user)
        ->patchJson("/sections/{$section->id}/image-description", ['index' => 0, 'alt' => ''])
        ->assertOk();

    expect($section->fresh()->content['content'][0]['attrs']['alt'])->toBe('');
});

it('stops a stranger describing an image in somebody else\'s book', function () {
    $owner = User::factory()->create();
    $section = ManuscriptSection::factory()
        ->for(Book::factory()->for($owner))
        ->create(['content' => ['type' => 'doc', 'content' => [
            ['type' => 'image', 'attrs' => ['src' => '/a.png']],
        ]]]);

    $this->actingAs(User::factory()->create())
        ->patchJson("/sections/{$section->id}/image-description", ['index' => 0, 'alt' => 'x'])
        ->assertForbidden();
});
