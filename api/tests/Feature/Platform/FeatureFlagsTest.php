<?php

use App\Models\Book;
use App\Models\User;
use App\Platform\Features;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Feature flags: the order things are decided in (prerequisite, account
 * override, platform switch, default), the audit trail, the entry points
 * and the map shared with every page.
 */

it('resolves an account override over the platform switch over the default', function () {
    $owner = platformAdmin();
    $ada = User::factory()->create();
    $bob = User::factory()->create();
    $features = app(Features::class);

    expect($features->explain('preview', $ada))->toBe(['enabled' => true, 'source' => 'default']);

    $features->setGlobal('preview', false, 'Renderer rewrite in progress', $owner);
    expect($features->explain('preview', $ada))->toBe(['enabled' => false, 'source' => 'global']);

    $features->setOverride($ada, 'preview', true, 'Beta tester', $owner);
    expect($features->explain('preview', $ada))->toBe(['enabled' => true, 'source' => 'override'])
        ->and($features->explain('preview', $bob))->toBe(['enabled' => false, 'source' => 'global']);

    // Removing the override hands the decision back to the switch.
    $features->setOverride($ada, 'preview', null, 'Beta over', $owner);
    expect(Features::enabled('preview', $ada))->toBeFalse();

    // And "default" removes the switch.
    $features->setGlobal('preview', null, 'Shipped', $owner);
    expect(feature('preview', $ada))->toBeTrue();

    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'feature.global', 'reason' => 'Renderer rewrite in progress']);
    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'feature.override', 'subject_id' => $ada->id, 'reason' => 'Beta tester']);
});

it('never turns on a module whose prerequisite is missing', function () {
    config(['commerce.enabled' => false]);
    $owner = platformAdmin();
    $ada = User::factory()->create();

    app(Features::class)->setOverride($ada, 'storefront', true, 'Wants to sell', $owner);

    expect(app(Features::class)->explain('storefront', $ada))->toBe(['enabled' => false, 'source' => 'unavailable']);
});

it('refuses at the module entry point when it is off for this account', function () {
    $owner = platformAdmin();
    $ada = User::factory()->create();
    $book = Book::factory()->for($ada)->create();

    $this->actingAs($ada)->get('/inbox')->assertOk();

    app(Features::class)->setOverride($ada, 'inbox', false, 'Inbox bug on this account', $owner);
    app(Features::class)->setGlobal('preview', false, 'Renderer rewrite', $owner);
    app(Features::class)->setOverride($ada, 'audiobook', false, 'Hide coming soon', $owner);

    $this->actingAs($ada)->get('/inbox')->assertNotFound();
    $this->actingAs($ada)->get("/books/{$book->id}/preview")->assertNotFound();
    $this->actingAs($ada)->getJson("/books/{$book->id}/coming-soon/audio")->assertNotFound();
    $this->actingAs($ada)->getJson("/books/{$book->id}/coming-soon/course")->assertOk();
});

it('shares the resolved flags with every page, so hidden modules leave the navigation', function () {
    $owner = platformAdmin();
    $ada = User::factory()->create();
    app(Features::class)->setOverride($ada, 'support', false, 'Abusive tickets', $owner);
    app(Features::class)->setGlobal('audiobook', false, 'Not this year', $owner);

    $this->actingAs($ada)->get('/books')
        ->assertInertia(fn (Assert $page) => $page
            ->where('features.support', false)
            ->where('features.audiobook', false)
            ->where('features.inbox', true));
});

it('lets only the owner change a flag, with a reason', function () {
    $ada = User::factory()->create();

    $this->actingAs(platformAdmin('support'))->post('/admin/features', ['key' => 'preview', 'state' => 'off', 'reason' => 'Because'])->assertForbidden();
    $this->actingAs(platformAdmin())->post('/admin/features', ['key' => 'preview', 'state' => 'off'])->assertSessionHasErrors('reason');
    $this->actingAs(platformAdmin())->post('/admin/features', ['key' => 'nope', 'state' => 'off', 'reason' => 'Because'])->assertSessionHasErrors('key');

    expect(Features::enabled('preview', $ada))->toBeTrue();

    $this->actingAs(platformAdmin())->post("/admin/accounts/{$ada->id}/features", ['key' => 'preview', 'state' => 'off', 'reason' => 'Crashes their browser'])->assertSessionHas('platform_notice');
    app(Features::class)->flush();
    expect(Features::enabled('preview', $ada))->toBeFalse();

    $this->actingAs(platformAdmin('support'))->get('/admin/features')
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Features')->where('can_edit', false)->has('features', count(Features::CATALOG)));
});
