<?php

use App\Models\User;

/**
 * Light, dark, or the device's own setting, saved on the account.
 *
 * The page sets the class on <html> itself before the request; what the
 * server owns is remembering the choice and printing it on the next page,
 * before the first paint, so a dark page never flashes white.
 */
it('starts every account on the system setting', function () {
    $user = User::factory()->create()->fresh();

    expect($user->theme)->toBe('system');
});

it('saves the theme to the account', function (string $theme) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patchJson('/profile/theme', ['theme' => $theme])
        ->assertOk()
        ->assertJson(['theme' => $theme]);

    expect($user->fresh()->theme)->toBe($theme);
})->with(['light', 'dark', 'system']);

it('refuses anything that is not a theme', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patchJson('/profile/theme', ['theme' => 'neon'])
        ->assertUnprocessable();

    expect($user->fresh()->theme)->toBe('system');
});

it('needs a signed-in account', function () {
    $this->patchJson('/profile/theme', ['theme' => 'dark'])->assertUnauthorized();
});

it('prints the saved theme on the page root before the app loads', function () {
    $user = User::factory()->create();
    $user->forceFill(['theme' => 'dark'])->save();

    $html = $this->actingAs($user)->get('/profile')->assertOk()->getContent();

    expect($html)->toMatch('/<html[^>]*data-theme="dark"/')
        ->and($html)->toMatch('/<html[^>]*class="dark"/');
});

it('leaves system and guests to the device', function () {
    $user = User::factory()->create();

    $html = $this->actingAs($user)->get('/profile')->getContent();
    expect($html)->toMatch('/<html[^>]*data-theme="system"/')
        ->and($html)->not->toMatch('/<html[^>]*class="dark"/')
        // The pre-paint script that reads prefers-color-scheme carries the
        // CSP nonce, or the browser would refuse to run it.
        ->and($html)->toMatch('/<script nonce="[^"]+">\s*\(function \(\) \{\s*var root/');

    auth()->logout();

    $this->get('/login')->assertSee('data-theme="system"', false);
});
