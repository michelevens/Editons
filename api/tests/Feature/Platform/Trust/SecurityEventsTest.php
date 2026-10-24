<?php

use App\Models\LoginFailure;
use App\Models\User;
use App\Models\UserLogin;
use App\Platform\Trust\SignInEvents;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sign-in security: failed attempts recorded with a keyed hash (never the
 * address), lockouts, two-factor failures, new-location sign-ins, and the
 * console page that shows them.
 */

it('records a failed password with a hash of the address, the IP and the browser', function () {
    $author = User::factory()->create(['email' => 'ada@example.com']);

    $this->withHeader('User-Agent', 'TestBrowser/1.0')
        ->post('/login', ['email' => 'ada@example.com', 'password' => 'wrong'])
        ->assertSessionHasErrors('email');
    $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong']);

    $known = LoginFailure::where('user_id', $author->id)->sole();
    expect($known->kind)->toBe('password')
        ->and($known->email_hash)->toBe(SignInEvents::emailHash('ada@example.com'))
        ->and($known->ip)->toBe('127.0.0.1')
        ->and($known->user_agent)->toBe('TestBrowser/1.0');

    $unknown = LoginFailure::whereNull('user_id')->sole();
    expect($unknown->email_hash)->toBe(SignInEvents::emailHash('nobody@example.com'));

    // The address itself is nowhere in the table.
    expect(LoginFailure::query()->get()->toJson())->not->toContain('nobody@example.com')->not->toContain('ada@example.com');
});

it('records a lockout once a minute, not on every refused try', function () {
    User::factory()->create(['email' => 'locked@example.com']);

    foreach (range(1, 8) as $i) {
        $this->post('/login', ['email' => 'locked@example.com', 'password' => 'wrong']);
    }

    expect(LoginFailure::where('kind', 'password')->count())->toBe(5)
        ->and(LoginFailure::where('kind', 'lockout')->count())->toBe(1);
});

it('records a wrong two-factor code', function () {
    $user = platformAdmin('support');

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('two-factor.challenge'));
    $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');

    expect(LoginFailure::where('user_id', $user->id)->where('kind', 'two_factor')->exists())->toBeTrue();
});

it('flags a sign-in from a new network, and never the first one', function () {
    $user = User::factory()->create();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->post('/login', ['email' => $user->email, 'password' => 'password']);
    auth()->logout();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.200.99'])->post('/login', ['email' => $user->email, 'password' => 'password']);
    auth()->logout();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->post('/login', ['email' => $user->email, 'password' => 'password']);

    $logins = UserLogin::where('user_id', $user->id)->orderBy('id')->get();
    expect($logins)->toHaveCount(3)
        ->and($logins[0]->new_location)->toBeFalse()   // first ever
        ->and($logins[0]->network)->toBe('203.0.0.0/16')
        ->and($logins[1]->new_location)->toBeFalse()   // same /16
        ->and($logins[2]->new_location)->toBeTrue();   // new /16
});

it('uses the country from the edge when there is one', function () {
    $user = User::factory()->create();

    $this->withHeader('CF-IPCountry', 'CA')->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->post('/login', ['email' => $user->email, 'password' => 'password']);
    auth()->logout();
    // A different network in the same country is not new…
    $this->withHeader('CF-IPCountry', 'CA')->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->post('/login', ['email' => $user->email, 'password' => 'password']);
    auth()->logout();
    // …a new country is.
    $this->withHeader('CF-IPCountry', 'HT')->withServerVariables(['REMOTE_ADDR' => '198.51.100.8'])->post('/login', ['email' => $user->email, 'password' => 'password']);

    expect(UserLogin::where('user_id', $user->id)->orderBy('id')->pluck('new_location')->all())->toBe([false, false, true])
        ->and(UserLogin::where('user_id', $user->id)->latest('id')->value('country'))->toBe('HT');
});

it('shows platform sign-in security to staff, with two-factor adoption and staff accounts', function () {
    $owner = platformAdmin();
    User::factory()->count(2)->create();
    $this->post('/login', ['email' => 'ghost@example.com', 'password' => 'nope']);

    $this->actingAs($owner)->get('/admin/security?window=24h')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Trust/Security')
            ->where('summary.failed_passwords', 1)
            ->where('two_factor.staff', 1)
            ->where('two_factor.staff_with', 1)
            ->where('staff.0.email', $owner->email)
            ->where('staff.0.two_factor', true)
            ->has('sessions.note')
            ->where('events.0.type', 'failure'));
});

it('lets staff sign an account out from the security page (the phase-1 action)', function () {
    $author = User::factory()->create();

    $this->actingAs(platformAdmin('support'))->post("/admin/accounts/{$author->id}/sign-out", ['reason' => 'Suspicious sign-ins'])
        ->assertSessionHas('platform_notice');

    expect($author->fresh()->sessions_revoked_at)->not->toBeNull();
});
