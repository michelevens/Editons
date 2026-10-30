<?php

use App\Commerce\Bundles;
use App\Commerce\Fulfilment;
use App\Commerce\Gateway\PaymentGateway;
use App\Commerce\Reports\ReportRange;
use App\Commerce\Reports\RoyaltyStatements;
use App\Commerce\Reports\SalesExplorer;
use App\Commerce\Revenue;
use App\Mail\Commerce\OrderReceiptMail;
use App\Mail\Commerce\PreorderReleasedMail;
use App\Models\Book;
use App\Models\Bundle;
use App\Models\DiscountCode;
use App\Models\License;
use App\Models\Order;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * An author's own discount codes, bundles and pre-orders: who can make and
 * use them, what checkout charges, how a bundle's royalty is split, and
 * the release job — and that no author reaches another's.
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

function sellerBook(User $owner, array $attributes = [], bool $exports = true): Book
{
    $book = Book::factory()->for($owner)->create($attributes);

    if ($exports) {
        foreach (['epub', 'pdf'] as $format) {
            $path = "exports/{$book->id}/book.{$format}";
            Storage::disk('files')->put($path, "{$format} bytes");
            $book->exports()->create(['format' => $format, 'status' => 'ready', 'path' => $path, 'byte_size' => 10]);
        }
    }

    return $book;
}

function sellerProduct(Book $book, string $price = '12.00', array $overrides = []): Product
{
    test()->actingAs($book->user)->putJson("/books/{$book->id}/product", array_merge([
        'price' => $price, 'currency' => 'usd', 'formats' => ['epub', 'pdf'], 'license' => 'personal', 'published' => true,
    ], $overrides))->assertOk();
    auth()->logout();

    return $book->fresh()->product;
}

/** Pay an order through the fake gateway's own page. */
function payOrder(Order $order, string $email = 'reader@example.com'): Order
{
    test()->post(route('store.checkout.fake.pay', $order->stripe_session_id), ['email' => $email])->assertRedirect();

    return $order->fresh('items.license');
}

// ── Author discount codes ───────────────────────────────────────────────

it('lets an author make a code for their own book, used at checkout', function () {
    $author = User::factory()->create();
    $product = sellerProduct(sellerBook($author));
    $other = sellerProduct(sellerBook($author), '8.00');

    $this->actingAs($author)->postJson('/sales/discounts', [
        'code' => 'launch', 'kind' => 'percent', 'percent_off' => 25,
        'product_ids' => [$product->id], 'max_redemptions' => 1, 'expires_on' => now()->addMonth()->toDateString(),
    ])->assertOk()->assertJsonPath('codes.0.code', 'LAUNCH')->assertJsonPath('codes.0.used', 0);
    auth()->logout();

    $code = DiscountCode::where('code', 'LAUNCH')->first();
    expect($code->seller_id)->toBe($author->id)
        ->and($code->applies_to_plans)->toBeFalse()
        ->and($code->stripe_coupon_id)->toBeNull();

    // Not on the author's other book: it names only the first.
    $this->post("/store/{$other->slug}/checkout", ['email' => 'r@example.com', 'code' => 'launch'])
        ->assertSessionHasErrors('code');

    $this->post("/store/{$product->slug}/checkout", ['email' => 'r@example.com', 'code' => 'launch'])->assertRedirect();
    $order = Order::latest('id')->first();
    expect($order->total_cents)->toBe(900)->and($order->discount_code_id)->toBe($code->id);
    payOrder($order);

    // Its one use is spent.
    $this->post("/store/{$product->slug}/checkout", ['email' => 'r2@example.com', 'code' => 'launch'])
        ->assertSessionHasErrors('code');
});

it('never lets one author use, see or change another author\'s code', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $aliceBook = sellerProduct(sellerBook($alice));
    $bobBook = sellerProduct(sellerBook($bob));

    $this->actingAs($alice)->postJson('/sales/discounts', ['code' => 'SUMMER', 'kind' => 'percent', 'percent_off' => 50])->assertOk();
    // The same name is free for Bob: codes are unique per seller.
    $this->actingAs($bob)->postJson('/sales/discounts', ['code' => 'summer', 'kind' => 'amount', 'amount_off' => '1.00', 'currency' => 'usd'])->assertOk();
    $aliceCode = DiscountCode::where('seller_id', $alice->id)->first();

    // Bob's list holds only his; he cannot change Alice's.
    $this->actingAs($bob)->getJson('/sales/discounts')->assertJsonCount(1, 'codes')->assertJsonPath('codes.0.kind', 'amount');
    $this->putJson("/sales/discounts/{$aliceCode->id}", ['active' => false])->assertNotFound();
    // Nor aim his code at Alice's book.
    $this->postJson('/sales/discounts', ['code' => 'SNEAKY', 'kind' => 'percent', 'percent_off' => 10, 'product_ids' => [$aliceBook->id]])
        ->assertUnprocessable()->assertJsonValidationErrors('product_ids');
    auth()->logout();

    // On Bob's book, SUMMER is Bob's code (1.00 off), never Alice's 50 %.
    $this->post("/store/{$bobBook->slug}/checkout", ['email' => 'r@example.com', 'code' => 'SUMMER'])->assertRedirect();
    expect(Order::latest('id')->first()->total_cents)->toBe(1100);

    // A code of Alice's that Bob does not have reads as not valid on his.
    $this->actingAs($alice)->postJson('/sales/discounts', ['code' => 'ALICEONLY', 'kind' => 'percent', 'percent_off' => 10])->assertOk();
    auth()->logout();
    $this->post("/store/{$bobBook->slug}/checkout", ['email' => 'r@example.com', 'code' => 'ALICEONLY'])
        ->assertSessionHasErrors(['code' => 'That code is not valid.']);
});

it('keeps the owner\'s site-wide codes working on every book', function () {
    $product = sellerProduct(sellerBook(User::factory()->create()));
    DiscountCode::create(['code' => 'HOUSE10', 'kind' => 'percent', 'percent_off' => 10, 'applies_to_storefront' => true, 'active' => true]);

    $this->post("/store/{$product->slug}/checkout", ['email' => 'r@example.com', 'code' => 'house10'])->assertRedirect();
    expect(Order::latest('id')->first()->total_cents)->toBe(1080);
});

it('lets an author pause a code and refuses an expired one', function () {
    $author = User::factory()->create();
    $product = sellerProduct(sellerBook($author));
    $this->actingAs($author)->postJson('/sales/discounts', ['code' => 'OLD', 'kind' => 'percent', 'percent_off' => 10, 'expires_on' => '2026-01-01'])->assertOk();
    $this->postJson('/sales/discounts', ['code' => 'PAUSE', 'kind' => 'percent', 'percent_off' => 10])->assertOk();
    $pause = DiscountCode::where('code', 'PAUSE')->first();
    $this->putJson("/sales/discounts/{$pause->id}", ['active' => false])->assertOk()->assertJsonPath('codes.0.active', false);
    auth()->logout();

    $this->post("/store/{$product->slug}/checkout", ['email' => 'r@example.com', 'code' => 'OLD'])->assertSessionHasErrors(['code' => 'That code has expired.']);
    $this->post("/store/{$product->slug}/checkout", ['email' => 'r@example.com', 'code' => 'PAUSE'])->assertSessionHasErrors(['code' => 'That code is not valid.']);
});

// ── Bundles ─────────────────────────────────────────────────────────────

it('sells a bundle as one item per book, the price split by list price', function () {
    $author = User::factory()->create();
    $a = sellerProduct(sellerBook($author, ['title' => 'Book One']), '10.00');
    $b = sellerProduct(sellerBook($author, ['title' => 'Book Two']), '20.00');
    $c = sellerProduct(sellerBook($author, ['title' => 'Book Three']), '30.00');

    $this->actingAs($author)->postJson('/sales/bundles', [
        'title' => 'The whole series', 'price' => '45.00', 'currency' => 'usd',
        'product_ids' => [$a->id, $b->id, $c->id], 'published' => true,
    ])->assertOk()->assertJsonPath('bundles.0.on_sale', true)->assertJsonPath('bundles.0.separately_cents', 6000);
    auth()->logout();
    $bundle = Bundle::first();

    $this->get("/store/bundles/{$bundle->slug}")->assertOk();

    $this->post("/store/bundles/{$bundle->slug}/checkout", ['email' => 'r@example.com'])->assertRedirect();
    $order = Order::latest('id')->first();
    $session = collect(app(PaymentGateway::class)->sessions)->last();

    expect($order->total_cents)->toBe(4500)
        ->and($session['line_items'])->toHaveCount(1)
        ->and($session['line_items'][0])->amount_cents->toBe(4500)->name->toBe('The whole series');

    // 10 : 20 : 30 of 45.00 → 7.50, 15.00, 22.50.
    $items = $order->items()->orderBy('id')->get();
    expect($items->pluck('price_cents')->all())->toBe([750, 1500, 2250])
        ->and($items->pluck('list_price_cents')->all())->toBe([1000, 2000, 3000])
        ->and($items->pluck('bundle_id')->unique()->all())->toBe([$bundle->id])
        ->and($items->pluck('book_id')->all())->toBe([$a->book_id, $b->book_id, $c->book_id]);

    $order = payOrder($order);

    // Every book gets its licence, and a royalty from its own share.
    expect($order->items->every(fn ($item) => $item->license !== null))->toBeTrue();
    $fee = Revenue::fee(4500);
    $fees = Revenue::allocate($fee, [750, 1500, 2250]);
    expect($order->items->sortBy('id')->pluck('royalty_cents')->values()->all())->toBe([
        Revenue::royalty(750, $fees[0]), Revenue::royalty(1500, $fees[1]), Revenue::royalty(2250, $fees[2]),
    ]);
    expect($order->items->sum('price_cents'))->toBe(4500);

    // The sales explorer and the statement see three book sales.
    $range = ReportRange::preset('last_30', $author->reportTimezone());
    $totals = collect((new SalesExplorer([$author->id]))->aggregate($range)['totals'])->firstWhere('currency', 'usd');
    expect($totals['units'])->toBe(3)->and($totals['gross_cents'])->toBe(4500);
    $lines = iterator_to_array((new SalesExplorer([$author->id]))->csvLines($range), false);
    expect(array_slice($lines, 1))->each(fn ($line) => $line->toContain('The whole series'));

    $statement = app(RoyaltyStatements::class)->build($author, now()->setTimezone($author->reportTimezone())->format('Y-m'), 'usd');
    expect(json_encode($statement))->toContain('Book One')->toContain('Book Three');
});

it('splits a discounted bundle and rounds to the cent', function () {
    $author = User::factory()->create();
    $products = collect(['3.33', '3.33', '3.34'])->map(fn ($p) => sellerProduct(sellerBook($author), $p));
    $bundle = app(Bundles::class)->save($author, [
        'title' => 'Trio', 'price' => '7.00', 'currency' => 'usd', 'product_ids' => $products->pluck('id')->all(), 'published' => true,
    ]);

    $shares = Bundles::split($bundle, 700);
    expect(array_sum(array_column($shares, 'cents')))->toBe(700)
        ->and(array_column($shares, 'cents'))->toBe([233, 233, 234]);

    $this->actingAs($author)->postJson('/sales/discounts', ['code' => 'TRIO', 'kind' => 'amount', 'amount_off' => '1.00', 'currency' => 'usd', 'bundle_ids' => [$bundle->id]])->assertOk();
    auth()->logout();

    $this->post("/store/bundles/{$bundle->slug}/checkout", ['email' => 'r@example.com', 'code' => 'TRIO'])->assertRedirect();
    $order = Order::latest('id')->first();
    expect($order->total_cents)->toBe(600)->and($order->items()->sum('price_cents'))->toBe(600);

    // The bundle-only code is not for the books alone.
    $this->post("/store/{$products[0]->slug}/checkout", ['email' => 'r@example.com', 'code' => 'TRIO'])->assertSessionHasErrors('code');
});

it('takes a bundle off sale with any of its books and keeps bundles per seller', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $a = sellerProduct(sellerBook($alice));
    $b = sellerProduct(sellerBook($alice));
    $bobs = sellerProduct(sellerBook($bob));

    // Bob cannot bundle Alice's book, nor see or change her bundle.
    $this->actingAs($bob)->postJson('/sales/bundles', [
        'title' => 'Mine', 'price' => '10', 'currency' => 'usd', 'product_ids' => [$bobs->id, $a->id], 'published' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors('product_ids');

    $this->actingAs($alice)->postJson('/sales/bundles', [
        'title' => 'Pair', 'price' => '20', 'currency' => 'usd', 'product_ids' => [$a->id, $b->id], 'published' => true,
    ])->assertOk();
    $bundle = Bundle::first();

    $this->actingAs($bob)->getJson('/sales/bundles')->assertJsonCount(0, 'bundles');
    $this->putJson("/sales/bundles/{$bundle->id}", ['title' => 'x', 'price' => '1', 'currency' => 'usd', 'product_ids' => [$bobs->id], 'published' => false])->assertNotFound();
    $this->deleteJson("/sales/bundles/{$bundle->id}")->assertNotFound();
    auth()->logout();

    // One book off sale: the bundle page goes too.
    $a->forceFill(['published_at' => null])->save();
    $this->get("/store/bundles/{$bundle->slug}")->assertNotFound();
    $this->post("/store/bundles/{$bundle->slug}/checkout", ['email' => 'r@example.com'])->assertNotFound();
});

it('refunds a bundle across its books and revokes every licence', function () {
    $author = User::factory()->create();
    $a = sellerProduct(sellerBook($author), '10.00');
    $b = sellerProduct(sellerBook($author), '30.00');
    $bundle = app(Bundles::class)->save($author, ['title' => 'Pair', 'price' => '20', 'currency' => 'usd', 'product_ids' => [$a->id, $b->id], 'published' => true]);

    $this->post("/store/bundles/{$bundle->slug}/checkout", ['email' => 'r@example.com'])->assertRedirect();
    $order = payOrder(Order::latest('id')->first());

    app(Fulfilment::class)->chargeRefunded([
        'payment_intent' => $order->stripe_payment_intent, 'amount_refunded' => 2000, 'metadata' => ['order_id' => (string) $order->id],
    ]);

    $order->refresh();
    expect($order->status)->toBe('refunded')
        ->and($order->items->pluck('refunded_cents')->all())->toBe([500, 1500])
        ->and(License::whereIn('order_item_id', $order->items->pluck('id'))->where('status', 'active')->count())->toBe(0);
});

// ── Pre-orders ──────────────────────────────────────────────────────────

it('sells a pre-order now and delivers it at release, once', function () {
    $author = User::factory()->create(['timezone' => 'America/New_York']);
    Carbon::setTestNow('2026-11-10 12:00:00');

    // No export yet: a pre-order may go on sale before its files exist.
    $book = sellerBook($author, exports: false);
    $product = sellerProduct($book, '9.00', ['release_on' => '2026-11-20']);
    expect($product->isPreorder())->toBeTrue()
        ->and($product->release_at->toIso8601String())->toBe('2026-11-20T05:00:00+00:00');

    $buyer = User::factory()->create();
    $this->actingAs($buyer)->post("/store/{$product->slug}/checkout", ['email' => 'r@example.com'])->assertRedirect();
    $order = payOrder(Order::latest('id')->first());

    expect($order->status)->toBe('paid')
        ->and($order->items->first()->license)->toBeNull()
        ->and($order->items->first()->release_at->toIso8601String())->toBe('2026-11-20T05:00:00+00:00');
    Mail::assertQueued(OrderReceiptMail::class, fn ($mail) => $mail->releaseAt !== null);

    // Too early: nothing.
    $this->artisan('commerce:release-preorders')->assertSuccessful();
    expect(License::count())->toBe(0);

    Carbon::setTestNow('2026-11-20 05:01:00');
    $this->artisan('commerce:release-preorders')->assertSuccessful();
    $this->artisan('commerce:release-preorders')->assertSuccessful();

    expect(License::count())->toBe(1)
        ->and($order->items()->first()->delivered_at)->not->toBeNull();
    Mail::assertQueued(PreorderReleasedMail::class, 1);
    Mail::assertQueued(PreorderReleasedMail::class, fn ($mail) => $mail->buyerEmail === 'reader@example.com' && count($mail->items[0]['downloads']) === 2);

    // Released: too late to cancel.
    $this->actingAs($buyer)->post(route('purchases.cancel', $order->number))->assertSessionHasErrors('cancel');
    Carbon::setTestNow();
});

it('lets the buyer cancel a pre-order before release, refunded in full', function () {
    $author = User::factory()->create();
    Carbon::setTestNow('2026-11-10 12:00:00');
    $product = sellerProduct(sellerBook($author), '9.00', ['release_on' => '2026-12-01']);
    $buyer = User::factory()->create();
    $stranger = User::factory()->create();

    $this->actingAs($buyer)->post("/store/{$product->slug}/checkout", ['email' => 'r@example.com'])->assertRedirect();
    $order = payOrder(Order::latest('id')->first());

    // Somebody else's order is not there to cancel.
    $this->actingAs($stranger)->post(route('purchases.cancel', $order->number))->assertNotFound();

    $this->actingAs($buyer)->get('/purchases')->assertInertia(fn ($page) => $page
        ->where('orders.0.cancel_url', route('purchases.cancel', $order->number))
        ->where('orders.0.items.0.release_at', '2026-12-01T05:00:00+00:00'));

    $this->post(route('purchases.cancel', $order->number))->assertRedirect()->assertSessionHasNoErrors();

    $order->refresh();
    $refund = Refund::where('order_id', $order->id)->first();
    expect($order->status)->toBe('refunded')
        ->and($refund->amount_cents)->toBe(900)
        ->and($refund->actor_id)->toBe($buyer->id)
        ->and(collect(app(PaymentGateway::class)->refunds)->last()['amount_cents'])->toBe(900);

    // A cancelled pre-order is never delivered.
    Carbon::setTestNow('2026-12-02 00:00:00');
    $this->artisan('commerce:release-preorders')->assertSuccessful();
    expect(License::count())->toBe(0);
    Mail::assertNotQueued(PreorderReleasedMail::class);
    Carbon::setTestNow();
});

it('refuses a release date in the past', function () {
    $book = sellerBook(User::factory()->create());

    $this->actingAs($book->user)->putJson("/books/{$book->id}/product", [
        'price' => '9', 'currency' => 'usd', 'formats' => ['epub'], 'license' => 'personal', 'published' => true, 'release_on' => '2020-01-01',
    ])->assertUnprocessable()->assertJsonValidationErrors('release_on');
});
