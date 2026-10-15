<?php

use App\Billing\CreditLedger;
use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;

/**
 * Discover, the outline, and resuming.
 *
 * The three pieces missing from the first milestone: saying what the book is,
 * turning that into chapters, and coming back to where you were.
 */
function ideaBook(User $user, array $attributes = []): Book
{
    return $user->books()->create(array_merge([
        'title' => 'Building an Independent Practice',
        'book_type' => 'nonfiction',
    ], $attributes));
}

function bookWithChaptersNamed(User $user, int $count, array $attributes = []): Book
{
    $book = ideaBook($user, $attributes);

    for ($n = 1; $n <= $count; $n++) {
        $book->sections()->create([
            'type' => 'chapter',
            'title' => "Chapter {$n}",
            'position' => $n * ManuscriptSection::POSITION_STEP,
            'content' => ['type' => 'doc', 'content' => []],
        ]);
    }

    return $book->fresh();
}

// --- Discover -----------------------------------------------------------

it('saves what the book is about', function () {
    $user = User::factory()->create();
    $book = ideaBook($user);

    $this->actingAs($user)
        ->patchJson("/books/{$book->id}/idea", [
            'premise' => 'How to open a private clinic without losing your shirt.',
            'audience' => 'Clinicians thinking about leaving an employer.',
            'promise' => 'They will know what the first six decisions are.',
        ])
        ->assertOk();

    expect($book->fresh()->premise)->toContain('private clinic')
        ->and($book->fresh()->audience)->toContain('Clinicians')
        ->and($book->fresh()->promise)->toContain('six decisions');
});

it('moves past Discover once the premise exists', function () {
    // Answering the question is what finishing the stage means. A separate
    // "I have finished thinking" button would be asking twice.
    $user = User::factory()->create();
    $book = ideaBook($user);

    // fresh(): create() returns the model as built, before the database
    // applies its column defaults.
    expect($book->fresh()->journey_stage)->toBe('discover');

    $this->actingAs($user)->patchJson("/books/{$book->id}/idea", [
        'premise' => 'A sentence about the book.',
    ]);

    expect($book->fresh()->journey_stage)->toBe('plan');
});

it('stays in Discover while the premise is empty', function () {
    $user = User::factory()->create();
    $book = ideaBook($user);

    $this->actingAs($user)->patchJson("/books/{$book->id}/idea", [
        'audience' => 'Somebody, eventually.',
    ]);

    expect($book->fresh()->journey_stage)->toBe('discover');
});

it('does not drag an author backwards once they are further on', function () {
    $user = User::factory()->create();
    $book = ideaBook($user, ['journey_stage' => 'create']);

    $this->actingAs($user)->patchJson("/books/{$book->id}/idea", [
        'premise' => 'Revised, in chapter nine.',
    ]);

    expect($book->fresh()->journey_stage)->toBe('create');
});

it('lets the author go back to any stage', function () {
    // Skippable and revisitable. Refusing to reopen Discover in chapter nine
    // would misunderstand how writing works.
    $user = User::factory()->create();
    $book = ideaBook($user, ['journey_stage' => 'publish']);

    $this->actingAs($user)
        ->patchJson("/books/{$book->id}/stage", ['stage' => 'discover'])
        ->assertOk();

    expect($book->fresh()->journey_stage)->toBe('discover');
});

it('changes the assistance level without touching the manuscript', function () {
    $user = User::factory()->create();
    $book = ideaBook($user);
    ManuscriptSection::factory()->for($book)->create(['title' => 'Chapter 1']);

    $before = $book->sections()->first()->content;

    $this->actingAs($user)
        ->patchJson("/books/{$book->id}/assistance", ['assistance' => 'collaborative'])
        ->assertOk();

    expect($book->fresh()->assistance)->toBe('collaborative')
        ->and($book->sections()->first()->content)->toBe($before);
});

// --- The outline --------------------------------------------------------

it('names chapters from the idea', function () {
    $user = User::factory()->create();
    $book = bookWithChaptersNamed($user, 6, [
        'premise' => 'Private practice, and how to survive it.',
    ]);

    $this->actingAs($user)->postJson("/books/{$book->id}/outline")->assertOk();

    $titles = $book->sections()->where('type', 'chapter')->orderBy('position')
        ->pluck('title')->all();

    expect($titles[0])->toStartWith('1. ')
        ->and($titles[0])->not->toBe('Chapter 1')
        ->and($titles[0])->toContain('Private practice');
});

it('does not overwrite a chapter the author has named', function () {
    // A title they wrote is their decision about their own book.
    $user = User::factory()->create();
    $book = bookWithChaptersNamed($user, 4, ['premise' => 'Something.']);

    $third = $book->sections()->where('title', 'Chapter 3')->first();
    $third->update(['title' => 'The one about money']);

    $this->actingAs($user)->postJson("/books/{$book->id}/outline")->assertOk();

    expect($third->fresh()->title)->toBe('The one about money');
});

it('reports how many it renamed', function () {
    $user = User::factory()->create();
    $book = bookWithChaptersNamed($user, 5, ['premise' => 'Something.']);

    $book->sections()->where('title', 'Chapter 2')->first()->update(['title' => 'Mine']);

    expect($this->actingAs($user)->postJson("/books/{$book->id}/outline")->json('renamed'))
        ->toBe(4);
});

it('is safe to run twice', function () {
    $user = User::factory()->create();
    $book = bookWithChaptersNamed($user, 4, ['premise' => 'Something.']);

    $this->actingAs($user)->postJson("/books/{$book->id}/outline");
    $first = $book->sections()->orderBy('position')->pluck('title')->all();

    expect($this->actingAs($user)->postJson("/books/{$book->id}/outline")->json('renamed'))
        ->toBe(0);

    expect($book->fresh()->sections()->orderBy('position')->pluck('title')->all())
        ->toBe($first);
});

it('numbers past the end of the arc rather than repeating it', function () {
    // A book longer than its usual shape is a real thing, and inventing more
    // stages would be making them up.
    $user = User::factory()->create();
    $book = bookWithChaptersNamed($user, 30, ['premise' => 'Something.']);

    $this->actingAs($user)->postJson("/books/{$book->id}/outline")->assertOk();

    $titles = $book->sections()->where('type', 'chapter')->orderBy('position')
        ->pluck('title')->all();

    expect($titles)->toHaveCount(30)
        ->and(count(array_unique($titles)))->toBe(30);
});

it('falls back to the title when there is no premise', function () {
    // Still the author's own words, never invented.
    $user = User::factory()->create();
    $book = bookWithChaptersNamed($user, 3);

    $this->actingAs($user)->postJson("/books/{$book->id}/outline")->assertOk();

    expect($book->sections()->orderBy('position')->first()->title)
        ->toContain('Building an Independent Practice');
});

it('uses the arc that suits the kind of book', function () {
    $user = User::factory()->create();

    $memoir = bookWithChaptersNamed($user, 4, ['book_type' => 'memoir', 'title' => 'A Life']);
    $this->actingAs($user)->postJson("/books/{$memoir->id}/outline");

    expect($memoir->sections()->orderBy('position')->first()->title)->toContain('Before');
});

it('refuses to outline a book with no type', function () {
    $user = User::factory()->create();
    $book = bookWithChaptersNamed($user, 3, ['book_type' => null]);

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/outline")
        ->assertStatus(422);
});

it('refuses to outline a book with no chapters', function () {
    $user = User::factory()->create();
    $book = ideaBook($user);

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/outline")
        ->assertStatus(422);
});

it('costs nothing, because it rearranges what the author already said', function () {
    $user = User::factory()->subscribed('studio', 100)->create();
    $book = bookWithChaptersNamed($user, 4, ['premise' => 'Something.']);

    $this->actingAs($user)->postJson("/books/{$book->id}/outline")->assertOk();

    expect(app(CreditLedger::class)->balanceFor($user))->toBe(100);
});

// --- What to do next ----------------------------------------------------

it('asks for the premise first', function () {
    $user = User::factory()->create();
    $book = ideaBook($user);

    expect($this->actingAs($user)->getJson("/books/{$book->id}/journey")->json('next.label'))
        ->toContain('what the book is about');
});

it('asks for chapter names once there are chapters', function () {
    $user = User::factory()->create();
    $book = bookWithChaptersNamed($user, 5, ['premise' => 'Something.']);

    expect($this->actingAs($user)->getJson("/books/{$book->id}/journey")->json('next.label'))
        ->toContain('Name the chapters');
});

it('asks for writing once the chapters are named', function () {
    $user = User::factory()->create();
    $book = bookWithChaptersNamed($user, 3, ['premise' => 'Something.']);

    $this->actingAs($user)->postJson("/books/{$book->id}/outline");

    expect($this->actingAs($user)->getJson("/books/{$book->id}/journey")->json('next.label'))
        ->toContain('Write');
});

it('gives one next action, not a list', function () {
    // A product that answers "what should I do next" with nine options has
    // not answered it.
    $user = User::factory()->create();
    $book = ideaBook($user);

    $next = $this->actingAs($user)->getJson("/books/{$book->id}/journey")->json('next');

    expect($next)->toHaveKeys(['label', 'detail'])
        ->and($next['label'])->toBeString();
});

it('computes stage completion from the manuscript, not from a tick', function () {
    // A stage claiming completion while the work is absent would make the
    // whole path decorative.
    $user = User::factory()->create();
    $book = ideaBook($user, ['journey_stage' => 'publish']);

    $stages = collect($this->actingAs($user)->getJson("/books/{$book->id}/journey")->json('stages'))
        ->keyBy('key');

    expect($stages['discover']['done'])->toBeFalse()
        ->and($stages['create']['done'])->toBeFalse()
        ->and($stages['publish']['current'])->toBeTrue();
});

// --- Resume -------------------------------------------------------------

it('reopens the section the author last worked in', function () {
    // On a twenty-chapter book, landing on chapter one every time means
    // hunting for your own work.
    $user = User::factory()->create();
    $book = bookWithChaptersNamed($user, 10, ['premise' => 'Something.']);

    $seventh = $book->sections()->where('title', 'Chapter 7')->first();

    $this->actingAs($user)->get("/books/{$book->id}/edit?section={$seventh->id}");

    expect($book->fresh()->last_section_id)->toBe($seventh->id);

    $active = $this->actingAs($user)
        ->get("/books/{$book->id}/edit")
        ->viewData('page')['props']['activeSection'];

    expect($active['id'])->toBe($seventh->id);
});

it('falls back to the first section when the last one is gone', function () {
    $user = User::factory()->create();
    $book = bookWithChaptersNamed($user, 5, ['premise' => 'Something.']);

    $third = $book->sections()->where('title', 'Chapter 3')->first();
    $this->actingAs($user)->get("/books/{$book->id}/edit?section={$third->id}");

    $third->forceDelete();

    $active = $this->actingAs($user)
        ->get("/books/{$book->id}/edit")
        ->viewData('page')['props']['activeSection'];

    expect($active['title'])->toBe('Chapter 1');
});

it('prefers an explicitly requested section over the remembered one', function () {
    $user = User::factory()->create();
    $book = bookWithChaptersNamed($user, 5, ['premise' => 'Something.']);

    $second = $book->sections()->where('title', 'Chapter 2')->first();
    $fourth = $book->sections()->where('title', 'Chapter 4')->first();

    $this->actingAs($user)->get("/books/{$book->id}/edit?section={$second->id}");

    $active = $this->actingAs($user)
        ->get("/books/{$book->id}/edit?section={$fourth->id}")
        ->viewData('page')['props']['activeSection'];

    expect($active['id'])->toBe($fourth->id);
});

it('never resumes into a part, which holds chapters rather than prose', function () {
    $user = User::factory()->create();
    $book = bookWithChaptersNamed($user, 3, ['premise' => 'Something.']);

    $part = $book->sections()->create([
        'type' => 'part',
        'title' => 'Part I',
        'position' => 5,
        'content' => ['type' => 'doc', 'content' => []],
    ]);

    $book->forceFill(['last_section_id' => $part->id])->save();

    $active = $this->actingAs($user)
        ->get("/books/{$book->id}/edit")
        ->viewData('page')['props']['activeSection'];

    expect($active['type'])->not->toBe('part');
});
