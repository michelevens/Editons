<?php

use App\Models\Book;
use App\Models\User;
use App\Publishing\Anatomy;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The Book parts endpoints and the publishing metadata on Book Settings.
 */

function partsOwner(): array
{
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create(['language' => 'French', 'book_type' => 'educational']);

    return [$user, $book];
}

it('serves the checklist to the owner and nobody else', function () {
    [$user, $book] = partsOwner();

    $this->actingAs($user)->getJson("/books/{$book->id}/parts")
        ->assertOk()
        ->assertJsonPath('profile', 'educational')
        ->assertJsonPath('language', 'fr')
        ->assertJsonFragment(['key' => 'glossary', 'name' => 'Glossaire']);

    $this->actingAs(User::factory()->create())->getJson("/books/{$book->id}/parts")->assertForbidden();
    $this->actingAs(User::factory()->create())->postJson("/books/{$book->id}/parts", ['part' => 'preface'])->assertForbidden();
});

it('adds a part from its template and refuses a duplicate', function () {
    [$user, $book] = partsOwner();

    $this->actingAs($user)->postJson("/books/{$book->id}/parts", ['part' => 'preface'])
        ->assertCreated()
        ->assertJsonPath('section.title', 'Avant-propos')
        ->assertJsonPath('section.part', 'preface');

    $this->actingAs($user)->postJson("/books/{$book->id}/parts", ['part' => 'preface'])
        ->assertStatus(422);

    $this->actingAs($user)->postJson("/books/{$book->id}/parts", ['part' => 'title_page'])
        ->assertStatus(422);

    $this->actingAs($user)->postJson("/books/{$book->id}/parts", ['part' => 'not_a_part'])
        ->assertStatus(422);
});

it('previews, then reorders, and refuses a stale preview', function () {
    [$user, $book] = partsOwner();
    $book->sections()->create(['type' => 'back_matter', 'title' => 'Index', 'position' => 10]);
    $book->sections()->create(['type' => 'front_matter', 'title' => 'Avant-propos', 'position' => 20]);

    $preview = $this->actingAs($user)->getJson("/books/{$book->id}/parts/order")->assertOk()->json();
    expect($preview['moves'])->toHaveCount(2);

    $this->actingAs($user)->postJson("/books/{$book->id}/parts/order", ['fingerprint' => str_repeat('0', 40)])
        ->assertStatus(409)
        ->assertJsonPath('preview.fingerprint', $preview['fingerprint']);

    $this->actingAs($user)->postJson("/books/{$book->id}/parts/order", ['fingerprint' => $preview['fingerprint']])
        ->assertOk()
        ->assertJsonPath('in_order', true);

    expect($book->sections()->orderBy('position')->pluck('title')->all())->toBe(['Avant-propos', 'Index']);
});

it('names a section’s part from the binder', function () {
    [$user, $book] = partsOwner();
    $section = $book->sections()->create(['type' => 'chapter', 'title' => 'Avant de commencer', 'position' => 10]);

    $this->actingAs($user)->patchJson("/sections/{$section->id}/part", ['part' => 'introduction'])
        ->assertOk()
        ->assertJson(['part' => 'introduction', 'type' => 'front_matter']);

    $this->actingAs($user)->patchJson("/sections/{$section->id}/part", ['part' => 'back_cover_copy'])
        ->assertStatus(422);

    $this->actingAs($user)->patchJson("/sections/{$section->id}/part", ['part' => null])
        ->assertOk()
        ->assertJson(['part' => null]);

    $this->actingAs(User::factory()->create())->patchJson("/sections/{$section->id}/part", ['part' => 'preface'])
        ->assertForbidden();
});

it('sends each binder node’s part and the part menu to the editor', function () {
    [$user, $book] = partsOwner();
    $book->sections()->create(['type' => 'front_matter', 'title' => 'Préface', 'position' => 10]);

    $this->actingAs($user)->get("/books/{$book->id}/edit")
        ->assertInertia(fn (Assert $page) => $page
            ->where('binder.0.part', 'foreword')
            ->has('book_parts')
            ->where('book_parts.0.value', 'half_title'));
});

it('saves publishing metadata, merging and trimming it', function () {
    [$user, $book] = partsOwner();

    $this->actingAs($user)->patch("/books/{$book->id}", [
        'series_name' => 'Écrivain Prépa',
        'series_number' => 4,
        'publication' => [
            'publisher' => '  Éditions Écrivain ',
            'isbn_print' => '978-0-306-40615-7',
            'legal_deposit' => 'Dépôt légal : 2026, Bibliothèque nationale d’Haïti',
            'credits' => [['role' => 'Couverture', 'name' => 'Studio Lakay'], ['role' => '', 'name' => '']],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->actingAs($user)->patch("/books/{$book->id}", [
        'publication' => ['copyright_year' => 2026],
    ])->assertSessionHasNoErrors();

    $book->refresh();

    expect($book->series_name)->toBe('Écrivain Prépa')
        ->and($book->series_number)->toBe(4)
        ->and($book->publication()['publisher'])->toBe('Éditions Écrivain')
        ->and($book->publication()['isbn_print'])->toBe('978-0-306-40615-7')
        ->and($book->publication()['copyright_year'])->toBe(2026)
        ->and($book->publication()['credits'])->toEqual([['role' => 'Couverture', 'name' => 'Studio Lakay']]);
});

it('refuses an ISBN that fails its checksum, and one number for two formats', function () {
    [$user, $book] = partsOwner();

    $this->actingAs($user)->patch("/books/{$book->id}", [
        'publication' => ['isbn_epub' => '9780306406158'],
    ])->assertSessionHasErrors('publication.isbn_epub');

    $this->actingAs($user)->patch("/books/{$book->id}", [
        'publication' => ['isbn_print' => '9780306406157', 'isbn_epub' => '978-0-306-40615-7'],
    ])->assertSessionHasErrors('publication.isbn_epub');

    $this->actingAs($user)->patch("/books/{$book->id}", [
        'publication' => ['copyright_year' => 99],
    ])->assertSessionHasErrors('publication.copyright_year');

    expect($book->fresh()->publication()['isbn_epub'])->toBeNull();
});

it('shows the checklist and metadata on Book Settings', function () {
    [$user, $book] = partsOwner();

    $this->actingAs($user)->get("/books/{$book->id}/settings")
        ->assertInertia(fn (Assert $page) => $page
            ->component('Books/Settings')
            ->has('anatomy.parts')
            ->where('anatomy.profile', 'educational')
            ->has('book.publication')
            ->has('copyright_preview'));
});

it('copies a valid legacy ISBN into the print ISBN when migrating', function () {
    // The migration's rule, applied by the model default: a book whose only
    // ISBN is the legacy column still prints it as the print ISBN.
    [$user, $book] = partsOwner();
    $book->forceFill(['isbn' => '9780306406157', 'publication' => null])->save();

    expect(Anatomy::isbns($book->fresh())['print'])->toBe('9780306406157');
});
