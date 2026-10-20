<?php

use App\Models\Book;
use App\Models\User;

it('applies defaults for settings a book has never saved', function () {
    // A book created before a toggle existed must still answer for it, or
    // every screen reading that key gets undefined.
    $book = Book::factory()->for(User::factory())->create(['settings' => null]);

    expect($book->settings())->toBe([
        'autosave' => true,
        'inline_ai' => true,
        'review_on_save' => false,
        'citation_style' => 'APA',
    ]);
});

it('merges a partial settings update instead of replacing it', function () {
    // The form sends one toggle at a time. Replacing the column would
    // silently reset the others, which looks like the switches moving on
    // their own.
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create([
        'settings' => ['autosave' => false, 'inline_ai' => false],
    ]);

    $this->actingAs($user)
        ->patch("/books/{$book->id}", ['settings' => ['review_on_save' => true]])
        ->assertRedirect();

    expect($book->refresh()->settings())->toBe([
        'autosave' => false,
        'inline_ai' => false,
        'review_on_save' => true,
        'citation_style' => 'APA',
    ]);
});

it('saves the book details', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    $this->actingAs($user)->patch("/books/{$book->id}", [
        'title' => 'A Better Title',
        'language' => 'English (UK)',
        'target_words' => 90000,
    ])->assertRedirect();

    $book->refresh();

    expect($book->title)->toBe('A Better Title')
        ->and($book->language)->toBe('English (UK)')
        ->and($book->target_words)->toBe(90000);
});

it('archives a book without deleting it', function () {
    // Archive is a status, not a soft delete: the book stays readable and
    // restorable, which is what the shelf's Archived filter depends on.
    $user = User::factory()->create(['plan' => 'author']);
    $book = Book::factory()->for($user)->create();

    $this->actingAs($user)
        ->patch("/books/{$book->id}", ['status' => 'archived'])
        ->assertRedirect();

    expect($book->refresh()->status)->toBe('archived')
        ->and($book->trashed())->toBeFalse()
        ->and(Book::find($book->id))->not->toBeNull();
});

it('filters the shelf by status', function () {
    $user = User::factory()->create(['plan' => 'studio']);
    Book::factory()->for($user)->create(['title' => 'Active one']);
    Book::factory()->for($user)->create(['title' => 'Old one', 'status' => 'archived']);

    // The shelf opens on 'all', so both books are listed. Opening on a
    // filtered view would hide a book without saying so, which is the wrong
    // first impression of somebody's own shelf.
    $all = $this->actingAs($user)->get('/books')->viewData('page')['props'];

    expect($all['books'])->toHaveCount(2)
        ->and($all['counts']['archived'])->toBe(1)
        ->and($all['counts']['active'])->toBe(1);

    $active = $this->actingAs($user)
        ->get('/books?filter=active')
        ->viewData('page')['props'];

    expect($active['books'])->toHaveCount(1)
        ->and($active['books'][0]['title'])->toBe('Active one');

    $archived = $this->actingAs($user)
        ->get('/books?filter=archived')
        ->viewData('page')['props'];

    expect($archived['books'])->toHaveCount(1)
        ->and($archived['books'][0]['title'])->toBe('Old one');
});

it('enforces the plan book limit on the server', function () {
    // The UI disables the button; this is the control. A disabled button is
    // a hint, not a limit.
    $user = User::factory()->create(['plan' => 'free']);
    Book::factory()->for($user)->create();

    $this->actingAs($user)
        ->post('/books', ['title' => 'One too many'])
        ->assertForbidden();

    expect($user->books()->count())->toBe(1);
});

it('will not let the last active book be archived from the screen', function () {
    // Archiving it would leave the author on an empty shelf with nowhere to
    // go, so the screen is told not to offer it.
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    $props = $this->actingAs($user)
        ->get("/books/{$book->id}/settings")
        ->viewData('page')['props'];

    expect($props['can_archive'])->toBeFalse();
});

it('offers archiving once a second book exists', function () {
    $user = User::factory()->create(['plan' => 'author']);
    $book = Book::factory()->for($user)->create();
    Book::factory()->for($user)->create();

    $props = $this->actingAs($user)
        ->get("/books/{$book->id}/settings")
        ->viewData('page')['props'];

    expect($props['can_archive'])->toBeTrue();
});

it('refuses to show another author\'s settings', function () {
    $book = Book::factory()->for(User::factory())->create();

    $this->actingAs(User::factory()->create())
        ->get("/books/{$book->id}/settings")
        ->assertForbidden();
});
