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
 * Sessions are remembered for the life of the process, so a test can assert
 * what would have been sent to Stripe.
 */
class FakeGateway implements PaymentGateway
{
    /** @var array<string, array<string, mixed>> */
    public array $sessions = [];

    public function createCheckoutSession(array $params): CheckoutSession
    {
        $id = 'cs_fake_'.Str::random(24);
        $this->sessions[$id] = $params;

        return new CheckoutSession($id, route('store.checkout.fake', ['session' => $id]));
    }

    public function name(): string
    {
        return 'fake';
    }
}
