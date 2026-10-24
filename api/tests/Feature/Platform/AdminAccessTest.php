<?php

use App\Models\User;
use App\Platform\PlatformSettings;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Who reaches the admin console. Everybody who is not staff gets 404 — not
 * 403, and not a redirect to sign in — so its existence is never confirmed.
 */

it('answers 404 to guests and authors on every console path', function (string $path) {
    $this->get($path)->assertNotFound();

    $this->actingAs(User::factory()->create())->get($path)->assertNotFound();
})->with(['/admin', '/admin/accounts', '/admin/accounts/1', '/admin/audit', '/admin/audit/export', '/admin/settings', '/admin/two-factor', '/admin/impersonations']);

it('answers 404 to an author posting to a console action', function () {
    $author = User::factory()->create();
    $victim = User::factory()->create();

    $this->actingAs($author)
        ->post("/admin/accounts/{$victim->id}/suspend", ['reason' => 'Because I can'])
        ->assertNotFound();

    expect($victim->fresh()->isSuspended())->toBeFalse();
});

it('denies a stray /admin route outside the group, by default', function () {
    // A route someone forgot to put in the group: the global middleware
    // still refuses it.
    Route::middleware('web')->get('/admin/forgotten', fn () => 'open');

    $this->actingAs(User::factory()->create())->get('/admin/forgotten')->assertNotFound();
    $this->actingAs(platformAdmin())->get('/admin/forgotten')->assertOk()->assertSee('open');
});

it('opens the console to staff with two-factor', function (string $role) {
    $this->actingAs(platformAdmin($role))
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Dashboard')->has('live'));
})->with(['owner', 'support']);

it('sends staff without two-factor to its set-up page, and nowhere else', function () {
    $admin = platformAdmin('owner', twoFactor: false);

    $this->actingAs($admin)->get('/admin')->assertRedirect(route('admin.two-factor'));
    $this->actingAs($admin)->get('/admin/accounts')->assertRedirect(route('admin.two-factor'));
    $this->actingAs($admin)->get('/admin/two-factor')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/TwoFactor')->where('two_factor.enabled', false));
});

it('lets staff without two-factor in when the requirement is switched off', function () {
    $owner = platformAdmin();
    app(PlatformSettings::class)->update(['require_2fa_for_admins' => false], $owner);

    $this->actingAs(platformAdmin('support', twoFactor: false))->get('/admin')->assertOk();
});

it('keeps owner-only actions from support', function () {
    $support = platformAdmin('support');
    $author = User::factory()->create();

    $this->actingAs($support)->post("/admin/accounts/{$author->id}/suspend", ['reason' => 'Spam account'])->assertForbidden();
    $this->actingAs($support)->post("/admin/accounts/{$author->id}/plan", ['action' => 'set', 'plan' => 'studio', 'reason' => 'Gift'])->assertForbidden();
    $this->actingAs($support)->post("/admin/accounts/{$author->id}/role", ['role' => 'support', 'reason' => 'Promote'])->assertForbidden();
    $this->actingAs($support)->post("/admin/accounts/{$author->id}/two-factor-reset", ['reason' => 'Lost phone'])->assertForbidden();
    $this->actingAs($support)->patch('/admin/settings', ['settings' => ['maintenance_mode' => true]])->assertForbidden();

    expect($author->fresh()->isSuspended())->toBeFalse()
        ->and($author->fresh()->plan)->toBe('free')
        ->and(app(PlatformSettings::class)->maintenance())->toBeFalse();
});

it('lets support read accounts, the audit log and take notes', function () {
    $support = platformAdmin('support');
    $author = User::factory()->create();

    $this->actingAs($support)->get("/admin/accounts/{$author->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Accounts/Show')->where('account.id', $author->id)->where('can.owner', false));
    $this->actingAs($support)->get('/admin/audit')->assertOk();
    $this->actingAs($support)->post("/admin/accounts/{$author->id}/notes", ['body' => 'Called about export'])->assertRedirect();

    $this->assertDatabaseHas('admin_notes', ['user_id' => $author->id, 'author_id' => $support->id]);
});

it('finds accounts by email, name or id', function () {
    $admin = platformAdmin();
    $match = User::factory()->create(['email' => 'needle@example.test', 'name' => 'Ada Haystack']);
    User::factory()->create(['email' => 'other@example.test']);

    foreach (['needle', 'haystack', (string) $match->id] as $q) {
        $this->actingAs($admin)->get('/admin/accounts?q='.$q)
            ->assertInertia(fn (Assert $page) => $page->where('accounts.data.0.id', $match->id));
    }
});

it('shows the console link only to staff', function () {
    $this->actingAs(User::factory()->create())->get('/profile')
        ->assertInertia(fn (Assert $page) => $page->where('platform.role', null));

    $this->actingAs(platformAdmin('support'))->get('/profile')
        ->assertInertia(fn (Assert $page) => $page->where('platform.role', 'support'));
});

it('never sends the two-factor secret or recovery codes to the browser', function () {
    $admin = platformAdmin();

    $this->actingAs($admin)->get('/profile')
        ->assertInertia(fn (Assert $page) => $page
            ->missing('auth.user.two_factor_secret')
            ->missing('auth.user.two_factor_recovery_codes'));
});

it('throttles dangerous console actions', function () {
    $admin = platformAdmin();
    $author = User::factory()->create();

    for ($i = 0; $i < 20; $i++) {
        $this->actingAs($admin)->post("/admin/accounts/{$author->id}/sign-out", ['reason' => 'Test run '.$i]);
    }

    $this->actingAs($admin)->post("/admin/accounts/{$author->id}/sign-out", ['reason' => 'One too many'])->assertStatus(429);
});
