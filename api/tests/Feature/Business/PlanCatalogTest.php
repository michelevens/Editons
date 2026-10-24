<?php

use App\Billing\Entitlements;
use App\Billing\PlanCatalog;
use App\Commerce\Gateway\PaymentGateway;
use App\Editorial\EditorialAgent;
use App\Models\AdminAuditLog;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\User;
use App\Platform\PlatformMetrics;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The plan catalogue: seeded from config/billing.php so nothing changes the
 * day it ships, edited by the owner, a price change is a new price, and the
 * limits read it.
 */

function bizPlanForm(Plan $plan, array $changes = []): array
{
    return $changes + [
        'name' => $plan->name,
        'price' => $plan->price_cents / 100,
        'credits' => $plan->credits,
        'books' => $plan->books,
        'seats' => $plan->seats,
        'formats' => $plan->formats,
        'tier' => $plan->tier,
        'visibility' => $plan->visibility,
        'reason' => 'Pricing review',
    ];
}

it('seeds the catalogue from the config, field for field', function () {
    $catalog = app(PlanCatalog::class)->all();

    expect(array_keys($catalog))->toBe(array_keys(config('billing.plans')));

    foreach (config('billing.plans') as $key => $plan) {
        foreach (['name', 'price', 'credits', 'books', 'seats', 'formats', 'tier'] as $field) {
            expect($catalog[$key][$field])->toBe($plan[$field], "{$key}.{$field}");
        }

        expect(PlanPrice::whereHas('plan', fn ($q) => $q->where('key', $key))->count())->toBe(1);
    }
});

it('keeps every limit what it was', function () {
    foreach (config('billing.plans') as $key => $plan) {
        $user = User::factory()->create(['plan' => $key]);

        expect($user->planLimits()['credits'])->toBe($plan['credits'])
            ->and($user->planLimits()['books'])->toBe($plan['books'])
            ->and(app(Entitlements::class)->tier($user))->toBe($plan['tier']);

        foreach (['pdf', 'epub', 'docx', 'md', 'html'] as $format) {
            expect($user->canExportFormat($format))->toBe(in_array($format, $plan['formats'], true));
        }
    }

    // The upgrade message names the same plan it did.
    $free = User::factory()->create(['plan' => 'free']);
    $agent = collect(EditorialAgent::cases())->first(fn ($a) => $a->minimumTier() === 1);

    expect(fn () => app(Entitlements::class)->assertCanRunAgent($free, $agent))
        ->toThrow(ValidationException::class, 'part of the Author plan');
});

it('falls back to the config when the table is empty', function () {
    Plan::query()->delete();
    app(PlanCatalog::class)->forget();

    // (No account can exist then: users.plan is a foreign key to the table.)
    expect(app(PlanCatalog::class)->get('studio')['credits'])->toBe(config('billing.plans.studio.credits'))
        ->and(array_keys(app(PlanCatalog::class)->all()))->toBe(['free', 'author', 'studio']);
});

it('creates a new Stripe price on a price change, and keeps the old subscribers valued at theirs', function () {
    $owner = platformAdmin();
    $author = Plan::where('key', 'author')->first();
    $author->forceFill(['stripe_price_id' => 'price_old_author'])->save();
    PlanPrice::where('plan_id', $author->id)->update(['stripe_price_id' => 'price_old_author']);
    bizSubscription(User::factory()->create(['plan' => 'author']), 'active', 'price_old_author');

    $this->actingAs($owner)
        ->patch(route('admin.plans.update', $author), bizPlanForm($author, ['price' => 24, 'credits' => 1500]))
        ->assertRedirect()
        ->assertSessionHas('platform_notice', fn ($n) => str_contains($n, 'new Stripe price'));

    $gateway = app(PaymentGateway::class);
    $newPrice = array_key_first($gateway->prices);
    $author->refresh();

    expect($gateway->prices[$newPrice])->toMatchArray(['plan_key' => 'author', 'amount_cents' => 2400, 'replaces' => 'price_old_author'])
        ->and($author->price_cents)->toBe(2400)
        ->and($author->stripe_price_id)->toBe($newPrice)
        // The old price is retired, not edited.
        ->and(PlanPrice::where('stripe_price_id', 'price_old_author')->first()->amount_cents)->toBe(1900)
        ->and(PlanPrice::where('stripe_price_id', 'price_old_author')->first()->retired_at)->not->toBeNull()
        ->and(app(PlanCatalog::class)->stripePriceId('author'))->toBe($newPrice)
        // The existing subscriber is still worth what they pay.
        ->and(app(PlatformMetrics::class)->mrr()['amount_cents'])->toBe(1900)
        // And the new allowance applies at once.
        ->and(User::factory()->create(['plan' => 'author'])->planLimits()['credits'])->toBe(1500);

    $log = AdminAuditLog::where('action', 'plan.update')->sole();
    expect($log->before)->toMatchArray(['price_cents' => 1900, 'credits' => 1000, 'stripe_price_id' => 'price_old_author'])
        ->and($log->after)->toMatchArray(['price_cents' => 2400, 'credits' => 1500, 'stripe_price_id' => $newPrice])
        ->and($log->reason)->toBe('Pricing review');
});

it('records a price change without Stripe, and says so', function () {
    config(['commerce.gateway' => 'stripe', 'cashier.secret' => null]);
    $author = Plan::where('key', 'author')->first();

    $this->actingAs(platformAdmin())
        ->patch(route('admin.plans.update', $author), bizPlanForm($author, ['price' => 21]))
        ->assertSessionHas('platform_notice', fn ($n) => str_contains($n, 'Stripe is not configured'));

    expect($author->fresh()->price_cents)->toBe(2100)
        ->and($author->fresh()->stripe_price_id)->toBeNull()
        ->and($author->prices()->count())->toBe(2);
});

it('does not touch Stripe when only the limits change', function () {
    $studio = Plan::where('key', 'studio')->first();

    $this->actingAs(platformAdmin())->patch(route('admin.plans.update', $studio), bizPlanForm($studio, ['books' => 50, 'visibility' => 'legacy']))->assertRedirect();

    expect(app(PaymentGateway::class)->prices)->toBe([])
        ->and($studio->prices()->count())->toBe(1)
        ->and(User::factory()->create(['plan' => 'studio'])->planLimits()['books'])->toBe(50);
});

it('keeps Free free and public', function () {
    $free = Plan::where('key', 'free')->first();

    $this->actingAs(platformAdmin())->patch(route('admin.plans.update', $free), bizPlanForm($free, ['price' => 5]))->assertSessionHasErrors('price_cents');
    $this->actingAs(platformAdmin())->patch(route('admin.plans.update', $free), bizPlanForm($free, ['visibility' => 'hidden']))->assertSessionHasErrors('visibility');

    expect($free->fresh()->price_cents)->toBe(0);
});

it('hides a hidden plan from the billing page, except from the people on it', function () {
    Plan::where('key', 'studio')->update(['visibility' => 'hidden']);

    $this->actingAs(User::factory()->create(['plan' => 'free']))->get('/billing')
        ->assertInertia(fn (Assert $page) => $page->where('plans', fn ($plans) => collect($plans)->pluck('key')->all() === ['free', 'author']));

    $this->actingAs(User::factory()->create(['plan' => 'studio']))->get('/billing')
        ->assertInertia(fn (Assert $page) => $page->where('plans', fn ($plans) => collect($plans)->pluck('key')->contains('studio')));
});

it('creates a plan, which can then be given as a complimentary plan', function () {
    $owner = platformAdmin();

    $this->actingAs($owner)->post(route('admin.plans.store'), [
        'key' => 'school',
        'name' => 'School',
        'price' => 49,
        'credits' => 3000,
        'books' => 30,
        'seats' => 40,
        'formats' => ['pdf', 'epub', 'docx'],
        'tier' => 1,
        'visibility' => 'hidden',
        'reason' => 'Pilot with a lycée',
    ])->assertRedirect();

    $ada = User::factory()->create();
    $this->actingAs($owner)->post(route('admin.accounts.plan', $ada), ['action' => 'set', 'plan' => 'school', 'reason' => 'Pilot school'])->assertRedirect();

    expect($ada->fresh()->planLimits())->toMatchArray(['name' => 'School', 'books' => 30, 'credits' => 3000])
        ->and(AdminAuditLog::where('action', 'plan.create')->exists())->toBeTrue();
});

it('shows the catalogue to support and keeps changes from them', function () {
    $support = platformAdmin('support');
    $author = Plan::where('key', 'author')->first();

    $this->actingAs($support)->get('/admin/plans')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Business/Plans')->has('plans', 3)->where('can_edit', false));
    $this->actingAs($support)->patch(route('admin.plans.update', $author), bizPlanForm($author, ['price' => 1]))->assertForbidden();

    expect($author->fresh()->price_cents)->toBe(1900);
});
