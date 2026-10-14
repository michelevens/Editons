<?php

use App\Editorial\EditorialAgent;
use App\Editorial\ReviewRunner;
use App\Models\Book;
use App\Models\EditorialFinding;
use App\Models\ManuscriptSection;
use App\Models\User;

function bookWithFindings(): array
{
    // subscribed(): the fixture runs a specialist agent for real, which is
    // now gated on plan tier and costs credits. The default factory user is
    // on the free plan with an empty ledger.
    $user = User::factory()->subscribed()->create();
    $book = Book::factory()->for($user)->create();
    $book->sections()->delete();

    $section = $book->sections()->create([
        'type' => 'chapter',
        'title' => 'Chapter 1',
        'position' => ManuscriptSection::POSITION_STEP,
        'content' => ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'Roughly 47 percent of practices close early.']],
        ]]],
    ]);

    app(ReviewRunner::class)->run($section, EditorialAgent::FactChecker, $user->id);

    return [$user, $book, $section];
}

it('gathers findings from every chapter, not one', function () {
    // The studio panel answers "what is wrong with this chapter". This screen
    // answers "what is left before the book is done", which is why it exists.
    [$user, $book] = bookWithFindings();

    $second = $book->sections()->create([
        'type' => 'chapter',
        'title' => 'Chapter 2',
        'position' => 2 * ManuscriptSection::POSITION_STEP,
        'content' => ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'Roughly 12 percent never reopen.']],
        ]]],
    ]);

    app(ReviewRunner::class)->run($second, EditorialAgent::FactChecker, $user->id);

    $chapters = collect(
        $this->actingAs($user)->getJson("/books/{$book->id}/findings")->json('findings'),
    )->pluck('section_title')->unique();

    expect($chapters)->toHaveCount(2);
});

it('shows open findings by default', function () {
    // Resolved findings are history. Loading them by default buries the work
    // that is left.
    [$user, $book] = bookWithFindings();

    EditorialFinding::query()->update(['status' => 'dismissed']);

    expect($this->actingAs($user)->getJson("/books/{$book->id}/findings")->json('findings'))
        ->toBe([]);
});

it('filters by status and by agent', function () {
    [$user, $book] = bookWithFindings();

    $dismissed = $this->actingAs($user)
        ->getJson("/books/{$book->id}/findings?status=dismissed")
        ->json('findings');

    expect($dismissed)->toBe([]);

    $wrongAgent = $this->actingAs($user)
        ->getJson("/books/{$book->id}/findings?agent=accessibility")
        ->json('findings');

    expect($wrongAgent)->toBe([]);

    $rightAgent = $this->actingAs($user)
        ->getJson("/books/{$book->id}/findings?agent=fact_checker")
        ->json('findings');

    expect($rightAgent)->not->toBeEmpty();
});

it('counts from the unfiltered set so a filter can show its size', function () {
    // A chip reading "Dismissed" with no number is a guess the author has to
    // click to resolve.
    [$user, $book] = bookWithFindings();

    $response = $this->actingAs($user)
        ->getJson("/books/{$book->id}/findings?agent=accessibility")
        ->json();

    expect($response['findings'])->toBe([])
        ->and($response['counts']['open'])->toBeGreaterThan(0);
});

it('orders critical findings first', function () {
    [$user, $book] = bookWithFindings();

    $severities = collect(
        $this->actingAs($user)->getJson("/books/{$book->id}/findings")->json('findings'),
    )->pluck('severity');

    expect($severities->first())->toBe('critical');
});

it('reopens a resolved finding and clears who resolved it', function () {
    // Dismissing is a judgement, and judgements get revisited. Leaving the
    // resolution record would have it pointing at a decision that no longer
    // stands.
    [$user, $book] = bookWithFindings();

    $finding = EditorialFinding::first();

    $this->actingAs($user)
        ->patchJson("/findings/{$finding->id}", ['status' => 'dismissed'])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson("/findings/{$finding->id}/reopen")
        ->assertOk();

    $finding->refresh();

    expect($finding->status)->toBe('open')
        ->and($finding->resolved_by)->toBeNull()
        ->and($finding->resolved_at)->toBeNull();
});

it('refuses to reopen a finding in someone else\'s book', function () {
    bookWithFindings();

    $finding = EditorialFinding::first();

    $this->actingAs(User::factory()->create())
        ->patchJson("/findings/{$finding->id}/reopen")
        ->assertForbidden();
});

it('reports each agent with its open count', function () {
    [$user, $book] = bookWithFindings();

    $agents = collect($this->actingAs($user)->getJson("/books/{$book->id}/findings")->json('agents'));

    expect($agents)->toHaveCount(count(EditorialAgent::cases()))
        ->and($agents->firstWhere('value', 'fact_checker')['open'])->toBeGreaterThan(0)
        ->and($agents->firstWhere('value', 'accessibility')['open'])->toBe(0);
});
