<?php

use App\Models\Book;
use App\Models\ManuscriptVersion;
use App\Models\User;
use App\Platform\House\HouseAccounts;
use App\Platform\PlatformSettings;

/*
 * Who can do what with a house book (ADR — house catalog): the platform
 * owner edits it in the normal studio as themselves — a permission, not
 * "View as" — and every save is theirs; support reads; authors see nothing.
 */

beforeEach(function () {
    $this->house = app(HouseAccounts::class)->create('ecrivain-prepa', 'Écrivain Prépa', 'Test imprint');
    $this->book = Book::factory()->for($this->house)->create(['title' => 'Bac Physique']);
    $this->section = $this->book->sections()->create(['type' => 'chapter', 'title' => 'One', 'position' => 10, 'content' => platformDoc('Avant.')]);
});

it('lets the platform owner edit a house book, and saves the version as them', function () {
    $owner = platformAdmin();

    expect($owner->can('view', $this->book))->toBeTrue()
        ->and($owner->can('update', $this->book))->toBeTrue()
        ->and($owner->can('manage', $this->book))->toBeTrue()
        ->and($owner->can('delete', $this->book))->toBeFalse();

    $this->actingAs($owner)
        ->putJson("/sections/{$this->section->id}/content", ['content' => platformDoc('Une phrase.'), 'reason' => 'manual'])
        ->assertOk();

    $version = ManuscriptVersion::where('manuscript_section_id', $this->section->id)->latest('id')->sole();
    expect($version->user_id)->toBe($owner->id)
        ->and($version->impersonator_id)->toBeNull();
});

it('lets support open a house book but not change it', function () {
    $support = platformAdmin('support');

    expect($support->can('view', $this->book))->toBeTrue()
        ->and($support->can('update', $this->book))->toBeFalse();

    $this->actingAs($support)->get("/books/{$this->book->id}/edit")->assertOk();
    $this->actingAs($support)
        ->putJson("/sections/{$this->section->id}/content", ['content' => platformDoc('Non.')])
        ->assertForbidden();
});

it('gives authors no access to house books', function () {
    $author = User::factory()->create();

    expect($author->can('view', $this->book))->toBeFalse();

    $this->actingAs($author)->get("/books/{$this->book->id}/edit")->assertForbidden();
    $this->actingAs($author)->get('/books?filter=house')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('books', 0)->where('house', null));
});

it('requires the staff member\'s two-factor when the platform does', function () {
    $owner = platformAdmin(twoFactor: false);

    expect($owner->can('update', $this->book))->toBeFalse();

    app(PlatformSettings::class)->update(['require_2fa_for_admins' => false], platformAdmin(), 'Testing');
    expect($owner->fresh()->can('update', $this->book))->toBeTrue();
});

it('shows staff a House catalog shelf and a banner in the editor', function () {
    $owner = platformAdmin();

    $this->actingAs($owner)->get('/books?filter=house')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('books', 1)
            ->where('books.0.id', $this->book->id)
            ->where('counts.house', 1)
            ->where('house.console', route('admin.house.index')));

    $this->actingAs($owner)->get('/books')
        ->assertInertia(fn ($page) => $page->where('counts.all', 0));

    $this->actingAs($owner)->get("/books/{$this->book->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('house.imprint', 'Écrivain Prépa')
            ->where('house.editor', $owner->name)
            ->where('house.can_edit', true));
});

it('shows the House catalog to all staff, with no action for support', function () {
    $this->actingAs(platformAdmin('support'))->get('/admin/house')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/House/Index')
            ->has('books', 1)
            ->where('books.0.id', $this->book->id)
            ->where('can.manage', false));

    $this->actingAs(platformAdmin('support'))
        ->post("/admin/house/books/{$this->book->id}/unpublish", ['reason' => 'Testing'])
        ->assertForbidden();

    $this->actingAs(User::factory()->create())->get('/admin/house')->assertNotFound();
});
