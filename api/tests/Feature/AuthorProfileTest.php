<?php

use App\Models\User;

/**
 * The author's profile.
 *
 * Most of these are about what a profile form must NOT be able to change.
 * It is the one form in the product that posts arbitrary fields about the
 * account itself, so it is the obvious place to try to grant yourself a plan.
 */

it('saves the author\'s own details', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/profile', [
            'name' => 'Margaret Hale',
            'email' => $user->email,
            'author_name' => 'M. Hale',
            'bio' => 'Writes about industry and the people in it.',
            'website' => 'https://example.com',
        ])
        ->assertRedirect();

    $user->refresh();

    expect($user->name)->toBe('Margaret Hale')
        ->and($user->author_name)->toBe('M. Hale')
        ->and($user->bio)->toContain('industry')
        ->and($user->website)->toBe('https://example.com');
});

it('keeps the account name and the cover name separate', function () {
    // They are routinely different — a pen name, or a name with credentials
    // after it — and changing one must not change the other.
    $user = User::factory()->create(['name' => 'Jo March', 'author_name' => 'J. March']);

    $this->actingAs($user)->patch('/profile', [
        'name' => 'Josephine March',
        'email' => $user->email,
        'author_name' => 'J. March',
    ]);

    expect($user->fresh()->name)->toBe('Josephine March')
        ->and($user->fresh()->author_name)->toBe('J. March');
});

it('saves writing preferences', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch('/profile', [
        'name' => $user->name,
        'email' => $user->email,
        'default_book_type' => 'memoir',
        'daily_word_goal' => 750,
    ]);

    expect($user->fresh()->default_book_type)->toBe('memoir')
        ->and($user->fresh()->daily_word_goal)->toBe(750);
});

it('rejects a default book type that does not exist', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'default_book_type' => 'interpretive_dance',
        ])
        ->assertSessionHasErrors('default_book_type');
});

it('rejects a word goal nobody could write', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'daily_word_goal' => 900000,
        ])
        ->assertSessionHasErrors('daily_word_goal');
});

it('rejects a website that is not a URL', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'website' => 'not a website',
        ])
        ->assertSessionHasErrors('website');
});

// --- What the form must not be able to do -------------------------------

it('will not let somebody give themselves a plan', function () {
    // The obvious attack on a profile form, and the reason `plan` is not
    // fillable: what somebody is subscribed to is decided by billing.
    $user = User::factory()->create(['plan' => 'free']);

    $this->actingAs($user)->patch('/profile', [
        'name' => $user->name,
        'email' => $user->email,
        'plan' => 'studio',
    ]);

    expect($user->fresh()->plan)->toBe('free');
});

it('will not let somebody grant themselves credits', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch('/profile', [
        'name' => $user->name,
        'email' => $user->email,
        'credits_granted_at' => null,
        'stripe_id' => 'cus_free_money',
    ]);

    expect($user->fresh()->stripe_id)->toBeNull();
});

it('will not let somebody verify their own email by posting a date', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->patch('/profile', [
        'name' => $user->name,
        'email' => $user->email,
        'email_verified_at' => now(),
    ]);

    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('clears verification when the email actually changes', function () {
    // Breeze's behaviour, asserted because the test above would pass just as
    // happily if verification were simply never set.
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)->patch('/profile', [
        'name' => $user->name,
        'email' => 'somewhere.else@example.com',
    ]);

    expect($user->fresh()->email_verified_at)->toBeNull();
});

// --- What the screen shows ----------------------------------------------

it('shows the plan and balance without a trip to billing', function () {
    $user = User::factory()->subscribed('author', 420)->create();

    $plan = $this->actingAs($user)->get('/profile')->viewData('page')['props']['plan'];

    expect($plan['name'])->toBe('Author')
        ->and($plan['credits'])->toBe(420)
        ->and($plan['monthly_credits'])->toBe(1000);
});

it('counts books against the plan limit', function () {
    $user = User::factory()->create(['plan' => 'free']);
    $user->books()->create(['title' => 'The One Book']);

    $plan = $this->actingAs($user)->get('/profile')->viewData('page')['props']['plan'];

    expect($plan['books'])->toBe(1)
        ->and($plan['book_limit'])->toBe(config('billing.plans.free.books'));
});

it('offers every book type as a default', function () {
    $user = User::factory()->create();

    $types = $this->actingAs($user)->get('/profile')->viewData('page')['props']['book_types'];

    expect($types)->toHaveCount(count(config('booktypes')));
});

it('requires signing in', function () {
    $this->get('/profile')->assertRedirect('/login');
});

it('offers the author\'s usual kind of book when they start a new one', function () {
    // A preference nothing reads is not a preference. The shelf carries it so
    // the flow can open past a question already answered.
    $user = User::factory()->create(['default_book_type' => 'memoir']);

    $props = $this->actingAs($user)->get('/books')->viewData('page')['props'];

    expect($props['default_book_type'])->toBe('memoir');
});

it('asks each time when no default is set', function () {
    $user = User::factory()->create();

    expect($this->actingAs($user)->get('/books')->viewData('page')['props']['default_book_type'])
        ->toBeNull();
});
