<?php

use App\Billing\CreditLedger;
use App\Models\Book;
use App\Models\User;

/**
 * Ways to start a book.
 *
 * The directive lists twelve. Several are real, several need something that
 * does not exist yet, and these tests hold the product to saying which is
 * which — a method offered but not working is worse than one that says
 * plainly what it still needs.
 */
function outlineBook(User $user, array $attributes = []): Book
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

    expect($book->sections()->orderBy('position')->pluck('title')->all())
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

    expect(app(CreditLedger::class)->balanceFor($user))->toBe(100);
});

it('will not let a stranger rewrite somebody\'s binder', function () {
    $owner = User::factory()->create();
    $book = outlineBook($owner);

    $this->actingAs(User::factory()->create())
        ->postJson("/books/{$book->id}/outline/import", ['outline' => 'Mine now'])
        ->assertForbidden();
});

// --- A proposal's outline -------------------------------------------------

/*
 * The outline an author brings from a proposal: a title, front material,
 * "## Part I — ..." headings with a describing line, "Chapter N:" lines with
 * bullets under them, appendices, and editorial notes. The plain importer
 * made every one of those lines a chapter.
 */
function proposalOutline(): string
{
    return <<<'MD'
    # BOOK OUTLINE

    # Crossing Over

    How to Do a Thing from Somewhere Else

    Subtitle: A Practical Guide for People Far Away.

    Target audience: Readers who live somewhere else.

    ## Part I — The Big Picture

    Why any of this is possible.

    Chapter 1: The Opportunity

    - Why it attracts people
    - What is legal

    Chapter 2: The New Reader

    - How things changed

    ## Part II — Doing It

    From idea to working thing.

    Chapter 3: Setting Up

    - Choosing the form
    - Registering

    ## Appendices — The Toolkit

    - Registration checklist
    - Glossary of terms

    ## Editorial direction

    Plain language. Every chapter ends with a checklist.

    | Budget | Example |
    | ------ | ------- |
    | $500   | Services |
    MD;
}

it('reads a proposal outline for its parts and chapters', function () {
    $user = User::factory()->create();
    $book = outlineBook($user);

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/outline/import", ['outline' => proposalOutline()])
        ->assertOk()
        ->assertJsonPath('created', 7);

    $sections = $book->sections()->orderBy('position')->get();

    expect($sections->map(fn ($s) => [$s->type, $s->title])->all())->toBe([
        ['part', 'The Big Picture'],
        ['chapter', 'The Opportunity'],
        ['chapter', 'The New Reader'],
        ['part', 'Doing It'],
        ['chapter', 'Setting Up'],
        ['back_matter', 'Registration checklist'],
        ['back_matter', 'Glossary of terms'],
    ]);

    // Chapters sit under their part; the part keeps its describing line.
    $parts = $sections->where('type', 'part')->values();
    expect($sections->firstWhere('title', 'The New Reader')->parent_id)->toBe($parts[0]->id)
        ->and($sections->firstWhere('title', 'Setting Up')->parent_id)->toBe($parts[1]->id)
        ->and($parts[0]->subtitle)->toBe('Why any of this is possible.')
        ->and($sections->firstWhere('type', 'back_matter')->parent_id)->toBeNull();
});

it('keeps chapters empty and puts their plan in the notes', function () {
    // Words in a chapter mark it written; a book of bullet points would
    // report itself drafted.
    $user = User::factory()->create();
    $book = outlineBook($user, ['notes' => 'My own note.']);

    $this->actingAs($user)->postJson("/books/{$book->id}/outline/import", [
        'outline' => proposalOutline(),
    ])->assertOk();

    $book->refresh();

    expect($book->sections()->where('type', 'chapter')->sum('word_count'))->toBe(0)
        ->and($book->notes)->toStartWith('My own note.')
        ->and($book->notes)->toContain("Chapter 3: Setting Up\n- Choosing the form\n- Registering")
        // Nothing pasted is lost: the direction and the table come along.
        ->and($book->notes)->toContain('Every chapter ends with a checklist.')
        ->and($book->notes)->toContain('| $500   | Services |');
});

it('fills the subtitle and audience only when the author has not', function () {
    $user = User::factory()->create();

    $empty = outlineBook($user);
    $this->actingAs($user)->postJson("/books/{$empty->id}/outline/import", [
        'outline' => proposalOutline(),
    ])->assertOk();

    $decided = outlineBook($user, ['subtitle' => 'Mine', 'audience' => 'Mine too']);
    $this->actingAs($user)->postJson("/books/{$decided->id}/outline/import", [
        'outline' => proposalOutline(),
    ])->assertOk();

    expect($empty->fresh()->subtitle)->toBe('A Practical Guide for People Far Away.')
        ->and($empty->fresh()->audience)->toBe('Readers who live somewhere else.')
        ->and($decided->fresh()->subtitle)->toBe('Mine')
        ->and($decided->fresh()->audience)->toBe('Mine too');
});
