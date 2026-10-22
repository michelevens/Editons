<?php

use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

/**
 * The doors that need no account, and how often one address may knock.
 *
 * From the audit: ten registrations in a row from one IP all succeeded (each
 * with free credits), ten reset requests all answered, and a password tried
 * against rotating emails was never slowed.
 */
it('limits registrations from one address', function () {
    for ($i = 1; $i <= 5; $i++) {
        auth()->logout();

        $this->post('/register', [
            'name' => "Person {$i}",
            'email' => "person{$i}@example.com",
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasNoErrors();
    }

    auth()->logout();

    $this->post('/register', [
        'name' => 'One Too Many',
        'email' => 'sixth@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('email');

    expect(User::where('email', 'sixth@example.com')->exists())->toBeFalse();
});

it('counts each address separately', function () {
    $register = fn (string $ip, string $email) => $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->post('/register', [
            'name' => 'Someone',
            'email' => $email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

    for ($i = 1; $i <= 5; $i++) {
        auth()->logout();
        $register('198.51.100.1', "a{$i}@example.com");
    }

    auth()->logout();
    $register('198.51.100.2', 'other@example.com')->assertSessionHasNoErrors();
});

it('is not fooled by a made-up X-Forwarded-For', function () {
    // Each request claims a different client. The limit still holds, because
    // the address it keys on is the one a trusted hop recorded.
    for ($i = 1; $i <= 6; $i++) {
        auth()->logout();

        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])
            ->post('/register', [
                'name' => 'Spoofer',
                'email' => "spoof{$i}@example.com",
                'password' => 'password',
                'password_confirmation' => 'password',
            ], ['X-Forwarded-For' => "9.9.9.{$i}, 81.2.69.160"]);
    }

    $response->assertSessionHasErrors('email');
    expect(User::where('email', 'spoof6@example.com')->exists())->toBeFalse();
});

it('limits sign-in attempts per address, across different emails', function () {
    // Password spraying: one password, many accounts. The per-email lockout
    // never sees more than one attempt each.
    for ($i = 1; $i <= 20; $i++) {
        $this->post('/login', ['email' => "user{$i}@example.com", 'password' => 'Summer2026!']);
    }

    $response = $this->post('/login', ['email' => 'user21@example.com', 'password' => 'Summer2026!']);

    $response->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toContain('Too many attempts');
});

it('still locks one email after five failures', function () {
    $user = User::factory()->create();

    for ($i = 0; $i < 5; $i++) {
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
    }

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('answers a JSON caller with a 429', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/forgot-password', ['email' => "x{$i}@example.com"]);
    }

    $this->postJson('/forgot-password', ['email' => 'x6@example.com'])
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});

it('limits password reset requests from one address', function () {
    Notification::fake();
    $user = User::factory()->create();

    for ($i = 0; $i < 5; $i++) {
        $this->post('/forgot-password', ['email' => "nobody{$i}@example.com"]);
    }

    $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasErrors('email');

    Notification::assertNothingSent();
});

it('limits reset-password submissions from one address', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->post('/reset-password', [
            'token' => "guess{$i}", 'email' => 'a@example.com',
            'password' => 'password', 'password_confirmation' => 'password',
        ]);
    }

    $this->post('/reset-password', [
        'token' => 'guess6', 'email' => 'a@example.com',
        'password' => 'password', 'password_confirmation' => 'password',
    ])->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))->toStartWith('Too many attempts.');
});

// --- Account enumeration -------------------------------------------------

it('gives the same answer for a known and an unknown address', function () {
    Notification::fake();
    $user = User::factory()->create();

    $known = $this->post('/forgot-password', ['email' => $user->email]);
    $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.com']);

    $known->assertSessionHasNoErrors()->assertSessionHas('status', PasswordResetLinkController::SENT);
    $unknown->assertSessionHasNoErrors()->assertSessionHas('status', PasswordResetLinkController::SENT);

    Notification::assertSentTo($user, ResetPassword::class);
});

it('gives the same answer when a link was sent a moment ago', function () {
    // The broker throttles repeat requests per account, and "Please wait
    // before retrying" would confirm the account exists.
    Notification::fake();
    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    $this->post('/forgot-password', ['email' => $user->email])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', PasswordResetLinkController::SENT);
});

it('does not confirm an address on the reset form either', function () {
    $user = User::factory()->create();
    $payload = ['token' => 'not-a-token', 'password' => 'password', 'password_confirmation' => 'password'];

    $known = $this->post('/reset-password', [...$payload, 'email' => $user->email]);
    $unknown = $this->post('/reset-password', [...$payload, 'email' => 'nobody@example.com']);

    expect(session('errors'))->not->toBeNull();

    $known->assertSessionHasErrors(['email' => trans('passwords.token')]);
    $unknown->assertSessionHasErrors(['email' => trans('passwords.token')]);
});
