<?php

use App\Editorial\EditorialAgent;

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('new users can register', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('the registration offer is derived from the free plan and the agent catalogue', function () {
    // The page used to promise "all seven editorial agents ... free": there
    // were nine, and four of them ran on the free plan.
    $free = collect(EditorialAgent::cases())
        ->filter(fn ($agent) => $agent->minimumTier() <= config('billing.plans.free.tier'))
        ->map->label()
        ->values()
        ->all();

    $this->get('/register')->assertInertia(fn ($page) => $page
        ->component('Auth/Register')
        ->where('offer.books', config('billing.plans.free.books'))
        ->where('offer.credits', config('billing.plans.free.credits'))
        ->where('offer.agents_total', count(EditorialAgent::cases()))
        ->where('offer.agents_free', $free)
    );

    expect($free)->toContain('Copy Editor')->not->toContain('Fact Checker');
});
