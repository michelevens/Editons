<?php

use App\Models\User;

/**
 * Where somebody lands, and how they get out.
 *
 * Two gaps that were invisible from inside the studio:
 *
 *  - Every Breeze auth controller redirects to `dashboard`, and that route
 *    rendered the stock "You're logged in!" placeholder. A new author's first
 *    screen after registering was not the product.
 *  - The avatar in the studio header was a <span>, so billing, the profile
 *    and sign out could not be reached from any studio page.
 */

// --- Arriving ------------------------------------------------------------

it('sends a brand new author somewhere they can write', function () {
    // The registration journey end to end: the first screen after signing up
    // must be the product, not a placeholder.
    $this->post('/register', [
        'name' => 'New Author',
        'email' => 'new@example.test',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ]);

    $this->assertAuthenticated();

    $this->get('/dashboard')->assertRedirect(route('books.quick'));
});

it('sends a returning author to their shelf', function () {
    $user = User::factory()->create();
    $user->books()->create(['title' => 'Already Writing']);

    $this->actingAs($user)->get('/dashboard')->assertRedirect(route('books.index'));
});

it('treats an author whose only book is archived as new', function () {
    // An empty shelf is an empty shelf. Sending them to a page listing
    // nothing is a worse welcome than asking what they want to write.
    $user = User::factory()->create();
    $user->books()->create(['title' => 'Put Away', 'status' => 'archived']);

    $this->actingAs($user)->get('/dashboard')->assertRedirect(route('books.quick'));
});

it('does not strand anybody on the stock placeholder', function () {
    // Asserted against the route rather than the page, because the failure
    // mode is a 200 rendering "You're logged in!" rather than an error.
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/dashboard');

    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->not->toContain('dashboard');
});

it('still requires signing in', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

// --- The account menu ----------------------------------------------------

it('gives every studio page what the account menu needs', function () {
    $user = User::factory()->create();
    $book = $user->books()->create(['title' => 'A Book']);

    foreach ([
        '/books',
        "/books/{$book->id}/overview",
        "/books/{$book->id}/edit",
        '/profile',
    ] as $url) {
        $account = $this->actingAs($user)->get($url)->viewData('page')['props']['auth']['account'];

        expect($account)->not->toBeNull("[{$url}] renders no account for the menu.");
        expect($account)->toHaveKeys(['plan_name', 'credits', 'credits_pct', 'books_active']);
    }
});

it('counts only the books still on the shelf', function () {
    $user = User::factory()->create(['plan' => 'studio']);
    $user->books()->create(['title' => 'Current']);
    $user->books()->create(['title' => 'Also Current']);
    $user->books()->create(['title' => 'Put Away', 'status' => 'archived']);

    $account = $this->actingAs($user)->get('/books')->viewData('page')['props']['auth']['account'];

    expect($account['books_active'])->toBe(2);
});

it('shares nothing about an account to somebody signed out', function () {
    $auth = $this->get('/login')->viewData('page')['props']['auth'];

    expect($auth['account'])->toBeNull();
});

it('lets a newly registered author sign out of the screen they land on', function () {
    // Quick Start has no studio header, and it is exactly where registration
    // sends somebody — so without its own link a new account had no way out.
    $quick = file_get_contents(resource_path('js/Pages/Books/QuickStart.jsx'));

    $this->assertStringContainsString("route('logout')", $quick, 'Quick Start offers no way to sign out.');
});

it('keeps the menu reachable from the studio header', function () {
    // The regression this guards: the avatar was markup with no behaviour,
    // and sign out was unreachable without typing a URL. Asserted against
    // the source, since Inertia renders the markup on the client.
    $header = file_get_contents(resource_path('js/Components/Studio/AppHeader.jsx'));
    $menu = file_get_contents(resource_path('js/Components/Studio/UserMenu.jsx'));

    $this->assertStringContainsString('<UserMenu', $header, 'The header no longer renders the account menu.');
    $this->assertStringContainsString("route('logout')", $menu, 'The account menu has no way to sign out.');
    // The destinations are route NAMES in the ITEMS table, resolved at click
    // time, so the source carries 'billing.show' rather than route(...).
    $this->assertStringContainsString("'billing.show'", $menu, 'The account menu has no way to reach billing.');
});
