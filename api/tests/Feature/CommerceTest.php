<?php

use App\Commerce\Commerce;
use App\Commerce\Downloads;
use App\Commerce\Gateway\FakeGateway;
use App\Commerce\Gateway\PaymentGateway;
use App\Jobs\GenerateExport;
use App\Mail\Commerce\NewSaleMail;
use App\Mail\Commerce\OrderReceiptMail;
use App\Mail\Commerce\RefundProcessedMail;
use App\Mail\Commerce\RoyaltyStatementMail;
use App\Models\Book;
use App\Models\License;
use App\Models\ManuscriptSection;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The storefront end to end, against the fake payment gateway: an author
 * puts a book on sale, a reader buys it, Stripe's signed webhook fulfils the
 * order, the reader downloads, a refund revokes, and the author sees it all
 * in their sales — and nobody sees anybody else's.
 */
beforeEach(function () {
    Storage::fake('files');
    Mail::fake();

    config([
        'commerce.enabled' => true,
        'commerce.gateway' => 'fake',
        'commerce.stripe_fee' => ['percent' => 2.9, 'fixed_cents' => 30],
        'commerce.platform_rate' => 0.0,
        'cashier.webhook.secret' => 'whsec_commerce_test',
    ]);
});

/** A book with ready EPUB and PDF exports whose files exist. */
function sellableBook(?User $owner = null, array $attributes = []): Book
{
    $owner ??= User::factory()->create();
    $book = Book::factory()->for($owner)->create($attributes);

    foreach (['epub' => 'epub', 'pdf' => 'pdf'] as $format => $ext) {
        $path = "exports/{$book->id}/book-{$format}.{$ext}";
        Storage::disk('files')->put($path, "{$format} bytes of {$book->title}");
        $book->exports()->create(['format' => $format, 'status' => 'ready', 'path' => $path, 'byte_size' => 10]);
    }

    return $book;
}

function putOnSale(Book $book, array $overrides = []): Product
{
    test()->actingAs($book->user)
        ->putJson("/books/{$book->id}/product", array_merge([
            'price' => '12.00',
            'currency' => 'usd',
            'formats' => ['epub', 'pdf'],
            'license' => 'personal',
            'title' => null,
            'description' => 'A plain description.',
            'published' => true,
        ], $overrides))
        ->assertOk();

    auth()->logout();

    return $book->fresh()->product;
}

/** A signed Stripe event, as Stripe would post it. */
function signedEvent(string $type, array $object, ?string $id = null, ?string $secret = 'whsec_commerce_test', array $extra = []): array
{
    $body = json_encode(array_merge([
        'id' => $id ?? 'evt_'.Str::random(14),
        'type' => $type,
        'data' => ['object' => $object],
    ], $extra));

    $headers = ['CONTENT_TYPE' => 'application/json'];

    if ($secret !== null) {
        $time = time();
        $headers['HTTP_STRIPE_SIGNATURE'] = "t={$time},v1=".hash_hmac('sha256', "{$time}.{$body}", $secret);
    }

    return [$body, $headers];
}

function deliver(array $event)
{
    [$body, $headers] = $event;

    return test()->call('POST', '/stripe/webhook', [], [], [], $headers, $body);
}

/** Start a checkout as a guest; returns the pending order. */
function startCheckout(Product $product, ?string $email = 'reader@example.com'): Order
{
    test()->post("/store/{$product->slug}/checkout", ['email' => $email])->assertRedirect();

    return Order::latest('id')->firstOrFail();
}

function completedSession(Order $order, array $overrides = []): array
{
    return array_merge([
        'id' => $order->stripe_session_id,
        'object' => 'checkout.session',
        'payment_status' => 'paid',
        'amount_total' => $order->total_cents,
        'currency' => $order->currency,
        'payment_intent' => 'pi_'.$order->id,
        'customer_details' => ['email' => 'reader@example.com', 'name' => 'Jonas Pierre', 'address' => ['country' => 'CA']],
        'metadata' => ['order_id' => (string) $order->id],
    ], $overrides);
}

function paidOrder(Product $product, string $email = 'reader@example.com'): Order
{
    $order = startCheckout($product, $email);
    deliver(signedEvent('checkout.session.completed', completedSession($order, [
        'customer_details' => ['email' => $email, 'name' => 'Jonas Pierre', 'address' => ['country' => 'CA']],
    ])))->assertOk();

    return $order->fresh();
}

// ── Products ────────────────────────────────────────────────────────────

it('lets the owner save a draft and put a book on sale', function () {
    $book = sellableBook();

    $this->actingAs($book->user)
        ->putJson("/books/{$book->id}/product", [
            'price' => '9.99', 'currency' => 'eur', 'formats' => ['epub'], 'license' => 'household', 'published' => false,
        ])
        ->assertOk()
        ->assertJsonPath('product.published', false)
        ->assertJsonPath('product.price_cents', 999);

    $this->putJson("/books/{$book->id}/product", [
        'price' => '9.99', 'currency' => 'eur', 'formats' => ['epub'], 'license' => 'household', 'published' => true,
    ])
        ->assertOk()
        ->assertJsonPath('product.published', true)
        ->assertJsonPath('product.url', fn ($url) => str_contains($url, '/store/'));

    $product = $book->fresh()->product;
    expect($product->user_id)->toBe($book->user_id)
        ->and($product->currency)->toBe('eur')
        ->and($product->formats)->toBe(['epub']);
});

it('refuses to put a book on sale without an export of each format', function () {
    $book = Book::factory()->create();

    $this->actingAs($book->user)
        ->putJson("/books/{$book->id}/product", [
            'price' => '5', 'currency' => 'usd', 'formats' => ['epub'], 'license' => 'personal', 'published' => true,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('formats');

    expect($book->fresh()->product)->toBeNull();
});

it('refuses a price below what card payments allow', function () {
    $book = sellableBook();

    $this->actingAs($book->user)
        ->putJson("/books/{$book->id}/product", [
            'price' => '0.20', 'currency' => 'usd', 'formats' => ['epub'], 'license' => 'personal', 'published' => true,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('price');
});

it('lets only the owner manage the product', function () {
    $book = sellableBook();
    $editor = User::factory()->create();
    $stranger = User::factory()->create();
    $book->members()->create(['user_id' => $editor->id, 'email' => $editor->email, 'role' => 'editor', 'status' => 'active']);

    $payload = ['price' => '5', 'currency' => 'usd', 'formats' => ['epub'], 'license' => 'personal', 'published' => true];

    $this->actingAs($editor)->putJson("/books/{$book->id}/product", $payload)->assertForbidden();
    $this->actingAs($stranger)->putJson("/books/{$book->id}/product", $payload)->assertForbidden();
    $this->actingAs($stranger)->getJson("/books/{$book->id}/product")->assertForbidden();

    expect($book->fresh()->product)->toBeNull();
});

it('strips markup from a description', function () {
    $book = sellableBook();

    $product = putOnSale($book, ['description' => '<script>alert(1)</script>Read <b>this</b>']);

    expect($product->description)->toBe('alert(1)Read this');
});

// ── Feature flag ────────────────────────────────────────────────────────

it('is off without Stripe keys unless switched on, and never fake in production', function () {
    config(['commerce.enabled' => false]);
    expect(Commerce::enabled())->toBeFalse();

    config(['commerce.enabled' => true, 'commerce.gateway' => 'fake']);
    expect(Commerce::enabled())->toBeTrue();

    app()->detectEnvironment(fn () => 'production');
    expect(Commerce::enabled())->toBeFalse()
        ->and(Commerce::fakeCheckoutAllowed())->toBeFalse();

    config(['commerce.gateway' => 'stripe']);
    expect(Commerce::enabled())->toBeTrue()
        ->and(Commerce::fakeCheckoutAllowed())->toBeFalse();
});

it('hides the storefront while the feature is off', function () {
    $book = sellableBook();
    $product = putOnSale($book);

    config(['commerce.enabled' => false]);

    $this->get("/store/{$product->slug}")->assertNotFound();
    $this->post("/store/{$product->slug}/checkout")->assertNotFound();
    $this->actingAs($book->user)
        ->putJson("/books/{$book->id}/product", ['price' => '5', 'currency' => 'usd', 'formats' => ['epub'], 'license' => 'personal', 'published' => true])
        ->assertNotFound();
});

// ── Storefront ──────────────────────────────────────────────────────────

it('shows a book on sale to a signed-out visitor', function () {
    $book = sellableBook(null, ['title' => 'The Long Walk', 'author_name' => 'Amélie Laurent']);
    $product = putOnSale($book);

    $this->get("/store/{$product->slug}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Store/Product')
            ->where('product.title', 'The Long Walk')
            ->where('product.author', 'Amélie Laurent')
            ->where('product.price_cents', 1200)
            ->where('product.formats', ['EPUB', 'PDF'])
            ->where('product.description', 'A plain description.')
            ->where('product.author_url', fn ($url) => str_contains($url, '/store/authors/')));
});

it('never names the author of a book published without a name', function () {
    $owner = User::factory()->create(['name' => 'Secret Person', 'author_name' => 'Pen Name']);
    $book = sellableBook($owner, ['author_name' => null, 'series_name' => 'Écrivain Prépa', 'series_number' => 2]);
    $product = putOnSale($book);

    $response = $this->get("/store/{$product->slug}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('product.author', null)
            ->where('product.author_url', null)
            ->where('product.series_name', 'Écrivain Prépa'));

    expect($response->getContent())
        ->not->toContain('Secret Person')
        ->not->toContain('Pen Name')
        ->not->toContain($owner->email);
});

it('lists named books on the author shelf and leaves anonymous ones off', function () {
    $owner = User::factory()->create(['name' => 'Account Holder']);
    $named = putOnSale(sellableBook($owner, ['title' => 'Named Book', 'author_name' => 'Amélie Laurent']));
    putOnSale(sellableBook($owner, ['title' => 'Anonymous Book', 'author_name' => null]));

    $this->get('/store/authors/'.$owner->fresh()->store_handle)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Store/Author')
            ->where('names', ['Amélie Laurent'])
            ->has('products', 1)
            ->where('products.0.slug', $named->slug));
});

it('answers 404 for an unpublished product or an empty shelf', function () {
    $owner = User::factory()->create();
    $product = putOnSale(sellableBook($owner, ['author_name' => null]));
    $draft = putOnSale(sellableBook($owner), ['published' => false]);

    $this->get("/store/{$draft->slug}")->assertNotFound();
    $this->get("/store/{$draft->slug}/cover")->assertNotFound();
    // Only an anonymous book on sale: no shelf to show.
    $this->get('/store/authors/'.$owner->fresh()->storeHandle())->assertNotFound();
    $this->get('/store/authors/nope')->assertNotFound();
    $this->get("/store/{$product->slug}")->assertOk();
});

// ── Checkout ────────────────────────────────────────────────────────────

it('creates a pending order and sends the buyer to the hosted checkout', function () {
    $product = putOnSale(sellableBook(null, ['title' => 'The Long Walk']));

    $response = $this->post("/store/{$product->slug}/checkout", [
        'email' => 'reader@example.com',
        // Ignored: the price is the product's.
        'price' => 1,
    ]);

    $order = Order::firstOrFail();
    $gateway = app(PaymentGateway::class);

    expect($gateway)->toBeInstanceOf(FakeGateway::class);
    $response->assertRedirect(route('store.checkout.fake', ['session' => $order->stripe_session_id]));

    $sent = $gateway->sessions[$order->stripe_session_id];
    expect($order->status)->toBe('pending')
        ->and($order->total_cents)->toBe(1200)
        ->and($order->items()->first()->price_cents)->toBe(1200)
        ->and($sent['line_items'][0]['amount_cents'])->toBe(1200)
        ->and($sent['currency'])->toBe('usd')
        ->and($sent['customer_email'])->toBe('reader@example.com')
        ->and($sent['success_url'])->toContain('session_id={CHECKOUT_SESSION_ID}');

    // Nothing is sold, licensed or emailed until Stripe says so.
    expect(License::count())->toBe(0);
    Mail::assertNothingQueued();
});

it('answers an Inertia visit with a location to the checkout page', function () {
    $product = putOnSale(sellableBook());

    $this->withHeaders(['X-Inertia' => 'true'])
        ->post("/store/{$product->slug}/checkout")
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location');
});

// ── Webhook fulfilment ──────────────────────────────────────────────────

it('fulfils a paid checkout from a signed webhook: license, revenue and emails', function () {
    $owner = User::factory()->create();
    $book = sellableBook($owner, ['title' => 'The Long Walk', 'author_name' => 'Amélie Laurent']);
    $product = putOnSale($book);

    $order = startCheckout($product);
    deliver(signedEvent('checkout.session.completed', completedSession($order)))->assertOk();

    $order->refresh();
    $item = $order->items()->first();

    expect($order->status)->toBe('paid')
        ->and($order->buyer_email)->toBe('reader@example.com')
        ->and($order->buyer_country)->toBe('CA')
        ->and($order->stripe_payment_intent)->toBe('pi_'.$order->id)
        // 2.9% of 1200 rounds to 35, plus 30.
        ->and($order->fee_cents)->toBe(65)
        ->and($item->fee_cents)->toBe(65)
        ->and($item->royalty_cents)->toBe(1135)
        ->and($item->license)->not->toBeNull()
        ->and($item->license->status)->toBe('active');

    Mail::assertQueued(OrderReceiptMail::class, function (OrderReceiptMail $mail) use ($order) {
        $urls = collect($mail->items[0]['downloads'])->pluck('url');

        return $mail->hasTo('reader@example.com')
            && $mail->orderNumber === $order->number
            && $mail->sellerName === 'Amélie Laurent'
            && $mail->licenseKey !== null
            && $urls->count() === 2
            && $urls->every(fn ($url) => str_contains($url, 'signature=') && str_contains($url, 'expires='));
    });
    Mail::assertQueued(NewSaleMail::class, fn (NewSaleMail $mail) => $mail->hasTo($owner->email)
        && $mail->amountCents === 1200
        && $mail->royaltyCents === 1135
        && $mail->buyerCountry === 'Canada');
});

it('does nothing twice when Stripe delivers the same event again', function () {
    $product = putOnSale(sellableBook());
    $order = startCheckout($product);
    $event = signedEvent('checkout.session.completed', completedSession($order), 'evt_same');

    deliver($event)->assertOk();
    deliver($event)->assertOk();
    // A different event for the same session (Stripe can send both
    // completed and async_payment_succeeded): still one fulfilment.
    deliver(signedEvent('checkout.session.async_payment_succeeded', completedSession($order)))->assertOk();

    expect(License::count())->toBe(1);
    Mail::assertQueued(OrderReceiptMail::class, 1);
    Mail::assertQueued(NewSaleMail::class, 1);
});

it('refuses unsigned or wrongly signed checkout events', function () {
    $product = putOnSale(sellableBook());
    $order = startCheckout($product);

    deliver(signedEvent('checkout.session.completed', completedSession($order), secret: null))->assertForbidden();
    deliver(signedEvent('checkout.session.completed', completedSession($order), secret: 'whsec_forged'))->assertForbidden();

    expect($order->fresh()->status)->toBe('pending');
    Mail::assertNothingQueued();
});

it('does not fulfil when the amount paid is not the order total', function () {
    $product = putOnSale(sellableBook());
    $order = startCheckout($product);

    deliver(signedEvent('checkout.session.completed', completedSession($order, ['amount_total' => 1])))->assertOk();

    expect($order->fresh()->status)->toBe('pending')
        ->and(License::count())->toBe(0);
});

it('waits for a delayed payment method to actually pay', function () {
    $product = putOnSale(sellableBook());
    $order = startCheckout($product);

    deliver(signedEvent('checkout.session.completed', completedSession($order, ['payment_status' => 'unpaid'])))->assertOk();
    expect($order->fresh()->status)->toBe('pending');

    deliver(signedEvent('checkout.session.async_payment_succeeded', completedSession($order)))->assertOk();
    expect($order->fresh()->status)->toBe('paid');
});

it('closes an order whose checkout expired', function () {
    $product = putOnSale(sellableBook());
    $order = startCheckout($product);

    deliver(signedEvent('checkout.session.expired', ['id' => $order->stripe_session_id, 'metadata' => ['order_id' => (string) $order->id]]))->assertOk();

    expect($order->fresh()->status)->toBe('cancelled');
});

it('does not let an unknown session name somebody else\'s order', function () {
    $product = putOnSale(sellableBook());
    $order = startCheckout($product);

    deliver(signedEvent('checkout.session.completed', completedSession($order, ['id' => 'cs_unknown'])))->assertOk();

    expect($order->fresh()->status)->toBe('pending');
});

// ── Downloads ───────────────────────────────────────────────────────────

it('serves a purchased file through a signed, expiring link', function () {
    $book = sellableBook(null, ['title' => 'The Long Walk']);
    $order = paidOrder(putOnSale($book));
    $license = $order->items()->first()->license;

    $url = Downloads::link($license, 'epub');
    $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

    $response = $this->get($path)->assertOk();
    expect($response->streamedContent())->toContain('epub bytes of The Long Walk')
        ->and($response->headers->get('content-disposition'))->toContain('the-long-walk.epub')
        ->and($license->fresh()->download_count)->toBe(1);

    // Tampered: another license key under the same signature.
    $this->get(str_replace($license->key, 'LIC-AAAA-BBBB-CCCC-DDDD', $path))->assertForbidden();
    // Unsigned.
    $this->get("/store/downloads/{$license->key}/epub")->assertForbidden();

    // Expired.
    $this->travel(config('commerce.download_link_hours') + 1)->hours();
    $this->get($path)->assertForbidden();
});

it('refuses a format the license does not cover', function () {
    $book = sellableBook();
    $order = paidOrder(putOnSale($book, ['formats' => ['epub']]));
    $license = $order->items()->first()->license;

    $url = Downloads::link($license, 'pdf');
    $this->get(parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY))->assertNotFound();
});

it('queues a rebuild when the export file has gone', function () {
    Queue::fake();
    $book = sellableBook();
    ManuscriptSection::factory()->for($book)->create([
        'title' => 'One', 'type' => 'chapter', 'position' => 0,
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Words.']]]]],
    ]);
    $order = paidOrder(putOnSale($book));
    Storage::disk('files')->deleteDirectory("exports/{$book->id}");

    $url = Downloads::link($order->items()->first()->license, 'pdf');
    $this->get(parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY))
        ->assertStatus(503)
        ->assertSee('being prepared');

    Queue::assertPushed(GenerateExport::class);
});

// ── Refunds ─────────────────────────────────────────────────────────────

it('records a partial then a full refund, once each, and revokes the license', function () {
    $book = sellableBook();
    $order = paidOrder(putOnSale($book));
    $license = $order->items()->first()->license;
    $url = Downloads::link($license, 'epub');
    $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

    $charge = fn (int $refunded) => [
        'id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_'.$order->id,
        'amount' => 1200, 'amount_refunded' => $refunded, 'refunded' => $refunded >= 1200,
        'metadata' => ['order_id' => (string) $order->id],
    ];

    $partial = signedEvent('charge.refunded', $charge(400), 'evt_refund_1');
    deliver($partial)->assertOk();
    deliver($partial)->assertOk();

    expect($order->fresh()->status)->toBe('partially_refunded')
        ->and($order->fresh()->refunded_cents)->toBe(400)
        ->and($license->fresh()->status)->toBe('active');
    $this->get($path)->assertOk();

    deliver(signedEvent('charge.refunded', $charge(1200), 'evt_refund_2'))->assertOk();
    // The same cumulative figure again, under a new event id: nothing new.
    deliver(signedEvent('charge.refunded', $charge(1200), 'evt_refund_3'))->assertOk();

    expect($order->fresh()->status)->toBe('refunded')
        ->and($order->fresh()->refunded_cents)->toBe(1200)
        ->and($license->fresh()->status)->toBe('revoked');
    $this->get($path)->assertForbidden();

    Mail::assertQueued(RefundProcessedMail::class, 2);
    Mail::assertQueued(RefundProcessedMail::class, fn (RefundProcessedMail $m) => $m->amountCents === 400);
    Mail::assertQueued(RefundProcessedMail::class, fn (RefundProcessedMail $m) => $m->amountCents === 800);
});

// ── Success page and purchases ──────────────────────────────────────────

it('shows the buyer their downloads on the success page, and nobody else', function () {
    $product = putOnSale(sellableBook());

    $this->post("/store/{$product->slug}/checkout", ['email' => 'reader@example.com']);
    $order = Order::firstOrFail();
    deliver(signedEvent('checkout.session.completed', completedSession($order)))->assertOk();

    // Same browser session that started the checkout.
    $this->get('/store/checkout/success?session_id='.$order->stripe_session_id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Store/Checkout')
            ->where('order.status', 'paid')
            ->has('order.items.0.downloads', 2));

    // A different visitor with the same URL sees the status only.
    $this->flushSession();
    $this->get('/store/checkout/success?session_id='.$order->stripe_session_id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('order.email', null)
            ->where('order.items.0.license_key', null)
            ->has('order.items.0.downloads', 0));
});

it('lists a signed-in buyer\'s purchases and no one else\'s', function () {
    $buyer = User::factory()->create();
    $other = User::factory()->create();
    $product = putOnSale(sellableBook());

    $this->actingAs($buyer)->post("/store/{$product->slug}/checkout");
    $order = Order::firstOrFail();
    expect($order->buyer_user_id)->toBe($buyer->id);
    deliver(signedEvent('checkout.session.completed', completedSession($order)))->assertOk();

    $this->actingAs($buyer)->get('/purchases')
        ->assertInertia(fn (Assert $page) => $page->component('Store/Purchases')->has('orders', 1)->has('orders.0.items.0.downloads', 2));

    $this->actingAs($other)->get('/purchases')
        ->assertInertia(fn (Assert $page) => $page->has('orders', 0));
});

it('completes the local test checkout without a card, and never in production', function () {
    $product = putOnSale(sellableBook());
    $order = startCheckout($product);

    $this->get("/store/checkout/test/{$order->stripe_session_id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Store/Checkout')->where('state', 'test'));

    $this->post("/store/checkout/test/{$order->stripe_session_id}", ['email' => 'local@example.com'])
        ->assertRedirect(route('store.checkout.success').'?session_id='.$order->stripe_session_id);

    expect($order->fresh()->status)->toBe('paid')
        ->and($order->fresh()->buyer_email)->toBe('local@example.com');

    $second = startCheckout($product);
    app()->detectEnvironment(fn () => 'production');

    $this->get("/store/checkout/test/{$second->stripe_session_id}")->assertNotFound();
    // CSRF is enforced outside the test environment; it is not what is
    // being tested here.
    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post("/store/checkout/test/{$second->stripe_session_id}", ['email' => 'x@example.com'])
        ->assertNotFound();
    expect($second->fresh()->status)->toBe('pending');
});

// ── Sales analytics ─────────────────────────────────────────────────────

it('reports an author\'s own sales: gross, fees, refunds and royalty', function () {
    $author = User::factory()->create();
    $book = sellableBook($author);
    $product = putOnSale($book);

    paidOrder($product);
    $refunded = paidOrder($product, 'second@example.com');
    deliver(signedEvent('charge.refunded', [
        'id' => 'ch_2', 'payment_intent' => 'pi_'.$refunded->id, 'amount_refunded' => 1200,
        'metadata' => ['order_id' => (string) $refunded->id],
    ]))->assertOk();

    // Somebody else's sale must not appear.
    paidOrder(putOnSale(sellableBook()));

    $this->actingAs($author)->getJson("/books/{$book->id}/sales")
        ->assertOk()
        ->assertJsonPath('direct.product.published', true)
        ->assertJsonPath('direct.totals.0.currency', 'usd')
        ->assertJsonPath('direct.totals.0.units', 2)
        ->assertJsonPath('direct.totals.0.gross_cents', 2400)
        ->assertJsonPath('direct.totals.0.refunded_cents', 1200)
        ->assertJsonPath('direct.totals.0.refunds', 1)
        ->assertJsonPath('direct.totals.0.fee_cents', 130)
        ->assertJsonPath('direct.totals.0.net_cents', 1070)
        ->assertJsonPath('direct.totals.0.royalty_cents', 1135)
        ->assertJsonPath('direct.daily.29.units', 2)
        ->assertJsonPath('direct.daily.29.refunded_cents', 1200);

    $this->actingAs($author)->get('/sales')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Sales')
            ->where('report.totals.0.units', 2)
            ->has('report.products', 1)
            ->has('report.recent', 2));
});

it('keeps the money from a co-editor who can view the book', function () {
    $book = sellableBook();
    paidOrder(putOnSale($book));
    $editor = User::factory()->create();
    $book->members()->create(['user_id' => $editor->id, 'email' => $editor->email, 'role' => 'editor', 'status' => 'active']);

    $this->actingAs($editor)->getJson("/books/{$book->id}/sales")
        ->assertOk()
        ->assertJsonPath('direct', null);

    $this->actingAs($editor)->get('/sales')
        ->assertInertia(fn (Assert $page) => $page->where('report.totals', []));
});

// ── Royalty statements ──────────────────────────────────────────────────

it('emails each author one royalty statement per month, once', function () {
    $author = User::factory()->create();

    $this->travelTo(now()->subMonthNoOverflow()->startOfMonth()->addDays(3));
    $product = putOnSale(sellableBook($author, ['title' => 'The Long Walk']));
    paidOrder($product);
    paidOrder($product, 'b@example.com');
    $this->travelBack();

    $this->artisan('commerce:royalty-statements')->assertSuccessful();
    $this->artisan('commerce:royalty-statements')->assertSuccessful();

    Mail::assertQueued(RoyaltyStatementMail::class, 1);
    Mail::assertQueued(RoyaltyStatementMail::class, fn (RoyaltyStatementMail $mail) => $mail->hasTo($author->email)
        && $mail->lines[0]['title'] === 'The Long Walk'
        && $mail->lines[0]['units'] === 2
        && $mail->lines[0]['royalty_cents'] === 2270);
});

it('rejects a malformed --month', function () {
    $this->artisan('commerce:royalty-statements', ['--month' => 'last'])->assertFailed();
});
