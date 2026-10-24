<?php

use App\Billing\CreditLedger;
use App\Billing\Entitlements;
use App\Billing\MonthlyAllowance;
use App\Models\User;

/**
 * The monthly allowance.
 *
 * Nothing granted it before: an account had a plan promising 1,000 credits a
 * month and a balance of zero, so every AI action was refused the moment plan
 * enforcement went in.
 */
it('grants the plan allowance on first use', function () {
    $user = User::factory()->create(['plan' => 'author']);

    app(MonthlyAllowance::class)->ensureGranted($user);

    expect(app(CreditLedger::class)->balanceFor($user))->toBe(1000)
        ->and($user->fresh()->credits_granted_at)->not->toBeNull();
});

it('does not grant twice in the same month', function () {
    // Called on every balance read, so this has to be safe to repeat.
    $user = User::factory()->create(['plan' => 'author']);
    $allowance = app(MonthlyAllowance::class);

    $allowance->ensureGranted($user);
    $allowance->ensureGranted($user->fresh());
    $allowance->ensureGranted($user->fresh());

    expect(app(CreditLedger::class)->balanceFor($user))->toBe(1000)
        ->and($user->creditTransactions()->where('kind', 'grant')->count())->toBe(1);
});

it('grants again the following month', function () {
    $user = User::factory()->create(['plan' => 'author']);

    app(MonthlyAllowance::class)->ensureGranted($user);

    $this->travel(1)->months();

    app(MonthlyAllowance::class)->ensureGranted($user->fresh());

    expect(app(CreditLedger::class)->balanceFor($user))->toBe(2000);
});

it('refuses a duplicate even if the timestamp is lost', function () {
    // The reference key is the real guard: the timestamp is only a
    // short-circuit, and a half-applied grant must not be repeatable.
    $user = User::factory()->create(['plan' => 'author']);

    app(MonthlyAllowance::class)->ensureGranted($user);

    $user->forceFill(['credits_granted_at' => null])->save();

    app(MonthlyAllowance::class)->ensureGranted($user->fresh());

    expect(app(CreditLedger::class)->balanceFor($user))->toBe(1000);
});

it('grants nothing on a plan with no allowance', function () {
    // The plan catalogue (the plans table) is what the limits read.
    setPlan('free', ['credits' => 0]);

    $user = User::factory()->create(['plan' => 'free']);

    app(MonthlyAllowance::class)->ensureGranted($user);

    expect($user->creditTransactions()->count())->toBe(0);
});

it('lets a brand new account run an editor without being topped up', function () {
    // A provider review is charged; the suite's FakeDriver is sample output
    // and is not (ReviewBillingTest).
    useChargingDriver();

    // The failure this exists to prevent: a new author signs up, is on a plan
    // that says it includes credits, and is told they cannot afford anything.
    $user = User::factory()->create(['plan' => 'author']);
    $book = $user->books()->create(['title' => 'A First Book']);

    $section = $book->sections()->create([
        'title' => 'Chapter One',
        'type' => 'chapter',
        'position' => 1000,
        'content' => ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'The the first sentence.']],
        ]]],
    ]);

    $this->actingAs($user)
        ->postJson("/sections/{$section->id}/review", ['agent' => 'copy'])
        ->assertOk();

    expect(app(CreditLedger::class)->balanceFor($user))
        ->toBe(1000 - config('billing.costs.agent_run'));
});

it('shows a granted balance on the billing screen', function () {
    $user = User::factory()->create(['plan' => 'studio']);

    $this->actingAs($user)
        ->get('/billing')
        ->assertOk();

    expect(app(CreditLedger::class)->balanceFor($user))->toBe(5000);
});

it('reports the allowance in the summary the credit strip reads', function () {
    $user = User::factory()->create(['plan' => 'author']);

    $summary = app(Entitlements::class)->summaryFor($user);

    expect($summary['credits'])->toBe(1000)
        ->and($summary['monthly_credits'])->toBe(1000)
        ->and($summary['low'])->toBeFalse();
});
