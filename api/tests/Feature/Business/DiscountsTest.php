<?php

use App\Commerce\Fulfilment;
use App\Commerce\Gateway\PaymentGateway;
use App\Growth\Discounts;
use App\Models\AdminAuditLog;
use App\Models\Book;
use App\Models\DiscountCode;
use App\Models\DiscountRedemption;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/*
 * Discount codes at the storefront's checkout, through the fake gateway:
 * the price is lowered before the session, limits and expiry hold, a
 * first-purchase code refuses a returning buyer, and the redemption is
 * recorded once when the order is paid.
 */

beforeEach(function () {
    Mail::fake();
    config(['commerce.enabled' => true, 'commerce.gateway' => 'fake']);
});

function bizProduct(int $priceCents = 1200, string $currency = 'usd'): Product
{
    $book = Book::factory()->for(User::factory()->create())->create();
    $product = new Product;
    $product->forceFill([
        'user_id' => $book->user_id,
        'book_id' => $book->id,
        'slug' => 'book-'.$book->id,
        'price_cents' => $priceCents,
        'currency' => $currency,
        'formats' => ['epub'],
        'license' => 'personal',
        'published_at' => now(),
    ])->save();

    return $product;
}

function bizCode(array $attributes = []): DiscountCode
{
    return DiscountCode::create($attributes + [
        'code' => 'RENTREE',
        'kind' => 'percent',
        'percent_off' => 25,
        'applies_to_storefront' => true,
        'active' => true,
    ]);
}

/** Pay the pending order the way Stripe's webhook would. */
function bizPay(Order $order): void
{
    app(Fulfilment::class)->checkoutCompleted([
        'id' => $order->stripe_session_id,
        'payment_status' => 'paid',
        'amount_total' => $order->total_cents,
        'currency' => $order->currency,
        'payment_intent' => 'pi_'.$order->id,
        'customer_details' => ['email' => $order->buyer_email],
        'metadata' => ['order_id' => (string) $order->id],
    ]);
}

it('takes the code off the price before checkout, and records it once when paid', function () {
    $product = bizProduct(1200);
    bizCode();

    $this->post(route('store.checkout', $product->slug), ['email' => 'reader@example.com', 'code' => 'rentree'])->assertRedirect();

    $order = Order::sole();
    $session = array_values(app(PaymentGateway::class)->sessions)[0];

    expect($order->total_cents)->toBe(900)
        ->and($order->discount_cents)->toBe(300)
        ->and($order->items()->first()->price_cents)->toBe(900)
        ->and($session['line_items'][0]['amount_cents'])->toBe(900);

    bizPay($order);
    bizPay($order->fresh()); // a repeated webhook

    expect(DiscountRedemption::count())->toBe(1)
        ->and(DiscountRedemption::sole())->toMatchArray(['amount_off_cents' => 300, 'context' => 'storefront', 'email' => 'reader@example.com'])
        // The author's royalty is on the price paid.
        ->and($order->fresh()->items()->first()->royalty_cents)->toBeLessThan(900);
});

it('shows a refused code under the field and creates no order', function (array $code, string $message) {
    $product = bizProduct(1200);
    bizCode($code);

    $this->post(route('store.checkout', $product->slug), ['email' => 'reader@example.com', 'code' => 'RENTREE'])
        ->assertSessionHasErrors(['code' => $message]);

    expect(Order::count())->toBe(0);
})->with([
    'expired' => [['expires_at' => now()->subDay()], 'That code has expired.'],
    'switched off' => [['active' => false], 'That code is not valid.'],
    'plans only' => [['applies_to_storefront' => false, 'applies_to_plans' => true], 'That code cannot be used on books.'],
    'another currency' => [['kind' => 'amount', 'percent_off' => null, 'amount_off_cents' => 200, 'currency' => 'eur'], 'That code is for prices in EUR.'],
    'too generous' => [['percent_off' => 99], 'With that code the price is below what a card payment can take.'],
]);

it('stops at its limit, counting checkouts still open', function () {
    $product = bizProduct(1200);
    bizCode(['max_redemptions' => 1]);

    $this->post(route('store.checkout', $product->slug), ['email' => 'one@example.com', 'code' => 'RENTREE'])->assertRedirect();
    $this->post(route('store.checkout', $product->slug), ['email' => 'two@example.com', 'code' => 'RENTREE'])
        ->assertSessionHasErrors(['code' => 'That code has been used up.']);

    expect(Order::count())->toBe(1);
});

it('refuses a first-purchase code to a returning buyer', function () {
    $product = bizProduct(1200);
    bizCode(['first_time_only' => true]);
    bizPaidOrder(User::factory()->create(), 500, 'usd', ['buyer_email' => 'Reader@Example.com']);

    expect(fn () => app(Discounts::class)->quote('RENTREE', 'storefront', 1200, 'usd', null, 'reader@example.com'))
        ->toThrow(ValidationException::class, 'first purchase only');

    expect(app(Discounts::class)->quote('RENTREE', 'storefront', 1200, 'usd', null, 'new@example.com')['total_cents'])->toBe(900);
});

it('works out amounts off and plan codes', function () {
    bizCode(['code' => 'FIVE', 'kind' => 'amount', 'percent_off' => null, 'amount_off_cents' => 500, 'currency' => 'usd']);
    bizCode(['code' => 'STUDIO20', 'percent_off' => 20, 'applies_to_storefront' => false, 'applies_to_plans' => true, 'plan_keys' => ['studio']]);
    $discounts = app(Discounts::class);

    expect($discounts->quote('five', 'storefront', 1200, 'USD'))->toMatchArray(['discount_cents' => 500, 'total_cents' => 700])
        ->and($discounts->quote('STUDIO20', 'plan', 7900, 'usd', null, null, 'studio')['total_cents'])->toBe(6320)
        ->and(fn () => $discounts->quote('STUDIO20', 'plan', 1900, 'usd', null, null, 'author'))->toThrow(ValidationException::class);
});

it('creates codes as the owner, with a Stripe coupon for plan codes, audited', function () {
    $owner = platformAdmin();

    $this->actingAs($owner)->post(route('admin.growth.discounts.store'), [
        'code' => 'launch-50', 'kind' => 'percent', 'percent_off' => 50, 'applies_to_plans' => true, 'applies_to_storefront' => false,
        'max_redemptions' => 100, 'expires_at' => now()->addMonth()->toDateString(), 'reason' => 'Launch week',
    ])->assertSessionHas('platform_notice');

    $code = DiscountCode::sole();
    expect($code->code)->toBe('LAUNCH-50')
        ->and($code->stripe_coupon_id)->toStartWith('coupon_fake_')
        ->and(app(PaymentGateway::class)->coupons)->toHaveCount(1)
        ->and(AdminAuditLog::where('action', 'discount.created')->sole()->reason)->toBe('Launch week');

    // The same code twice, case aside, is refused.
    $this->actingAs($owner)->post(route('admin.growth.discounts.store'), ['code' => 'LAUNCH-50', 'kind' => 'percent', 'percent_off' => 10, 'applies_to_storefront' => true, 'reason' => 'Duplicate'])->assertSessionHasErrors('code');

    // Switched off: refused at checkout.
    $this->actingAs($owner)->patch(route('admin.growth.discounts.update', $code), ['active' => false, 'reason' => 'Leaked on a forum'])->assertRedirect();
    expect($code->fresh()->active)->toBeFalse();

    $this->actingAs(platformAdmin('support'))->post(route('admin.growth.discounts.store'), ['code' => 'NOPE', 'kind' => 'percent', 'percent_off' => 10, 'applies_to_storefront' => true, 'reason' => 'Try'])->assertForbidden();
});
