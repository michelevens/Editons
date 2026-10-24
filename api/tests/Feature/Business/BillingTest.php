<?php

use App\Billing\BillingReport;
use App\Billing\Payments;
use App\Billing\Refunds;
use App\Commerce\Gateway\PaymentGateway;
use App\Models\AdminAuditLog;
use App\Models\CreditTransaction;
use App\Models\PlatformMetric;
use App\Models\Refund;
use App\Models\SubscriptionCancellation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Cashier\Events\WebhookHandled;

/*
 * Billing in the console: revenue figures from real subscription rows, the
 * subscription state kept from Stripe's webhooks, and refunds through the
 * gateway — refused when there is no Stripe, audited when there is.
 */

beforeEach(function () {
    config(['billing.prices.author' => 'price_author', 'billing.prices.studio' => 'price_studio']);
});

it('says there are no paid subscriptions rather than showing zero', function () {
    $kpis = app(BillingReport::class)->kpis();

    expect($kpis['mrr_cents'])->toBeNull()
        ->and($kpis['arr_cents'])->toBeNull()
        ->and($kpis['arpa_cents'])->toBeNull();

    $this->actingAs(platformAdmin())->get('/admin/billing')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Business/Billing')->where('kpis.mrr_cents', null));
});

it('computes MRR, ARR, ARPA, new and churned MRR from the rows', function () {
    $ada = User::factory()->create(['plan' => 'author']);
    $bea = User::factory()->create(['plan' => 'studio']);
    $cy = User::factory()->create();
    $dee = User::factory()->create();

    bizSubscription($ada, 'active', 'price_author');                                  // 19, old
    bizSubscription($bea, 'past_due', 'price_studio', ['created_at' => now()->startOfMonth()->addHour()]); // 79, new this month
    bizSubscription($cy, 'trialing', 'price_studio', ['trial_ends_at' => now()->addDays(3)]); // not paying yet
    bizSubscription($dee, 'canceled', 'price_author', ['ends_at' => now()->startOfMonth()->addMinutes(5)]); // churned this month
    bizSubscription(User::factory()->create(), 'active', 'price_unknown');           // counted, not valued

    // Last night of last month, as the snapshot recorded it.
    PlatformMetric::create(['day' => now()->startOfMonth()->subDay()->toDateString(), 'figures' => ['mrr' => 19], 'captured_at' => now()]);

    $k = app(BillingReport::class)->kpis();

    expect($k['mrr_cents'])->toBe(9800)
        ->and($k['arr_cents'])->toBe(117600)
        ->and($k['paying_accounts'])->toBe(3)
        ->and($k['arpa_cents'])->toBe(intdiv(9800, 3))
        ->and($k['unknown_prices'])->toBe(1)
        ->and($k['new_mrr_cents'])->toBe(7900)
        ->and($k['churned_mrr_cents'])->toBe(1900)
        ->and($k['net_new_mrr_cents'])->toBe(6000)
        ->and($k['opening_mrr_cents'])->toBe(1900)
        ->and($k['trialing'])->toBe(1)
        ->and($k['past_due'])->toBe(1);
});

it('lists subscriptions with filters, trials ending and the past-due list', function () {
    $ada = User::factory()->create(['email' => 'ada@example.com']);
    $bea = User::factory()->create(['email' => 'bea@example.com']);
    bizSubscription($ada, 'active', 'price_author', ['current_period_end' => now()->addDays(12)]);
    bizSubscription($bea, 'trialing', 'price_studio', ['trial_ends_at' => now()->addDays(5)]);
    bizSubscription(User::factory()->create(), 'trialing', 'price_studio', ['trial_ends_at' => now()->addDays(20)]);
    bizSubscription(User::factory()->create(), 'past_due', 'price_author', ['past_due_since' => now()->subDays(9)]);

    $report = app(BillingReport::class);
    $active = $report->list(['status' => 'active'])->items();
    $studio = $report->list(['plan' => 'studio'])->items();

    expect($active)->toHaveCount(1)
        ->and($active[0]['account']['email'])->toBe('ada@example.com')
        ->and($active[0]['mrr_cents'])->toBe(1900)
        ->and($active[0]['renews_at'])->not->toBeNull()
        ->and($studio)->toHaveCount(2)
        ->and($report->list(['q' => 'bea@'])->items())->toHaveCount(1)
        ->and($report->trialsEnding(7))->toHaveCount(1)
        ->and($report->trialsEnding(30))->toHaveCount(2)
        ->and($report->pastDue()[0]['days_overdue'])->toBe(9)
        ->and($report->pastDue()[0]['since_known'])->toBeTrue();

    $this->actingAs(platformAdmin('support'))->get('/admin/billing?tab=subscriptions&status=trialing')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('subscriptions.data', 2)->where('can_edit', false));
});

it('keeps the renewal date, dunning start and cancellation reason from Stripe webhooks', function () {
    Mail::fake();
    $ada = User::factory()->create(['stripe_id' => 'cus_ada']);
    bizSubscription($ada, 'active', 'price_author', ['stripe_id' => 'sub_ada']);
    $periodEnd = now()->addDays(20)->startOfSecond();

    event(new WebhookHandled(['id' => 'evt_1', 'type' => 'customer.subscription.updated', 'data' => ['object' => [
        'id' => 'sub_ada', 'customer' => 'cus_ada', 'cancel_at_period_end' => false,
        'items' => ['data' => [['current_period_end' => $periodEnd->getTimestamp(), 'price' => ['id' => 'price_author'], 'quantity' => 1]]],
    ], 'previous_attributes' => []]]));

    expect(DB::table('subscriptions')->where('stripe_id', 'sub_ada')->value('current_period_end'))->toContain($periodEnd->toDateString());

    event(new WebhookHandled(['id' => 'evt_2', 'type' => 'invoice.payment_failed', 'data' => ['object' => ['customer' => 'cus_ada', 'subscription' => 'sub_ada', 'lines' => ['data' => []]]]]));
    expect(DB::table('subscriptions')->where('stripe_id', 'sub_ada')->value('past_due_since'))->not->toBeNull();

    event(new WebhookHandled(['id' => 'evt_3', 'type' => 'customer.subscription.updated', 'data' => ['object' => [
        'id' => 'sub_ada', 'customer' => 'cus_ada', 'cancel_at_period_end' => true, 'canceled_at' => now()->getTimestamp(),
        'cancellation_details' => ['reason' => 'cancellation_requested', 'feedback' => 'too_expensive', 'comment' => 'Back after my exams'],
        'items' => ['data' => [['current_period_end' => $periodEnd->getTimestamp(), 'price' => ['id' => 'price_author'], 'quantity' => 1]]],
    ], 'previous_attributes' => ['cancel_at_period_end' => false]]]));

    $cancellation = SubscriptionCancellation::sole();
    expect($cancellation->user_id)->toBe($ada->id)
        ->and($cancellation->feedback)->toBe('too_expensive')
        ->and($cancellation->comment)->toBe('Back after my exams')
        ->and($cancellation->mrr_cents)->toBe(1900)
        ->and($cancellation->plan_key)->toBe('author');

    // Undone before the period ended: kept, marked resumed.
    event(new WebhookHandled(['id' => 'evt_4', 'type' => 'customer.subscription.updated', 'data' => ['object' => [
        'id' => 'sub_ada', 'customer' => 'cus_ada', 'cancel_at_period_end' => false,
        'items' => ['data' => [['current_period_end' => $periodEnd->getTimestamp(), 'price' => ['id' => 'price_author'], 'quantity' => 1]]],
    ], 'previous_attributes' => ['cancel_at_period_end' => true]]]));

    expect($cancellation->fresh()->resumed_at)->not->toBeNull()
        ->and(app(BillingReport::class)->cancellations(90)['summary'])->toMatchArray(['total' => 1, 'resumed' => 1])
        ->and(app(BillingReport::class)->cancellations(90)['breakdown'][0])->toMatchArray(['key' => 'too_expensive', 'count' => 1, 'pct' => 100]);
});

it('never lets the fake gateway move money in production', function () {
    config(['commerce.gateway' => 'fake', 'cashier.secret' => null]);
    expect(Payments::refusal())->toBeNull();

    app()->detectEnvironment(fn () => 'production');
    expect(Payments::refusal())->toBe(Payments::NOT_CONFIGURED);

    $order = bizPaidOrder(User::factory()->create(), 1200);
    expect(fn () => app(Refunds::class)->refundOrder($order, null, 'Test', platformAdmin()))
        ->toThrow(ValidationException::class, 'Stripe is not configured');
    expect(app(PaymentGateway::class)->refunds)->toBe([]);
});

it('refuses a refund when Stripe is off, with a sentence', function () {
    config(['commerce.gateway' => 'stripe', 'cashier.secret' => null]);
    $order = bizPaidOrder(User::factory()->create(), 1200);

    $this->actingAs(platformAdmin())
        ->post(route('admin.billing.refund-order', $order), ['reason' => 'Reader bought twice'])
        ->assertSessionHasErrors(['refund' => 'Stripe is not configured on this deployment (STRIPE_SECRET is not set), so nothing can be done in Stripe from here. Refund it in Stripe once it is set up.']);

    expect($order->fresh()->refunded_cents)->toBe(0)
        ->and(Refund::count())->toBe(0);
});

it('refunds an order through the gateway, audited, and applies it', function () {
    Mail::fake();
    $owner = platformAdmin();
    $order = bizPaidOrder(User::factory()->create(), 1200);

    $this->actingAs($owner)
        ->post(route('admin.billing.refund-order', $order), ['amount' => '5.00', 'reason' => 'Wrong edition'])
        ->assertSessionHas('platform_notice');

    $gateway = app(PaymentGateway::class);
    $sent = array_values($gateway->refunds)[0];
    $refund = Refund::sole();

    expect($sent)->toMatchArray(['payment_intent' => $order->stripe_payment_intent, 'amount_cents' => 500])
        ->and($refund)->toMatchArray(['status' => 'succeeded', 'gateway' => 'fake', 'amount_cents' => 500, 'actor_id' => $owner->id])
        ->and($order->fresh()->refunded_cents)->toBe(500)
        ->and($order->fresh()->status)->toBe('partially_refunded');

    $log = AdminAuditLog::where('action', 'refund.order')->sole();
    expect($log->reason)->toBe('Wrong edition')
        ->and($log->after['gateway_refund_id'])->toBe($refund->gateway_refund_id);

    // More than is left is refused.
    $this->actingAs($owner)->post(route('admin.billing.refund-order', $order), ['amount' => '8.00', 'reason' => 'Again'])->assertSessionHasErrors('amount');
});

it('records a refund the gateway refused, and says why', function () {
    $order = bizPaidOrder(User::factory()->create(), 1200);
    app(PaymentGateway::class)->failNextRefund = 'charge_already_refunded';

    $this->actingAs(platformAdmin())
        ->post(route('admin.billing.refund-order', $order), ['reason' => 'Duplicate purchase'])
        ->assertSessionHasErrors('refund');

    expect(Refund::sole()->status)->toBe('failed')
        ->and(AdminAuditLog::where('action', 'refund.order.failed')->exists())->toBeTrue()
        ->and($order->fresh()->refunded_cents)->toBe(0);
});

it('refunds a credit pack and takes back its unspent credits', function () {
    $ada = User::factory()->create();
    $purchase = CreditTransaction::create(['user_id' => $ada->id, 'amount' => 500, 'kind' => 'purchase', 'label' => 'credits_500', 'external_reference' => 'pi_pack_1']);
    CreditTransaction::create(['user_id' => $ada->id, 'amount' => -120, 'kind' => 'spend', 'label' => 'agent_run']);

    $this->actingAs(platformAdmin())->post(route('admin.billing.refund-credits', $purchase), ['reason' => 'Bought by mistake'])->assertSessionHas('platform_notice');

    expect(app(PaymentGateway::class)->refunds)->toHaveCount(1)
        ->and(array_values(app(PaymentGateway::class)->refunds)[0]['amount_cents'])->toBe(1500)
        ->and((int) CreditTransaction::where('user_id', $ada->id)->sum('amount'))->toBe(0);

    // Once.
    $this->actingAs(platformAdmin())->post(route('admin.billing.refund-credits', $purchase), ['reason' => 'Again'])->assertSessionHasErrors('refund');
});

it('keeps refunds from support', function () {
    $order = bizPaidOrder(User::factory()->create(), 1200);

    $this->actingAs(platformAdmin('support'))->post(route('admin.billing.refund-order', $order), ['reason' => 'Please'])->assertForbidden();

    expect(app(PaymentGateway::class)->refunds)->toBe([]);
});
