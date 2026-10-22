<?php

use App\Models\User;

/**
 * POST /stripe/webhook. Production had no STRIPE_WEBHOOK_SECRET, so Cashier
 * skipped signature checks and processed anything posted to it.
 */
function stripeEvent(array $event, ?string $secret = null): array
{
    $body = json_encode($event);
    $headers = ['Content-Type' => 'application/json'];

    if ($secret !== null) {
        $time = time();
        $headers['Stripe-Signature'] = "t={$time},v1=".hash_hmac('sha256', "{$time}.{$body}", $secret);
    }

    return [$body, $headers];
}

function postStripe($test, string $body, array $headers)
{
    return $test->call('POST', '/stripe/webhook', [], [], [], collect($headers)
        ->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])
        ->put('CONTENT_TYPE', 'application/json')
        ->all(), $body);
}

it('refuses unsigned events outside development when no secret is set', function () {
    config(['cashier.webhook.secret' => null]);
    app()->detectEnvironment(fn () => 'production');

    [$body, $headers] = stripeEvent(['type' => 'customer.subscription.updated', 'data' => ['object' => []]]);

    postStripe($this, $body, $headers)->assertStatus(403);
});

it('refuses an event whose signature does not match', function () {
    config(['cashier.webhook.secret' => 'whsec_test_secret']);

    [$body, $headers] = stripeEvent(['type' => 'invoice.paid', 'data' => ['object' => []]], 'whsec_other');

    postStripe($this, $body, $headers)->assertStatus(403);
});

it('accepts a correctly signed event', function () {
    config(['cashier.webhook.secret' => 'whsec_test_secret']);

    [$body, $headers] = stripeEvent(['type' => 'some.unhandled_event', 'data' => ['object' => []]], 'whsec_test_secret');

    postStripe($this, $body, $headers)->assertOk();
});

it('answers a malformed event with a 400, not a 500', function () {
    // The audit's request: Cashier indexed straight into data.object.customer.
    config(['cashier.webhook.secret' => 'whsec_test_secret']);

    [$body, $headers] = stripeEvent(['type' => 'customer.subscription.updated', 'data' => ['object' => []]], 'whsec_test_secret');

    postStripe($this, $body, $headers)->assertStatus(400);
});

it('answers a body that is not an event with a 400', function () {
    config(['cashier.webhook.secret' => null]);

    postStripe($this, 'not json', [])->assertStatus(400);
    postStripe($this, json_encode(['data' => []]), [])->assertStatus(400);
});

it('still lets Cashier handle a well-formed event', function () {
    config(['cashier.webhook.secret' => 'whsec_test_secret']);
    $user = User::factory()->create(['stripe_id' => 'cus_test_123']);

    [$body, $headers] = stripeEvent([
        'type' => 'customer.deleted',
        'data' => ['object' => ['id' => 'cus_test_123']],
    ], 'whsec_test_secret');

    postStripe($this, $body, $headers)->assertOk();

    expect($user->fresh()->stripe_id)->toBeNull();
});
