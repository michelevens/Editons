<?php

use App\Billing\CreditLedger;
use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;

/**
 * Notes, the marketing kit, and the screens that are not built.
 */
function kitBook(User $user, array $attributes = []): Book
{
    $book = $user->books()->create(array_merge([
        'title' => 'Building an Independent Practice',
        'subtitle' => 'A Step-by-Step Guide',
        'author_name' => 'A Writer',
        'genre' => 'Nonfiction',
        'meta' => ['category' => 'Medical / Practice Management'],
    ], $attributes));

    ManuscriptSection::factory()->for($book)->create([
        'title' => 'The Professional Opportunity',
        'type' => 'chapter',
        'position' => 0,
        'content' => ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' =>
                'Short one. Roughly half of new practices close within three years of '
                .'opening their doors, and almost all of them for reasons that were '
                .'visible beforehand.',
            ]],
        ]]],
    ]);

    ManuscriptSection::factory()->for($book)->create([
        'title' => 'Defining Your Services',
        'type' => 'chapter',
        'position' => 1,
        'content' => ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'Deciding what you will not do matters as much as deciding what you will.']],
        ]]],
    ]);

    return $book->fresh();
}

// --- Notes --------------------------------------------------------------

it('saves and returns notes', function () {
    $user = User::factory()->create();
    $book = kitBook($user);

    $this->actingAs($user)
        ->putJson("/books/{$book->id}/notes", ['notes' => "Reader: clinicians.\nTone: practical."])
        ->assertOk();

    expect($this->actingAs($user)->getJson("/books/{$book->id}/notes")->json('notes'))
        ->toContain('Reader: clinicians');
});

it('accepts empty notes, which is how you clear them', function () {
    $user = User::factory()->create();
    $book = kitBook($user, []);
    $book->update(['notes' => 'Something']);

    $this->actingAs($user)
        ->putJson("/books/{$book->id}/notes", ['notes' => ''])
        ->assertOk();

    expect($book->fresh()->notes)->toBe('');
});

it('does not version notes, because they are not the manuscript', function () {
    // A version history of a scratchpad is noise.
    $user = User::factory()->create();
    $book = kitBook($user);

    $this->actingAs($user)->putJson("/books/{$book->id}/notes", ['notes' => 'A note']);

    expect($book->sections()->first()->versions()->count())->toBe(0);
});

it('stops a stranger reading somebody else\'s notes', function () {
    $book = kitBook(User::factory()->create());

    $this->actingAs(User::factory()->create())
        ->getJson("/books/{$book->id}/notes")
        ->assertForbidden();
});

// --- Marketing kit ------------------------------------------------------

it('builds a kit from the manuscript', function () {
    $user = User::factory()->subscribed()->create();
    $book = kitBook($user);

    $response = $this->actingAs($user)
        ->postJson("/books/{$book->id}/marketing-kit")
        ->assertOk();

    $kit = $response->json('kit');

    expect($kit['description'])->not->toBeEmpty()
        ->and($kit['email']['subject'])->toContain($book->title)
        ->and($kit['posts'])->not->toBeEmpty();
});

it('uses the author\'s own sentences rather than inventing claims', function () {
    // The rule this protects: marketing copy goes out under the author's name
    // to people deciding whether to buy. An invented statistic in a store
    // listing is the author's problem, not the generator's.
    $user = User::factory()->subscribed()->create();
    $book = kitBook($user);

    $kit = $this->actingAs($user)
        ->postJson("/books/{$book->id}/marketing-kit")
        ->json('kit');

    expect($kit['description'])->toContain('Roughly half of new practices close')
        ->and($kit['source'])->toBe('extractive');
});

it('skips a sentence too short to carry a claim', function () {
    // "Short one." is first in the chapter and would be a useless headline.
    $user = User::factory()->subscribed()->create();
    $book = kitBook($user);

    $kit = $this->actingAs($user)
        ->postJson("/books/{$book->id}/marketing-kit")
        ->json('kit');

    expect($kit['description'])->not->toStartWith('Short one.');
});

it('keeps a description the author already wrote', function () {
    // They have said how they want the book described. Replacing it would be
    // presumptuous.
    $user = User::factory()->subscribed()->create();
    $book = kitBook($user, [
        'meta' => ['category' => 'Medical / Practice Management', 'description' => 'My own words.'],
    ]);

    $kit = $this->actingAs($user)
        ->postJson("/books/{$book->id}/marketing-kit")
        ->json('kit');

    expect($kit['description'])->toBe('My own words.');
});

it('keeps every post within the tightest common limit', function () {
    $user = User::factory()->subscribed()->create();
    $book = kitBook($user);

    $posts = $this->actingAs($user)
        ->postJson("/books/{$book->id}/marketing-kit")
        ->json('kit.posts');

    foreach ($posts as $post) {
        expect(mb_strlen($post))->toBeLessThanOrEqual(280);
    }
});

it('never cuts a post mid-word', function () {
    // The ellipsis must follow a whole word. Checking that it is preceded by
    // a letter proves nothing either way — "three…" is correct and
    // "thre…" is not — so this compares against the source text instead.
    $user = User::factory()->subscribed()->create();
    $book = kitBook($user, ['title' => str_repeat('A Very Long Title ', 12)]);

    $posts = $this->actingAs($user)
        ->postJson("/books/{$book->id}/marketing-kit")
        ->json('kit.posts');

    // Everything the generator draws on: the prose, the title, the subtitle
    // and the chapter headings.
    $source = $book->sections->map->plainText()->implode(' ')
        .' '.$book->sections->pluck('title')->implode(' ')
        .' '.$book->title.' '.$book->subtitle;

    foreach ($posts as $post) {
        if (! str_ends_with($post, '…')) {
            continue;
        }

        $lastWord = preg_split('/\s+/', rtrim($post, '…'));
        $lastWord = end($lastWord);

        // Every truncated post ends on a word that appears whole in the
        // material it was built from.
        expect($source)->toContain($lastWord);
    }
});

it('takes the audience the author gives it', function () {
    $user = User::factory()->subscribed()->create();
    $book = kitBook($user);

    $kit = $this->actingAs($user)
        ->postJson("/books/{$book->id}/marketing-kit", ['audience' => 'nurse practitioners'])
        ->json('kit');

    expect($kit['audience'])->toBe('nurse practitioners')
        ->and($kit['email']['body'])->toContain('nurse practitioners');
});

it('charges credits, and refunds when there is nothing to work with', function () {
    $user = User::factory()->subscribed('studio', 100)->create();
    $book = kitBook($user);

    $this->actingAs($user)->postJson("/books/{$book->id}/marketing-kit")->assertOk();

    expect(app(CreditLedger::class)->balanceFor($user))
        ->toBe(100 - config('billing.costs.marketing_kit'));

    $empty = $user->books()->create(['title' => 'Nothing Yet']);

    $this->actingAs($user)
        ->postJson("/books/{$empty->id}/marketing-kit")
        ->assertStatus(422);

    // Unchanged from after the first, successful run: the failed one was
    // refunded rather than charged.
    expect(app(CreditLedger::class)->balanceFor($user))
        ->toBe(100 - config('billing.costs.marketing_kit'));
});

it('refuses when the balance will not cover it', function () {
    $user = User::factory()->broke()->create();
    $book = kitBook($user);

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/marketing-kit")
        ->assertStatus(422)
        ->assertJsonValidationErrors('credits');
});

// --- The screens that are not built -------------------------------------

it('explains each unbuilt screen rather than showing a dead end', function () {
    $user = User::factory()->create();
    $book = kitBook($user);

    foreach (['audio', 'course', 'site'] as $screen) {
        $response = $this->actingAs($user)
            ->getJson("/books/{$book->id}/coming-soon/{$screen}")
            ->assertOk();

        expect($response->json('title'))->not->toBeEmpty()
            ->and($response->json('summary'))->not->toBeEmpty()
            ->and($response->json('needs'))->not->toBeEmpty()
            ->and($response->json('plan.name'))->not->toBeEmpty();
    }
});

it('reports a studio subscriber as entitled to an author-plan screen', function () {
    $user = User::factory()->create(['plan' => 'studio']);
    $book = kitBook($user);

    expect($this->actingAs($user)
        ->getJson("/books/{$book->id}/coming-soon/audio")
        ->json('entitled'))->toBeTrue();
});

it('reports a free account as not entitled', function () {
    $user = User::factory()->create(['plan' => 'free']);
    $book = kitBook($user);

    expect($this->actingAs($user)
        ->getJson("/books/{$book->id}/coming-soon/course")
        ->json('entitled'))->toBeFalse();
});

it('404s a screen that does not exist', function () {
    $user = User::factory()->create();
    $book = kitBook($user);

    $this->actingAs($user)
        ->getJson("/books/{$book->id}/coming-soon/teleporter")
        ->assertNotFound();
});
