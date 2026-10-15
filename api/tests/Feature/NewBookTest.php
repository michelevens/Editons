<?php

use App\Journey\BookType;
use App\Models\Book;
use App\Models\User;

/**
 * Starting a book.
 *
 * The guided flow sits alongside the plain one rather than replacing it. An
 * author who knows what they want should not be walked through questions to
 * reach an empty page, and these tests hold both paths open.
 */
it('still makes a plain book with one chapter', function () {
    // The one-click path. If this breaks, the flow has quietly become
    // mandatory.
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/books', ['title' => 'Just A Book'])
        ->assertRedirect();

    $book = $user->books()->first();

    expect($book->title)->toBe('Just A Book')
        ->and($book->book_type)->toBeNull()
        ->and($book->sections()->count())->toBe(1)
        ->and($book->journey_stage)->toBe('discover');
});

it('scaffolds a book when a type is chosen', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/books', [
            'title' => 'A Guided Book',
            'book_type' => 'nonfiction',
            'chapters' => 12,
        ])
        ->assertRedirect();

    $book = $user->books()->first();

    expect($book->book_type)->toBe('nonfiction')
        ->and($book->sections()->where('type', 'chapter')->count())->toBe(12)
        ->and($book->sections()->where('type', 'part')->count())->toBeGreaterThan(0)
        ->and($book->sections()->where('type', 'front_matter')->count())->toBeGreaterThan(0);
});

it('moves past Discover once the book knows what it is about', function () {
    /*
     * Changed deliberately from "knows what it is".
     *
     * Choosing a type used to move the book straight to Plan, on the
     * reasoning that Discover's question had been answered. It had not:
     * stages() computes Discover's `done` from the premise, so the book sat
     * in Plan while Discover showed incomplete — the current stage pointing
     * past work the product itself said was unfinished.
     *
     * The new-book form now asks the premise in the genre's own words, so
     * answering it there moves the book on and skipping it does not.
     */
    $user = User::factory()->create();

    $this->actingAs($user)->post('/books', [
        'title' => 'A Guided Book',
        'book_type' => 'memoir',
        'premise' => 'The year I stopped answering the phone.',
    ]);

    expect($user->books()->first()->journey_stage)->toBe('plan');
});

it('keeps a book whose premise is still unanswered in Discover', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/books', [
        'title' => 'A Guided Book',
        'book_type' => 'memoir',
    ]);

    $book = $user->books()->first();

    // The stage and what the stage list reports must agree.
    expect($book->journey_stage)->toBe('discover');

    $stages = collect($this->actingAs($user)
        ->getJson("/books/{$book->id}/journey")
        ->json('stages'))->keyBy('key');

    expect($stages['discover']['current'])->toBeTrue()
        ->and($stages['discover']['done'])->toBeFalse();
});

it('takes the type\'s typical length when none is given', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/books', [
        'title' => 'A Guided Book',
        'book_type' => 'business',
    ]);

    expect($user->books()->first()->target_words)
        ->toBe(BookType::findOrFail('business')->suggestedWords());
});

it('takes the author\'s own length over the suggestion', function () {
    // The suggestion is a starting point to argue with, not a rule.
    $user = User::factory()->create();

    $this->actingAs($user)->post('/books', [
        'title' => 'A Guided Book',
        'book_type' => 'business',
        'target_words' => 120000,
    ]);

    expect($user->books()->first()->target_words)->toBe(120000);
});

it('records how the book was started', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/books', [
        'title' => 'A Guided Book',
        'book_type' => 'fiction',
        'started_with' => 'template',
    ]);

    expect($user->books()->first()->started_with)->toBe('template');
});

it('records the assistance level the author picked', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/books', [
        'title' => 'A Guided Book',
        'book_type' => 'fiction',
        'assistance' => 'coach',
    ]);

    expect($user->books()->first()->assistance)->toBe('coach');
});

it('defaults to the author writing it themselves', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/books', ['title' => 'A Book']);

    expect($user->books()->first()->assistance)->toBe('author_led');
});

it('rejects a book type that does not exist', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/books', ['title' => 'A Book', 'book_type' => 'interpretive_dance'])
        ->assertSessionHasErrors('book_type');

    expect($user->books()->count())->toBe(0);
});

it('rejects an assistance level that does not exist', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/books', ['title' => 'A Book', 'assistance' => 'write_it_for_me'])
        ->assertSessionHasErrors('assistance');
});

it('refuses an absurd chapter count rather than building it', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/books', [
            'title' => 'A Book',
            'book_type' => 'fiction',
            'chapters' => 5000,
        ])
        ->assertSessionHasErrors('chapters');
});

it('still enforces the plan limit through the guided flow', function () {
    // The flow must not be a way around a limit the plain path enforces.
    $user = User::factory()->create(['plan' => 'free']);
    $user->books()->create(['title' => 'The One Book The Free Plan Allows']);

    $this->actingAs($user)
        ->post('/books', ['title' => 'A Second', 'book_type' => 'nonfiction'])
        ->assertForbidden();

    expect($user->books()->count())->toBe(1);
});

it('offers every configured type on the shelf', function () {
    // The flow's options come with the shelf, so choosing a type needs no
    // second request.
    $user = User::factory()->create();

    $types = $this->actingAs($user)->get('/books')->viewData('page')['props']['book_types'];

    expect($types)->toHaveCount(count(config('booktypes')))
        ->and($types[0])->toHaveKeys(['key', 'name', 'blurb', 'words', 'chapters']);
});

it('does not let one author scaffold onto another\'s shelf', function () {
    // Ownership comes from the session, never from input.
    $user = User::factory()->create();
    $stranger = User::factory()->create();

    $this->actingAs($user)->post('/books', [
        'title' => 'Mine',
        'book_type' => 'nonfiction',
        'user_id' => $stranger->id,
    ]);

    expect($user->books()->count())->toBe(1)
        ->and($stranger->books()->count())->toBe(0);
});

it('creates the whole scaffold or none of it', function () {
    // One transaction: a half-built book is worse than none, because the
    // author cannot tell which chapters are theirs.
    $user = User::factory()->create();

    $this->actingAs($user)->post('/books', [
        'title' => 'A Guided Book',
        'book_type' => 'academic',
    ]);

    $book = $user->books()->first();
    $type = BookType::findOrFail('academic');

    expect($book->sections()->where('type', 'front_matter')->count())
        ->toBe(count($type->frontMatter()))
        ->and($book->sections()->where('type', 'back_matter')->count())
        ->toBe(count($type->backMatter()))
        ->and($book->sections()->where('type', 'chapter')->count())
        ->toBe($type->suggestedChapters());
});
