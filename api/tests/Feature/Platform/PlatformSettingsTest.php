<?php

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Platform\PlatformSettings;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Platform settings: maintenance, the announcement, sign-ups and staff 2FA.
 * Every change goes through the console and into the audit log.
 */

function setPlatform(array $values): void
{
    $owner = platformAdmin();
    app(PlatformSettings::class)->update($values, $owner);
    app('auth')->forgetGuards();
}

it('saves settings from the console with a before and after in the audit log', function () {
    $owner = platformAdmin();

    $this->actingAs($owner)->patch('/admin/settings', [
        'settings' => ['maintenance_mode' => true, 'maintenance_message' => 'Back at noon'],
        'reason' => 'Database upgrade',
    ])->assertSessionHasNoErrors();

    expect(app(PlatformSettings::class)->maintenance())->toBeTrue();

    $log = AdminAuditLog::where('action', 'settings.updated')->sole();
    expect($log->before)->toBe(['maintenance_mode' => false, 'maintenance_message' => ''])
        ->and($log->after)->toBe(['maintenance_mode' => true, 'maintenance_message' => 'Back at noon'])
        ->and($log->reason)->toBe('Database upgrade');
});

it('rejects a value of the wrong type', function () {
    $this->actingAs(platformAdmin())
        ->patch('/admin/settings', ['settings' => ['announcement_tone' => 'neon']])
        ->assertSessionHasErrors('announcement_tone');

    expect(AdminAuditLog::count())->toBe(0);
});

it('will not require two-factor for staff from an account without it', function () {
    $owner = platformAdmin();
    app(PlatformSettings::class)->update(['require_2fa_for_admins' => false], $owner);

    $noTwoFactor = platformAdmin('owner', twoFactor: false);

    $this->actingAs($noTwoFactor)
        ->patch('/admin/settings', ['settings' => ['require_2fa_for_admins' => true]])
        ->assertSessionHasErrors(['require_2fa_for_admins' => 'Turn on two-factor sign-in for your own account first.']);

    expect(app(PlatformSettings::class)->get('require_2fa_for_admins'))->toBeFalse();
});

it('requires two-factor for staff by default', function () {
    expect(app(PlatformSettings::class)->get('require_2fa_for_admins'))->toBeTrue();
});

it('shows writers the maintenance page while staff carry on', function () {
    setPlatform(['maintenance_mode' => true, 'maintenance_message' => 'Back at noon']);

    $this->actingAs(User::factory()->create())->get('/books')
        ->assertStatus(503)
        ->assertInertia(fn (Assert $page) => $page->component('Maintenance')->where('message', 'Back at noon'));

    $this->actingAs(User::factory()->create())->getJson('/billing/summary')->assertStatus(503)->assertJson(['code' => 'maintenance']);

    $this->actingAs(platformAdmin('support'))->get('/books')->assertOk();
    $this->actingAs(platformAdmin('support'))->get('/admin')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('platform.maintenance', true));
});

it('keeps sign-in, the status endpoint and the health check open during maintenance', function () {
    setPlatform(['maintenance_mode' => true]);

    $this->get('/login')->assertOk();
    $this->get('/up')->assertOk();
    $this->getJson('/status')->assertOk()->assertJson(['status' => 'maintenance']);
    $this->get('/register')->assertStatus(503);

    // Staff can still sign in.
    $admin = platformAdmin('support');
    $admin->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
    $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect();
    $this->assertAuthenticatedAs($admin);
});

it('reports ok on the status endpoint normally', function () {
    $this->getJson('/status')->assertOk()->assertJson(['status' => 'ok', 'message' => null]);
});

it('shows the announcement to its audience until it ends', function () {
    setPlatform(['announcement_enabled' => true, 'announcement_message' => 'Exports are slow today', 'announcement_tone' => 'warning', 'announcement_audience' => 'writers', 'announcement_ends_at' => now()->addHour()->toIso8601String()]);

    $writer = User::factory()->create();

    $this->actingAs($writer)->get('/books')->assertInertia(fn (Assert $page) => $page
        ->where('platform.announcement.message', 'Exports are slow today')
        ->where('platform.announcement.tone', 'warning'));

    $this->actingAs(platformAdmin('support'))->get('/books')
        ->assertInertia(fn (Assert $page) => $page->where('platform.announcement', null));

    $this->travel(2)->hours();
    $this->actingAs($writer)->get('/books')
        ->assertInertia(fn (Assert $page) => $page->where('platform.announcement', null));
});

it('shows an "all" announcement on the sign-in page', function () {
    setPlatform(['announcement_enabled' => true, 'announcement_message' => 'Welcome', 'announcement_audience' => 'all']);

    $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('platform.announcement.message', 'Welcome'));
});

it('closes registration when new sign-ups are off', function () {
    setPlatform(['new_signups_open' => false]);

    $this->get('/register')->assertInertia(fn (Assert $page) => $page->where('signups_open', false));

    $this->post('/register', ['name' => 'New', 'email' => 'new@example.test', 'password' => 'password-123-ok', 'password_confirmation' => 'password-123-ok'])
        ->assertSessionHasErrors('email');

    expect(User::where('email', 'new@example.test')->exists())->toBeFalse();
});
