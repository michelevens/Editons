<?php

use App\Models\Book;
use App\Models\Citation;
use App\Models\ManuscriptSection;
use App\Models\ResearchSource;
use App\Models\User;

/**
 * The Overview is a progress screen, so the only thing that matters is that
 * its numbers are true. A checklist an author cannot trust is worse than no
 * checklist, which is why every figure is counted here rather than stored.
 */
function overviewFor(User $user, Book $book): array
{
    return $user->is($book->user)
        ? test()->actingAs($user)->get("/books/{$book->id}/overview")
            ->assertOk()
            ->viewData('page')['props']
        : [];
}

it('counts words, chapters and findings from the database', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $book->sections()->delete();

    $section = ManuscriptSection::factory()->for($book)->create(['type' => 'chapter']);

    app(App\Services\ManuscriptWriter::class)->save(
        section: $section,
        content: ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'One two three four five.']],
        ]]],
        reason: 'manual',
    );

    $book->refresh();

    $props = overviewFor($user, $book);

    expect($props['stats']['words'])->toBe(5)
        ->and($props['stats']['chapters'])->toBe(1)
        ->and($props['stats']['open_findings'])->toBe(0);
});

it('reports claims that still need a source', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $section = ManuscriptSection::factory()->for($book)->create();

    Citation::create([
        'manuscript_section_id' => $section->id,
        'claim' => 'Something unsourced.',
        'status' => 'needs_source',
    ]);

    expect(overviewFor($user, $book)['stats']['claims_needing_source'])->toBe(1);
});

it('reports sources nobody has verified', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    ResearchSource::factory()->for($book)->create();
    ResearchSource::factory()->for($book)->verified()->create();

    $stats = overviewFor($user, $book)['stats'];

    expect($stats['sources'])->toBe(2)
        ->and($stats['unverified_sources'])->toBe(1);
});

it('counts vocabulary disagreements across chapters', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $book->sections()->delete();

    foreach (['John arrived.', 'Jon left.'] as $index => $text) {
        $book->sections()->create([
            'type' => 'chapter',
            'title' => 'Chapter '.($index + 1),
            'position' => ($index + 1) * ManuscriptSection::POSITION_STEP,
            'content' => ['type' => 'doc', 'content' => [[
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => $text]],
            ]]],
        ]);
    }

    $book->terms()->create(['canonical' => 'John', 'variants' => ['Jon']]);

    expect(overviewFor($user, $book)['stats']['consistency_issues'])->toBe(1);
});

it('derives the checklist rather than storing it', function () {
    // A stored flag drifts from the thing it claims about the moment anything
    // else changes, which on a progress screen is the whole failure.
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    $before = collect(overviewFor($user, $book)['checklist'])
        ->firstWhere('label', 'Source every claim');

    expect($before['done'])->toBeTrue();

    Citation::create([
        'manuscript_section_id' => ManuscriptSection::factory()->for($book)->create()->id,
        'claim' => 'Unsourced.',
        'status' => 'needs_source',
    ]);

    $after = collect(overviewFor($user, $book)['checklist'])
        ->firstWhere('label', 'Source every claim');

    expect($after['done'])->toBeFalse()
        ->and($after['detail'])->toContain('1 awaiting');
});

it('sets a word target', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    $this->actingAs($user)
        ->patch("/books/{$book->id}/overview", ['target_words' => 80000])
        ->assertRedirect();

    expect($book->refresh()->target_words)->toBe(80000);
});

it('refuses a target below the minimum', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    $this->actingAs($user)
        ->patch("/books/{$book->id}/overview", ['target_words' => 12])
        ->assertSessionHasErrors('target_words');
});

it('leaves the target unset rather than inventing one', function () {
    // "0% of 0 words" is worse than an empty state, so a book with no target
    // says so and asks for one.
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    expect(overviewFor($user, $book)['book']['target_words'])->toBeNull();
});

it('refuses to show another author\'s overview', function () {
    $book = Book::factory()->for(User::factory())->create();

    $this->actingAs(User::factory()->create())
        ->get("/books/{$book->id}/overview")
        ->assertForbidden();
});
