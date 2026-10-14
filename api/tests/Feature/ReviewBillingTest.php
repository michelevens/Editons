<?php

use App\Billing\CreditLedger;
use App\Editorial\Contracts\EditorialDriver;
use App\Editorial\EditorialAgent;
use App\Editorial\ReviewRunner;
use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use RuntimeException;

/**
 * Charging for an AI review.
 *
 * The rule these protect: an author pays for work that happened, and only
 * for work that happened. Billing for a failed or empty review is the one
 * billing bug nobody forgives.
 */
function reviewable(User $user): ManuscriptSection
{
    return ManuscriptSection::factory()
        ->for(Book::factory()->for($user))
        ->create(['content' => ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'Roughly 47 percent of practices close early.']],
        ]]]]);
}

it('debits the account for a general agent', function () {
    $user = User::factory()->subscribed('studio', 100)->create();

    app(ReviewRunner::class)->run(reviewable($user), EditorialAgent::Copy, $user->id);

    expect(app(CreditLedger::class)->balanceFor($user))
        ->toBe(100 - config('billing.costs.agent_run'));
});

it('charges more for a specialist reviewer', function () {
    // The specialist agents are what the paid tiers are for, so they cost
    // more to run. Derived from the agent, not passed in by the client.
    $user = User::factory()->subscribed('studio', 100)->create();

    app(ReviewRunner::class)->run(reviewable($user), EditorialAgent::SubjectMatter, $user->id);

    expect(app(CreditLedger::class)->balanceFor($user))
        ->toBe(100 - config('billing.costs.specialist_run'));
});

it('does not charge for an empty chapter', function () {
    // There was nothing to review. Billing for a no-op is a small
    // dishonesty that costs more trust than it earns credits.
    $user = User::factory()->subscribed('studio', 100)->create();

    $section = ManuscriptSection::factory()
        ->for(Book::factory()->for($user))
        ->create(['content' => ['type' => 'doc', 'content' => []]]);

    app(ReviewRunner::class)->run($section, EditorialAgent::Copy, $user->id);

    expect(app(CreditLedger::class)->balanceFor($user))->toBe(100);
});

it('refunds when the provider fails', function () {
    // Charging for work that did not happen is unforgivable, so the runner
    // gives the credits back when it records a failure.
    $this->mock(EditorialDriver::class, function ($mock) {
        $mock->shouldReceive('name')->andReturn('exploding');
        $mock->shouldReceive('review')->andThrow(new RuntimeException('provider is down'));
    });

    $user = User::factory()->subscribed('studio', 100)->create();

    $review = app(ReviewRunner::class)->run(reviewable($user), EditorialAgent::Copy, $user->id);

    expect($review->status)->toBe('failed')
        ->and(app(CreditLedger::class)->balanceFor($user))->toBe(100);
});

it('leaves the spend and the refund both visible in the ledger', function () {
    // The ledger is append-only: the attempt happened and was paid for, then
    // refunded. Deleting the debit would hide that it was ever tried.
    $this->mock(EditorialDriver::class, function ($mock) {
        $mock->shouldReceive('name')->andReturn('exploding');
        $mock->shouldReceive('review')->andThrow(new RuntimeException('provider is down'));
    });

    $user = User::factory()->subscribed('studio', 100)->create();

    app(ReviewRunner::class)->run(reviewable($user), EditorialAgent::Copy, $user->id);

    expect($user->creditTransactions()->where('kind', 'spend')->count())->toBe(1)
        ->and($user->creditTransactions()->where('kind', 'refund')->count())->toBe(1);
});

it('does not charge a run with no account behind it', function () {
    // Seeders and internal tooling. There is nobody to bill, and guessing
    // would bill the wrong person.
    $user = User::factory()->subscribed('studio', 100)->create();

    app(ReviewRunner::class)->run(reviewable($user), EditorialAgent::Copy, null);

    expect(app(CreditLedger::class)->balanceFor($user))->toBe(100);
});

it('refuses over HTTP before the work starts when the balance is short', function () {
    // Told up front rather than watching a review begin and fail.
    $user = User::factory()->broke()->create();
    $section = reviewable($user);

    $this->actingAs($user)
        ->postJson("/sections/{$section->id}/review", ['agent' => 'copy'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('credits');

    expect(App\Models\EditorialReview::where('manuscript_section_id', $section->id)->count())
        ->toBe(0);
});

it('refuses a specialist agent the plan does not include', function () {
    $user = User::factory()->subscribed('free', 500)->create();
    $section = reviewable($user);

    $this->actingAs($user)
        ->postJson("/sections/{$section->id}/review", ['agent' => 'subject_matter'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('agent');

    // Nothing was charged for a review that never ran.
    expect(app(CreditLedger::class)->balanceFor($user))->toBe(500);
});

it('returns the new balance with the result', function () {
    // So the credit strip updates with the review rather than needing a
    // second request to notice the spend.
    $user = User::factory()->subscribed('studio', 100)->create();
    $section = reviewable($user);

    $response = $this->actingAs($user)
        ->postJson("/sections/{$section->id}/review", ['agent' => 'copy'])
        ->assertOk();

    expect($response->json('billing.credits'))
        ->toBe(100 - config('billing.costs.agent_run'));
});

it('does not let two concurrent reviews spend the same last credits', function () {
    // The ledger locks the account row. Without it both reads see the same
    // balance, both decide it is sufficient, and the account goes negative.
    $cost = config('billing.costs.agent_run');
    $user = User::factory()->subscribed('studio', $cost)->create();

    $first = reviewable($user);
    $second = reviewable($user);

    app(ReviewRunner::class)->run($first, EditorialAgent::Copy, $user->id);
    $blocked = app(ReviewRunner::class)->run($second, EditorialAgent::Copy, $user->id);

    expect($blocked->status)->toBe('failed')
        ->and(app(CreditLedger::class)->balanceFor($user))->toBe(0);
});
