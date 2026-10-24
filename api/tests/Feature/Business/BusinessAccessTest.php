<?php

use App\Models\Audience;
use App\Models\Broadcast;
use App\Models\DiscountCode;
use App\Models\LifecycleCampaign;
use App\Models\Plan;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Who reaches the business pages: 404 for guests and authors (the console is
 * never confirmed), every page for staff, changes for the owner only.
 */

const BIZ_PAGES = [
    '/admin/billing' => 'Admin/Business/Billing',
    '/admin/billing?tab=subscriptions' => 'Admin/Business/Billing',
    '/admin/billing?tab=trials' => 'Admin/Business/Billing',
    '/admin/billing?tab=past-due' => 'Admin/Business/Billing',
    '/admin/billing?tab=cancellations' => 'Admin/Business/Billing',
    '/admin/billing?tab=packs' => 'Admin/Business/Billing',
    '/admin/plans' => 'Admin/Business/Plans',
    '/admin/sales' => 'Admin/Business/Sales',
    '/admin/onboarding' => 'Admin/Business/Onboarding',
    '/admin/growth/acquisition' => 'Admin/Business/Growth/Acquisition',
    '/admin/growth/audiences' => 'Admin/Business/Growth/Audiences',
    '/admin/growth/broadcasts' => 'Admin/Business/Growth/Broadcasts',
    '/admin/growth/broadcasts/new' => 'Admin/Business/Growth/BroadcastEdit',
    '/admin/growth/discounts' => 'Admin/Business/Growth/Discounts',
    '/admin/growth/lifecycle' => 'Admin/Business/Growth/Lifecycle',
];

it('answers 404 to guests and authors on every business page', function (string $path) {
    $this->get($path)->assertNotFound();
    $this->actingAs(User::factory()->create())->get($path)->assertNotFound();
})->with(array_keys(BIZ_PAGES));

it('opens every business page to owner and support', function (string $role) {
    $admin = platformAdmin($role);

    foreach (BIZ_PAGES as $path => $component) {
        $this->actingAs($admin)->get($path)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component($component));
    }
})->with(['owner', 'support']);

it('sends staff without two-factor to its set-up page', function () {
    $this->actingAs(platformAdmin('owner', twoFactor: false))->get('/admin/billing')->assertRedirect(route('admin.two-factor'));
});

it('answers 404 to an author posting to any business action', function () {
    $author = User::factory()->create();
    $plan = Plan::first();
    $order = bizPaidOrder(User::factory()->create(), 1000);

    $this->actingAs($author)->patch("/admin/plans/{$plan->id}", ['name' => 'Mine'])->assertNotFound();
    $this->actingAs($author)->post("/admin/billing/orders/{$order->id}/refund", ['reason' => 'Mine'])->assertNotFound();
    $this->actingAs($author)->post('/admin/growth/broadcasts', ['subject' => 'Hi', 'body' => 'Spam'])->assertNotFound();
    $this->actingAs($author)->post('/admin/growth/discounts', ['code' => 'FREE'])->assertNotFound();
    $this->actingAs($author)->postJson('/admin/growth/audiences/preview', [])->assertNotFound();

    expect($plan->fresh()->name)->not->toBe('Mine')
        ->and(Broadcast::count())->toBe(0)
        ->and(DiscountCode::count())->toBe(0);
});

it('keeps every business change from support', function () {
    $support = platformAdmin('support');
    $audience = Audience::create(['name' => 'A', 'rules' => []]);
    $campaign = LifecycleCampaign::first();
    $order = bizPaidOrder(User::factory()->create(), 1000);
    $seller = $order->items->first()->seller_id;

    $writes = [
        ['post', '/admin/plans'],
        ['patch', '/admin/plans/'.Plan::first()->id],
        ['post', "/admin/billing/orders/{$order->id}/refund"],
        ['post', "/admin/sales/payouts/{$seller}"],
        ['post', '/admin/growth/audiences'],
        ['patch', "/admin/growth/audiences/{$audience->id}"],
        ['delete', "/admin/growth/audiences/{$audience->id}"],
        ['post', '/admin/growth/broadcasts'],
        ['post', '/admin/growth/discounts'],
        ['patch', "/admin/growth/lifecycle/{$campaign->id}"],
        ['post', "/admin/growth/lifecycle/{$campaign->id}/test"],
    ];

    foreach ($writes as [$method, $path]) {
        $this->actingAs($support)->{$method}($path, ['reason' => 'Trying'])->assertForbidden();
    }

    expect(Audience::count())->toBe(1);
});
