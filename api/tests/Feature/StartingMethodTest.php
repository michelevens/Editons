<?php

use App\Models\User;

/**
 * Ways to start a book.
 *
 * The directive lists twelve. Several are real, several need something that
 * does not exist yet, and these tests hold the product to saying which is
 * which — a method offered but not working is worse than one that says
 * plainly what it still needs.
 */
function outlineBook(User $user, array $attributes = []): App\Models\Book
{
    return $user->books()->create(array_merge([
        'title' => 'A Book',
        'book_type' => 'nonfiction',
    ], $attributes));
}

it('describes every starting method completely', function () {
    foreach (config('startingmethods') as $key => $method) {
        expect($method['name'])->not->toBeEmpty("[{$key}] has no name.")
            ->and($method['blurb'])->not->toBeEmpty("[{$key}] has no blurb.")
            ->and($method)->toHaveKey('available');
    }
});

it('says what an unavailable method still needs', function () {
    // A method that is merely greyed out tells somebody nothing. This is the
    // difference between "not yet" and "broken".
    foreach (config('startingmethods') as $key => $method) {
        if ($method['available']) {
            continue;
        }

        expect($method['needs'])->not->toBeEmpty(
            "[{$key}] is unavailable and does not say why.",
        );
    }
});

it('offers at least one way in that works', function () {
    $available = collect(config('startingmethods'))->where('available', true);

    expect($available)->not->toBeEmpty();
});

it('lists the methods on the journey', function () {
    $user = User::factory()->create();
    $book = outlineBook($user);

    $methods = $this->actingAs($user)
        ->getJson("/books/{$book->id}/journey")
        ->json('starting_methods');

    expect($methods)->toHaveCount(count(config('startingmethods')))
        ->and($methods[0])->toHaveKeys(['key', 'name', 'blurb', 'available']);
});

// --- Importing an outline -----------------------------------------------

it('turns a list of titles into chapters', function () {
    $user = User::factory()->create();
    $book = outlineBook($user);

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/outline/import", [
            'outline' => "Why it matters\nThe groundwork\nWhat goes wrong",
        ])
        ->assertOk();

    expect($book->sections()->where('type', 'chapter')->pluck('title')->all())
        ->toBe(['Why it matters', 'The groundwork', 'What goes wrong']);
});

it('strips the numbering, because the binder already has an order', function () {
    // Keeping both means they contradict each other the moment anything is
    // reordered.
    $user = User::factory()->create();
    $book = outlineBook($user);

    $this->actingAs($user)->postJson("/books/{$book->id}/outline/import", [
        'outline' => "1. Beginnings\n2) Middles\n- Endings\n## A heading",
    ]);

    expect($book->sections()->orderBy('position')->pluck('title')->all())
        ->toBe(['Beginnings', 'Middles', 'Endings', 'A heading']);
});

it('strips "Chapter 1:" and the like', function () {
    $user = User::factory()->create();
    $book = outlineBook($user);

    $this->actingAs($user)->postJson("/books/{$book->id}/outline/import", [
        'outline' => "Chapter 1: Why it matters\nChapter 2 - The groundwork",
    ]);

    expect($book->sections()->orderBy('position')->pluck('title')->all())
        ->toBe(['Why it matters', 'The groundwork']);
});

it('reads indentation as nesting', function () {
    $user = User::factory()->create();
    $book = outlineBook($user);

    $this->actingAs($user)->postJson("/books/{$book->id}/outline/import", [
        'outline' => "Laying the foundation\n  Why it matters\n  The groundwork",
    ]);

    $part = $book->sections()->where('type', 'part')->first();

    expect($part)->not->toBeNull()
        ->and($part->title)->toBe('Laying the foundation')
        ->and($book->sections()->where('parent_id', $part->id)->count())->toBe(2);
});

it('keeps a chapter the author has already written', function () {
    // Importing a corrected outline must not take their work with it.
    $user = User::factory()->create();
    $book = outlineBook($user);

    $written = $book->sections()->create([
        'type' => 'chapter',
        'title' => 'Already written',
        'position' => 10,
        'content' => ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'Real words that somebody typed.']],
        ]]],
    ]);

    $written->forceFill(['word_count' => 6])->save();

    $this->actingAs($user)->postJson("/books/{$book->id}/outline/import", [
        'outline' => "A new first chapter\nA new second",
    ]);

    expect($written->fresh())->not->toBeNull()
        ->and($written->fresh()->plainText())->toContain('Real words');
});

it('replaces an earlier outline rather than appending to it', function () {
    // Importing twice means the author is correcting it, and ending up with
    // both lists is never what they meant.
    $user = User::factory()->create();
    $book = outlineBook($user);

    $this->actingAs($user)->postJson("/books/{$book->id}/outline/import", [
        'outline' => "First go\nSecond line",
    ]);

    $this->actingAs($user)->postJson("/books/{$book->id}/outline/import", [
        'outline' => "Corrected\nAlso corrected",
    ]);

    expect($book->sections()->pluck('title')->all())
        ->toBe(['Corrected', 'Also corrected']);
});

it('refuses an empty outline', function () {
    $user = User::factory()->create();
    $book = outlineBook($user);

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/outline/import", ['outline' => "   \n\n  "])
        ->assertStatus(422);
});

it('refuses something that is a manuscript rather than an outline', function () {
    $user = User::factory()->create();
    $book = outlineBook($user);

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/outline/import", [
            'outline' => implode("\n", array_fill(0, 250, 'A line')),
        ])
        ->assertStatus(422)
        ->assertJsonFragment(['message' => 'That is longer than an outline — 250 lines. Import it as a manuscript instead.']);
});

it('moves the book to Plan', function () {
    $user = User::factory()->create();
    $book = outlineBook($user);

    $this->actingAs($user)->postJson("/books/{$book->id}/outline/import", [
        'outline' => "One\nTwo",
    ]);

    expect($book->fresh()->journey_stage)->toBe('plan');
});

it('costs nothing', function () {
    $user = User::factory()->subscribed('studio', 100)->create();
    $book = outlineBook($user);

    $this->actingAs($user)->postJson("/books/{$book->id}/outline/import", [
        'outline' => "One\nTwo",
    ]);

    expect(app(App\Billing\CreditLedger::class)->balanceFor($user))->toBe(100);
});

it('will not let a stranger rewrite somebody\'s binder', function () {
    $owner = User::factory()->create();
    $book = outlineBook($owner);

    $this->actingAs(User::factory()->create())
        ->postJson("/books/{$book->id}/outline/import", ['outline' => "Mine now"])
        ->assertForbidden();
});
