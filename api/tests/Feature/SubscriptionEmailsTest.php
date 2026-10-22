<?php

use App\Mail\Billing\PaymentFailedMail;
use App\Mail\Billing\PlanChangedMail;
use App\Mail\Billing\SubscriptionCancelledMail;
use App\Mail\Billing\SubscriptionReceiptMail;
use App\Mail\Billing\TrialEndingMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Laravel\Cashier\Events\WebhookHandled;

/**
 * Subscription emails from Stripe's events, after Cashier has handled them
 * (App\Billing\SubscriptionEmails). Each event id sends at most once.
 */
beforeEach(function () {
    Mail::fake();

    config([
        'cashier.webhook.secret' => 'whsec_billing_test',
        'billing.prices.author' => 'price_author',
        'billing.prices.studio' => 'price_studio',
    ]);
});

function billingEvent(string $type, array $object, string $id, array $previous = []): array
{
    $event = ['id' => $id, 'type' => $type, 'data' => ['object' => $object]];

    if ($previous !== []) {
        $event['data']['previous_attributes'] = $previous;
    }

    $body = json_encode($event);
    $time = time();

    return [$body, [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => "t={$time},v1=".hash_hmac('sha256', "{$time}.{$body}", 'whsec_billing_test'),
    ]];
}

function postBilling(array $event)
{
    [$body, $headers] = $event;

    return test()->call('POST', '/stripe/webhook', [], [], [], $headers, $body);
}

function subscriber(): User
{
    return User::factory()->create(['stripe_id' => 'cus_sub_1']);
}

it('sends a failed-payment email once, however often Stripe delivers it', function () {
    $user = subscriber();

    $event = billingEvent('invoice.payment_failed', [
        'customer' => 'cus_sub_1',
        'amount_due' => 1900,
        'currency' => 'usd',
        'next_payment_attempt' => now()->addDays(3)->getTimestamp(),
        'lines' => ['data' => [['price' => ['id' => 'price_author']]]],
    ], 'evt_fail_1');

    postBilling($event)->assertOk();
    postBilling($event)->assertOk();

    Mail::assertQueued(PaymentFailedMail::class, 1);
    Mail::assertQueued(PaymentFailedMail::class, fn (PaymentFailedMail $mail) => $mail->hasTo($user->email)
        && $mail->planName === 'Author'
        && $mail->amountCents === 1900
        && $mail->updateUrl === route('billing.show'));
});

it('sends a receipt for a paid subscription invoice, and none for a $0 one', function () {
    $user = subscriber();
    $invoice = fn (int $paid, string $reason) => [
        'customer' => 'cus_sub_1',
        'amount_paid' => $paid,
        'currency' => 'usd',
        'billing_reason' => $reason,
        'number' => 'INV-0001',
        'hosted_invoice_url' => 'https://invoice.stripe.com/i/test',
        'lines' => ['data' => [[
            'pricing' => ['price_details' => ['price' => 'price_studio']],
            'period' => ['start' => now()->getTimestamp(), 'end' => now()->addMonth()->getTimestamp()],
        ]]],
    ];

    postBilling(billingEvent('invoice.payment_succeeded', $invoice(0, 'subscription_create'), 'evt_paid_0'))->assertOk();
    Mail::assertNothingQueued();

    postBilling(billingEvent('invoice.payment_succeeded', $invoice(7900, 'subscription_create'), 'evt_paid_1'))->assertOk();

    Mail::assertQueued(SubscriptionReceiptMail::class, fn (SubscriptionReceiptMail $mail) => $mail->hasTo($user->email)
        && $mail->planName === 'Studio'
        && $mail->amountCents === 7900
        && $mail->isFirstPayment
        && $mail->monthlyCredits === 5000);
});

it('warns before a trial ends', function () {
    $user = subscriber();

    postBilling(billingEvent('customer.subscription.trial_will_end', [
        'customer' => 'cus_sub_1',
        'trial_end' => now()->addDays(3)->getTimestamp(),
        'items' => ['data' => [['price' => ['id' => 'price_author', 'unit_amount' => 1900, 'currency' => 'usd']]]],
    ], 'evt_trial_1'))->assertOk();

    Mail::assertQueued(TrialEndingMail::class, fn (TrialEndingMail $mail) => $mail->hasTo($user->email)
        && $mail->planName === 'Author'
        && $mail->priceCents === 1900
        && $mail->hasPaymentMethod === false);
});

it('tells the customer about a scheduled cancellation and a plan change', function () {
    $user = subscriber();
    $subscription = fn (string $price, bool $cancelAtEnd) => [
        'customer' => 'cus_sub_1',
        'cancel_at_period_end' => $cancelAtEnd,
        'items' => ['data' => [[
            'price' => ['id' => $price, 'unit_amount' => 7900, 'currency' => 'usd'],
            'current_period_end' => now()->addDays(20)->getTimestamp(),
        ]]],
    ];

    // Dispatched as Cashier does once it has handled the event, so the test
    // is about the emails rather than Cashier's subscription bookkeeping.
    WebhookHandled::dispatch([
        'id' => 'evt_upd_1', 'type' => 'customer.subscription.updated',
        'data' => ['object' => $subscription('price_author', true), 'previous_attributes' => ['cancel_at_period_end' => false]],
    ]);

    WebhookHandled::dispatch([
        'id' => 'evt_upd_2', 'type' => 'customer.subscription.updated',
        'data' => [
            'object' => $subscription('price_studio', false),
            'previous_attributes' => ['items' => ['data' => [['price' => ['id' => 'price_author']]]]],
        ],
    ]);
    // Delivered twice.
    WebhookHandled::dispatch([
        'id' => 'evt_upd_2', 'type' => 'customer.subscription.updated',
        'data' => [
            'object' => $subscription('price_studio', false),
            'previous_attributes' => ['items' => ['data' => [['price' => ['id' => 'price_author']]]]],
        ],
    ]);

    // The scheduled cancellation running out is not news a second time.
    WebhookHandled::dispatch([
        'id' => 'evt_del_1', 'type' => 'customer.subscription.deleted',
        'data' => ['object' => $subscription('price_author', true)],
    ]);

    Mail::assertQueued(SubscriptionCancelledMail::class, 1);
    Mail::assertQueued(SubscriptionCancelledMail::class, fn ($mail) => $mail->hasTo($user->email) && $mail->planName === 'Author');
    Mail::assertQueued(PlanChangedMail::class, 1);
    Mail::assertQueued(PlanChangedMail::class, fn (PlanChangedMail $mail) => $mail->fromPlan === 'Author'
        && $mail->toPlan === 'Studio'
        && $mail->isUpgrade
        && $mail->newPriceCents === 7900);
});

it('announces an immediate cancellation', function () {
    subscriber();

    WebhookHandled::dispatch([
        'id' => 'evt_del_2', 'type' => 'customer.subscription.deleted',
        'data' => ['object' => [
            'customer' => 'cus_sub_1',
            'cancel_at_period_end' => false,
            'ended_at' => now()->getTimestamp(),
            'items' => ['data' => [['price' => ['id' => 'price_studio']]]],
        ]],
    ]);

    Mail::assertQueued(SubscriptionCancelledMail::class, fn ($mail) => $mail->planName === 'Studio');
});

it('sends nothing for a customer it does not know', function () {
    postBilling(billingEvent('invoice.payment_failed', [
        'customer' => 'cus_nobody', 'amount_due' => 100, 'currency' => 'usd', 'lines' => ['data' => []],
    ], 'evt_fail_2'))->assertOk();

    Mail::assertNothingQueued();
});
