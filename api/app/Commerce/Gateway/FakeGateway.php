<?php

namespace App\Commerce\Gateway;

use Illuminate\Support\Str;

/**
 * A payment provider that never leaves the machine.
 *
 * Used by the test suite and by local development, where the Stripe keys
 * are empty. A session it creates points at the local "test checkout" page
 * (StoreController::fakeCheckout), which completes the order through the
 * same fulfilment code a real webhook runs — so the whole flow can be
 * walked through in a browser without a card or a network call.
 *
 * Everything it is asked for is remembered for the life of the process, so
 * a test can assert what would have been sent to Stripe.
 */
class FakeGateway implements PaymentGateway
{
    /** @var array<string, array<string, mixed>> */
    public array $sessions = [];

    /** @var array<string, array<string, mixed>> */
    public array $refunds = [];

    /** @var array<string, array<string, mixed>> */
    public array $prices = [];

    /** @var array<string, array<string, mixed>> */
    public array $coupons = [];

    /** Set to make the next refund fail, as a declined refund would. */
    public ?string $failNextRefund = null;

    public function createCheckoutSession(array $params): CheckoutSession
    {
        $id = 'cs_fake_'.Str::random(24);
        $this->sessions[$id] = $params;

        return new CheckoutSession($id, route('store.checkout.fake', ['session' => $id]));
    }

    public function refund(array $params): array
    {
        if ($this->failNextRefund !== null) {
            $message = $this->failNextRefund;
            $this->failNextRefund = null;

            throw new \RuntimeException($message);
        }

        $id = 're_fake_'.Str::random(20);
        $this->refunds[$id] = $params;

        return ['id' => $id, 'status' => 'succeeded'];
    }

    public function createRecurringPrice(array $params): string
    {
        $id = 'price_fake_'.Str::random(16);
        $this->prices[$id] = $params;

        return $id;
    }

    public function createCoupon(array $params): string
    {
        $id = 'coupon_fake_'.Str::random(12);
        $this->coupons[$id] = $params;

        return $id;
    }

    public function name(): string
    {
        return 'fake';
    }
}
