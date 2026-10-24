<?php

use App\Models\User;
use App\Models\UserLogin;
use App\Platform\TwoFactor;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;

/** A valid code for the step before or after the current one. */
function codeAt(User $user, int $offsetSteps): string
{
    return (new Google2FA)->oathTotp($user->two_factor_secret, (int) floor(time() / 30) + $offsetSteps);
}

function enrolled(): User
{
    $user = User::factory()->create();
    $twoFactor = app(TwoFactor::class);
    $twoFactor->begin($user);
    $twoFactor->confirm($user, $twoFactor->currentCode($user));

    return $user->fresh();
}

it('turns on in two steps: password, then a code from the app', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/user/two-factor', ['password' => 'wrong'])->assertSessionHasErrors('password');
    expect($user->fresh()->two_factor_secret)->toBeNull();

    $this->actingAs($user)->post('/user/two-factor', ['password' => 'password'])->assertRedirect();
    $user->refresh();

    expect($user->two_factor_secret)->not->toBeNull()
        ->and($user->hasTwoFactor())->toBeFalse();

    // The profile shows the QR code while it is half set up.
    $this->actingAs($user)->get('/profile')->assertInertia(fn (Assert $page) => $page
        ->where('two_factor.pending', true)
        ->where('two_factor.secret', $user->two_factor_secret)
        ->where('two_factor.qr_svg', fn ($svg) => str_contains($svg, '<svg')));

    $this->actingAs($user)->post('/user/two-factor/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
    expect($user->fresh()->hasTwoFactor())->toBeFalse();

    $this->actingAs($user)
        ->post('/user/two-factor/confirm', ['code' => app(TwoFactor::class)->currentCode($user)])
        ->assertSessionHas('two_factor_recovery_codes', fn ($codes) => count($codes) === 8);

    expect($user->fresh()->hasTwoFactor())->toBeTrue();
});

it('keeps the secret encrypted and the recovery codes hashed at rest', function () {
    $user = User::factory()->create();
    $twoFactor = app(TwoFactor::class);
    $secret = $twoFactor->begin($user);
    $codes = $twoFactor->confirm($user, $twoFactor->currentCode($user));

    $raw = DB::table('users')->where('id', $user->id)->first();

    expect($raw->two_factor_secret)->not->toContain($secret)
        ->and($raw->two_factor_recovery_codes)->not->toContain($codes[0])
        ->and(decrypt($raw->two_factor_recovery_codes, false))->not->toContain($codes[0]);
});

it('asks for a code after the password, and signs in only once it is right', function () {
    $user = enrolled();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.challenge'));
    $this->assertGuest();

    $this->get('/two-factor-challenge')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/TwoFactorChallenge'));

    $this->post('/two-factor-challenge', ['code' => '123456'])->assertSessionHasErrors('code');
    $this->assertGuest();

    $this->post('/two-factor-challenge', ['code' => codeAt($user, 1)])->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);

    expect(UserLogin::where('user_id', $user->id)->value('method'))->toBe('two_factor');
});

it('refuses the same code twice', function () {
    $user = enrolled();
    $code = codeAt($user, 1);

    expect(app(TwoFactor::class)->verify($user, $code))->toBeTrue()
        ->and(app(TwoFactor::class)->verify($user->fresh(), $code))->toBeFalse();
});

it('accepts each recovery code once', function () {
    $user = User::factory()->create();
    $twoFactor = app(TwoFactor::class);
    $twoFactor->begin($user);
    $codes = $twoFactor->confirm($user, $twoFactor->currentCode($user));

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->post('/two-factor-challenge', ['recovery_code' => $codes[2]])->assertRedirect();
    $this->assertAuthenticatedAs($user);
    expect($twoFactor->remainingRecoveryCodes($user->fresh()))->toBe(7);

    $this->post('/logout');
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->post('/two-factor-challenge', ['recovery_code' => $codes[2]])->assertSessionHasErrors('recovery_code');
    $this->assertGuest();
});

it('forgets a pending sign-in after five minutes', function () {
    $user = enrolled();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->travel(6)->minutes();

    $this->post('/two-factor-challenge', ['code' => app(TwoFactor::class)->currentCode($user)])
        ->assertRedirect(route('login'));
    $this->assertGuest();
});

it('throttles code guesses', function () {
    $user = enrolled();
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    for ($i = 0; $i < 5; $i++) {
        $this->post('/two-factor-challenge', ['code' => '000000']);
    }

    $this->post('/two-factor-challenge', ['code' => '000000'])->assertStatus(429);
});

it('turns off only with the password and a current code', function () {
    $user = enrolled();

    $this->actingAs($user)->delete('/user/two-factor', ['password' => 'password', 'code' => '000000'])->assertSessionHasErrors('code');
    expect($user->fresh()->hasTwoFactor())->toBeTrue();

    $this->actingAs($user)->delete('/user/two-factor', ['password' => 'nope', 'code' => codeAt($user, 1)])->assertSessionHasErrors('password');
    expect($user->fresh()->hasTwoFactor())->toBeTrue();

    $this->actingAs($user)->delete('/user/two-factor', ['password' => 'password', 'code' => codeAt($user, 1)])->assertRedirect();
    expect($user->fresh()->two_factor_secret)->toBeNull();
});

it('makes new recovery codes, and the old ones stop working', function () {
    $user = User::factory()->create();
    $twoFactor = app(TwoFactor::class);
    $twoFactor->begin($user);
    $old = $twoFactor->confirm($user, $twoFactor->currentCode($user));

    $this->actingAs($user)
        ->post('/user/two-factor/recovery-codes', ['password' => 'password', 'code' => codeAt($user, 1)])
        ->assertSessionHas('two_factor_recovery_codes');

    expect($twoFactor->useRecoveryCode($user->fresh(), $old[0]))->toBeFalse();
});

it('signs in without a challenge when two-factor is off', function () {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
    expect(UserLogin::where('user_id', $user->id)->value('method'))->toBe('password');
});

it('lets staff finish setting up two-factor from the console and then opens it', function () {
    $admin = platformAdmin('support', twoFactor: false);

    $this->actingAs($admin)->post('/user/two-factor', ['password' => 'password']);
    $this->actingAs($admin)->get('/admin/two-factor')
        ->assertInertia(fn (Assert $page) => $page->where('two_factor.pending', true)->where('two_factor.required', true));
    $this->actingAs($admin)->post('/user/two-factor/confirm', ['code' => app(TwoFactor::class)->currentCode($admin->fresh())]);

    $this->actingAs($admin->fresh())->get('/admin')->assertOk();
});
