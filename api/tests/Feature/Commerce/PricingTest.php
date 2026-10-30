<?php

use App\Commerce\Gateway\FakeGateway;
use App\Commerce\Gateway\PaymentGateway;
use App\Commerce\Pricing;
use App\Models\Book;
use App\Models\DiscountCode;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Per-format prices and time-limited sales (App\Commerce\Pricing): what a
 * product costs, what checkout charges and sends to Stripe, and that a
 * product nobody re-priced sells exactly as before.
 */
beforeEach(function () {
    Storage::fake('files');
    Mail::fake();

    config([
        'commerce.enabled' => true,
        'commerce.gateway' => 'fake',
        'commerce.stripe_fee' => ['percent' => 2.9, 'fixed_cents' => 30],
        'commerce.platform_rate' => 0.0,
    ]);
});

/** A book with ready EPUB and PDF exports. */
function pricedBook(?User $owner = null, array $attributes = []): Book
{
    $owner ??= User::factory()->create();
    $book = Book::factory()->for($owner)->create($attributes);

    foreach (['epub', 'pdf'] as $format) {
        $path = "exports/{$book->id}/book.{$format}";
        Storage::disk('files')->put($path, "{$format} bytes");
        $book->exports()->create(['format' => $format, 'status' => 'ready', 'path' => $path, 'byte_size' => 10]);
    }

    return $book;
}

function priceProduct(Book $book, array $overrides = []): Product
{
    test()->actingAs($book->user)
        ->putJson("/books/{$book->id}/product", array_merge([
            'price' => '12.00', 'currency' => 'usd', 'formats' => ['epub', 'pdf'],
            'license' => 'personal', 'published' => true,
        ], $overrides))
        ->assertOk();

    auth()->logout();

    return $book->fresh()->product;
}

function fakeGateway(): FakeGateway
{
    return app(PaymentGateway::class);
}

it('keeps a product without format prices selling everything at its one price', function () {
    $product = priceProduct(pricedBook());

    expect($product->format_prices)->toBeNull()
        ->and($product->sales)->toBeNull()
        ->and(Pricing::offers($product))->toBe(['all'])
        ->and(Pricing::listPrice($product, 'epub'))->toBe(1200)
        ->and(Pricing::listPrice($product, 'pdf'))->toBe(1200);

    $this->post("/store/{$product->slug}/checkout", ['email' => 'r@example.com'])->assertRedirect();
    $order = Order::latest('id')->first();
    $item = $order->items()->first();

    expect($order->total_cents)->toBe(1200)
        ->and($item->formats)->toBe(['epub', 'pdf'])
        ->and($item->offer)->toBe('all')
        ->and($item->list_price_cents)->toBe(1200);

    $session = collect(fakeGateway()->sessions)->last();
    expect($session['line_items'][0]['amount_cents'])->toBe(1200)
        ->and($session['line_items'][0]['description'])->toStartWith('EPUB + PDF');
});

it('prices each format on its own and sells one format at its price', function () {
    $product = priceProduct(pricedBook(), ['format_prices' => ['epub' => '4.99', 'pdf' => '']]);

    expect($product->format_prices)->toBe(['epub' => 499])
        ->and(Pricing::offers($product))->toBe(['epub', 'pdf', 'all'])
        // A format without its own price takes the product's.
        ->and(Pricing::listPrice($product, 'pdf'))->toBe(1200);

    $this->post("/store/{$product->slug}/checkout", ['email' => 'r@example.com', 'offer' => 'epub'])->assertRedirect();
    $order = Order::latest('id')->first();

    expect($order->total_cents)->toBe(499)
        ->and($order->items()->first()->formats)->toBe(['epub']);
    expect(collect(fakeGateway()->sessions)->last()['line_items'][0])
        ->amount_cents->toBe(499)
        ->description->toStartWith('EPUB ·');

    // A format the product does not sell is refused.
    $this->post("/store/{$product->slug}/checkout", ['email' => 'r@example.com', 'offer' => 'docx'])
        ->assertSessionHasErrors('checkout');
});

it('refuses a format price below what card payments take', function () {
    $book = pricedBook();

    $this->actingAs($book->user)->putJson("/books/{$book->id}/product", [
        'price' => '12', 'currency' => 'usd', 'formats' => ['epub', 'pdf'], 'license' => 'personal', 'published' => true,
        'format_prices' => ['epub' => '0.10'],
    ])->assertUnprocessable()->assertJsonValidationErrors('format_prices.epub');
});

it('runs a sale only inside its window, entered in the seller zone', function () {
    $seller = User::factory()->create(['timezone' => 'America/Port-au-Prince']);
    Carbon::setTestNow('2026-11-10 12:00:00');

    $product = priceProduct(pricedBook($seller), ['sales' => [
        ['offer' => 'all', 'price' => '8.00', 'starts_at' => '2026-11-12T00:00', 'ends_at' => '2026-11-15T00:00'],
    ]]);

    // Midnight in Port-au-Prince (UTC−5 in November) is 05:00 UTC.
    expect($product->sales['all']['starts_at'])->toBe('2026-11-12T05:00:00+00:00');

    expect(Pricing::quote($product, 'all')['price_cents'])->toBe(1200);
    expect(Pricing::quote($product, 'all', Carbon::parse('2026-11-12 04:59', 'UTC'))['price_cents'])->toBe(1200);
    expect(Pricing::quote($product, 'all', Carbon::parse('2026-11-12 05:00', 'UTC')))
        ->price_cents->toBe(800)
        ->on_sale->toBeTrue()
        ->list_cents->toBe(1200);
    expect(Pricing::quote($product, 'all', Carbon::parse('2026-11-15 05:00', 'UTC'))['price_cents'])->toBe(1200);

    // Checkout charges the price at the moment it starts.
    Carbon::setTestNow('2026-11-13 12:00:00');
    $this->post("/store/{$product->slug}/checkout", ['email' => 'r@example.com'])->assertRedirect();
    $order = Order::latest('id')->first();
    expect($order->total_cents)->toBe(800)
        ->and($order->items()->first()->list_price_cents)->toBe(1200)
        ->and(collect(fakeGateway()->sessions)->last()['line_items'][0]['amount_cents'])->toBe(800);

    // The product page shows the struck-through list price and the end.
    $this->get("/store/{$product->slug}")->assertInertia(fn (Assert $page) => $page
        ->where('product.offers.0.price_cents', 800)
        ->where('product.offers.0.list_cents', 1200)
        ->where('product.offers.0.sale_ends_at', '2026-11-15T05:00:00+00:00')
        ->where('product.price_cents', 800)
        ->where('product.list_cents', 1200));

    // The form gives the dates back in the seller's zone.
    $this->actingAs($seller)->getJson("/books/{$product->book_id}/product")
        ->assertJsonPath('product.sales.0.starts_at', '2026-11-12T00:00')
        ->assertJsonPath('timezone', 'America/Port-au-Prince');

    Carbon::setTestNow();
});

it('applies a discount code to the sale price', function () {
    Carbon::setTestNow('2026-11-13 12:00:00');
    $product = priceProduct(pricedBook(), ['sales' => [
        ['offer' => 'all', 'price' => '10.00', 'starts_at' => '2026-11-01T00:00', 'ends_at' => '2026-12-01T00:00'],
    ]]);
    DiscountCode::create(['code' => 'HALF', 'kind' => 'percent', 'percent_off' => 50, 'applies_to_storefront' => true, 'active' => true]);

    $this->post("/store/{$product->slug}/checkout", ['email' => 'r@example.com', 'code' => 'half'])->assertRedirect();
    $order = Order::latest('id')->first();

    expect($order->total_cents)->toBe(500)->and($order->discount_cents)->toBe(500);
    Carbon::setTestNow();
});

it('refuses sales that are not below the price, run backwards or name another format', function () {
    $book = pricedBook();
    $base = ['price' => '12', 'currency' => 'usd', 'formats' => ['epub', 'pdf'], 'license' => 'personal', 'published' => true];

    $this->actingAs($book->user)->putJson("/books/{$book->id}/product", $base + ['sales' => [
        ['offer' => 'all', 'price' => '12.00', 'starts_at' => '2026-11-12T00:00', 'ends_at' => '2026-11-15T00:00'],
        ['offer' => 'epub', 'price' => '5.00', 'starts_at' => '2026-11-12T00:00', 'ends_at' => '2026-11-15T00:00'],
    ]])->assertUnprocessable()->assertJsonValidationErrors(['sales.0.price', 'sales.1.offer']);

    $this->putJson("/books/{$book->id}/product", $base + ['sales' => [
        ['offer' => 'all', 'price' => '9.00', 'starts_at' => '2026-11-15T00:00', 'ends_at' => '2026-11-12T00:00'],
    ]])->assertUnprocessable()->assertJsonValidationErrors('sales.0.ends_at');
});

it('keeps stored prices and sales when an older form leaves them out', function () {
    $book = pricedBook();
    priceProduct($book, ['format_prices' => ['epub' => '5.00'], 'sales' => [
        ['offer' => 'epub', 'price' => '3.00', 'starts_at' => '2026-11-12T00:00', 'ends_at' => '2026-11-15T00:00'],
    ]]);

    $product = priceProduct($book, ['description' => 'Changed']);

    expect($product->format_prices)->toBe(['epub' => 500])
        ->and($product->sales)->toHaveKey('epub');
});
