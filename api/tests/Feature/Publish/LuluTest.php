<?php

use App\Commerce\Gateway\PaymentGateway;
use App\Commerce\Print\LuluClient;
use App\Commerce\Print\LuluException;
use App\Commerce\Print\LuluPackage;
use App\Commerce\Print\LuluPrinting;
use App\Commerce\Print\PrintJobs;
use App\Mail\Commerce\PaperbackShippedMail;
use App\Models\Book;
use App\Models\Order;
use App\Models\PrintJob;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/*
 * Lulu's Print API against a faked HTTP layer: client-credentials auth,
 * file validation, the one-copy quote, the paperback on sale in the
 * storefront, a paid order becoming a print job, status polling with the
 * tracking sent to the buyer, and what happens when Lulu says no. No
 * request ever leaves the test (Http::preventStrayRequests).
 */

const LULU = 'https://api.sandbox.lulu.com';

beforeEach(function () {
    Storage::fake('files');
    Mail::fake();
    Cache::flush();
    Http::preventStrayRequests();
    $this->withoutVite();

    config([
        'commerce.enabled' => true,
        'commerce.gateway' => 'fake',
        'commerce.paperback.shipping_cents' => 599,
        'commerce.paperback.countries' => ['US', 'CA'],
        'services.lulu.client_key' => 'test-key',
        'services.lulu.client_secret' => 'test-secret',
        'services.lulu.env' => 'sandbox',
        'services.lulu.contact_email' => 'owner@example.com',
    ]);
});

/** The Lulu answers a happy path gives, recorded from the API reference's examples. */
function luluFake(array $overrides = []): void
{
    Http::fake(array_merge([
        LULU.'/auth/realms/glasstree/protocol/openid-connect/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600, 'token_type' => 'bearer']),
        LULU.'/validate-interior/' => Http::response(['id' => 11, 'status' => 'VALIDATING', 'errors' => null], 201),
        LULU.'/validate-cover/' => Http::response(['id' => 22, 'status' => 'NORMALIZING', 'errors' => null], 201),
        LULU.'/validate-interior/11/' => Http::response(['id' => 11, 'status' => 'NORMALIZED', 'page_count' => '120', 'errors' => []]),
        LULU.'/validate-cover/22/' => Http::response(['id' => 22, 'status' => 'NORMALIZED', 'errors' => []]),
        LULU.'/print-job-cost-calculations/' => Http::response([
            'currency' => 'USD',
            'line_item_costs' => [['quantity' => 1, 'total_cost_excl_tax' => '4.25', 'total_cost_incl_tax' => '4.51']],
            'fulfillment_cost' => ['total_cost_excl_tax' => '0.75'],
            'shipping_cost' => ['total_cost_excl_tax' => '3.99'],
        ], 201),
        LULU.'/print-jobs/' => Http::response(['id' => 4242, 'status' => ['name' => 'CREATED'], 'costs' => ['total_cost_incl_tax' => '10.89', 'currency' => 'USD']], 201),
        LULU.'/print-jobs/4242/status/' => Http::response([
            'name' => 'SHIPPED', 'message' => 'All line-items were shipped', 'changed' => '2026-10-28T09:28:34Z',
            'line_item_statuses' => [['name' => 'SHIPPED', 'messages' => [
                'tracking_id' => 'TRK-1', 'tracking_urls' => ['https://tracking.example/TRK-1'], 'carrier_name' => 'USPS',
            ]]],
        ]),
    ], $overrides));
}

/** A book whose print interior and Lulu cover wrap are fresh, on sale as an ebook. */
function luluBook(): Book
{
    $book = pfChoose(pfBook(), 'store', 'lulu');
    pfFile($book, 'epub');
    pfFile($book, 'pdf_interior', [], 120);
    pfFile($book, 'cover_wrap', ['service' => 'lulu', 'paper' => 'white'], 120);
    test()->actingAs($book->user)->postJson("/books/{$book->id}/publish-flow/store")->assertOk();

    return $book->fresh();
}

it('builds the pod_package_id from the book\'s print settings, in Lulu\'s dotted form', function () {
    $book = pfBook();
    expect(LuluPackage::for($book)['id'])->toBe('0600X0900.BW.STD.PB.060UW444.MXX');

    $book->forceFill(['print' => ['paper' => 'cream', 'ink' => 'bw', 'finish' => 'glossy'], 'layout' => array_merge($book->layout(), ['trim' => '5.5 x 8.5 in'])])->save();
    expect(LuluPackage::for($book->fresh())['id'])->toBe('0550X0850.BW.STD.PB.060UC444.GXX')
        ->and(LuluPackage::describe('0550X0850.BW.STD.PB.060UC444.GXX'))->toContain('5.5 × 8.5 in')->toContain('cream');

    $book->forceFill(['print' => ['ink' => 'premium_color']])->save();
    expect(LuluPackage::for($book->fresh())['id'])->toBe('0550X0850.FC.PRE.PB.080CW444.MXX');
});

it('authenticates with client credentials and reuses the token until it expires', function () {
    luluFake();
    $client = app(LuluClient::class);

    $client->interiorValidation(11);
    $client->coverValidation(22);

    Http::assertSentCount(3);
    Http::assertSent(fn (HttpRequest $r) => $r->url() === LULU.'/auth/realms/glasstree/protocol/openid-connect/token'
        && $r['grant_type'] === 'client_credentials'
        && $r->hasHeader('Authorization', 'Basic '.base64_encode('test-key:test-secret')));
    Http::assertSent(fn (HttpRequest $r) => $r->url() === LULU.'/validate-cover/22/' && $r->hasHeader('Authorization', 'Bearer tok-1'));
});

it('says plainly when the keys are wrong, and is not connected without them', function () {
    Http::fake([LULU.'/auth/realms/glasstree/protocol/openid-connect/token' => Http::response(['error' => 'invalid_client'], 401)]);

    expect(fn () => app(LuluClient::class)->interiorValidation(1))
        ->toThrow(LuluException::class, 'Lulu refused the API keys');

    config(['services.lulu.client_key' => null]);
    expect(app(LuluClient::class)->connected())->toBeFalse()
        ->and(app(LuluPrinting::class)->status(pfBook())['state'])->toBe('not_connected')
        ->and(app(LuluPrinting::class)->status(pfBook())['needs'])->not->toBe([]);
});

it('sends the fresh print files to Lulu\'s validation from signed links, then offers the paperback once both pass', function () {
    luluFake();
    $book = luluBook();
    $this->actingAs($book->user);

    $this->postJson("/books/{$book->id}/publish-flow/lulu")->assertOk()->assertJsonPath('lulu.state', 'validating');

    Http::assertSent(function (HttpRequest $r) {
        if ($r->url() !== LULU.'/validate-cover/') {
            return false;
        }

        // Lulu fetches the file itself, from a signed link that works unsigned-in.
        $file = test()->get($r['source_url']);

        return $r['pod_package_id'] === '0600X0900.BW.STD.PB.060UW444.MXX'
            && $r['interior_page_count'] === 120
            && str_contains($r['source_url'], 'signature=')
            && $file->getStatusCode() === 200;
    });

    // Not yet passed: the paperback cannot be offered.
    $this->postJson("/books/{$book->id}/publish-flow/lulu/offer", ['on' => true])->assertStatus(422);

    $this->postJson("/books/{$book->id}/publish-flow/lulu/refresh")->assertOk()->assertJsonPath('lulu.state', 'validated');
    $this->postJson("/books/{$book->id}/publish-flow/lulu/offer", ['on' => true])->assertOk()->assertJsonPath('lulu.state', 'live');

    $product = $book->fresh()->product;
    expect($product->paperback_price_cents)->toBe(1499)
        ->and($product->print['pod_package_id'])->toBe('0600X0900.BW.STD.PB.060UW444.MXX')
        ->and($product->print['unit_cost_cents'])->toBe(500)
        ->and(app(LuluPrinting::class)->sellable($product))->toBeTrue();

    // A tampered link is refused.
    $this->get('/print-files/'.$product->print['interior_export_id'].'/interior.pdf')->assertForbidden();

    // The files change: no longer sellable until Lulu checks them again.
    $this->travel(5)->minutes();
    $book->sections()->first()->update(['title' => 'Changed']);
    expect(app(LuluPrinting::class)->sellable($product->fresh()))->toBeFalse()
        ->and(app(LuluPrinting::class)->status($book->fresh())['state'])->toBe('stale');
});

it('turns a paid paperback order into a Lulu print job, with the shipping address, and tracks it to the buyer', function () {
    luluFake();
    $book = luluBook();
    $this->actingAs($book->user)->postJson("/books/{$book->id}/publish-flow/lulu");
    $this->postJson("/books/{$book->id}/publish-flow/lulu/refresh");
    $this->postJson("/books/{$book->id}/publish-flow/lulu/offer", ['on' => true])->assertOk();
    auth()->logout();

    $product = $book->fresh()->product;
    $this->get("/store/{$product->slug}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('product.paperback.price_cents', 1499)->where('product.paperback.shipping_cents', 599));

    // Codes are for the ebook only.
    $this->post("/store/{$product->slug}/checkout", ['email' => 'reader@example.com', 'edition' => 'paperback', 'code' => 'SAVE'])->assertSessionHasErrors('code');

    $this->post("/store/{$product->slug}/checkout", ['email' => 'reader@example.com', 'edition' => 'paperback'])->assertRedirect();
    $order = Order::latest('id')->firstOrFail();

    expect($order->total_cents)->toBe(1499 + 599)
        ->and($order->shipping_cents)->toBe(599);

    $session = app(PaymentGateway::class)->sessions[$order->stripe_session_id];
    expect($session['shipping']['amount_cents'])->toBe(599)
        ->and($session['shipping']['countries'])->toBe(['US', 'CA'])
        ->and($session['line_items'][0]['amount_cents'])->toBe(1499);

    // The fake checkout asks for the address Stripe would collect.
    $this->post("/store/checkout/test/{$order->stripe_session_id}", ['email' => 'reader@example.com'])->assertSessionHasErrors(['line1', 'phone']);
    $this->post("/store/checkout/test/{$order->stripe_session_id}", [
        'email' => 'reader@example.com', 'name' => 'Jonas Pierre', 'line1' => '12 Rue Capois', 'city' => 'Miami',
        'state' => 'FL', 'postal_code' => '33101', 'country' => 'us', 'phone' => '+1 305 555 0100',
    ])->assertRedirect();

    $order = $order->fresh();
    $item = $order->items()->sole();
    $job = PrintJob::sole();

    expect($order->status)->toBe('paid')
        ->and($order->shipping_address['street1'])->toBe('12 Rue Capois')
        ->and($order->shipping_address['country_code'])->toBe('US')
        ->and($item->formats)->toBe(['paperback'])
        ->and($item->license)->toBeNull()
        ->and($item->print_cost_cents)->toBe(500)
        ->and($job->status)->toBe('CREATED')
        ->and($job->external_id)->toBe('4242')
        ->and($job->cost_cents)->toBe(1089);

    // The address is stored encrypted.
    expect(DB::table('orders')->where('id', $order->id)->value('shipping_address'))->not->toContain('Rue Capois');

    Http::assertSent(fn (HttpRequest $r) => $r->url() === LULU.'/print-jobs/'
        && $r['external_id'] === $order->number
        && $r['contact_email'] === 'owner@example.com'
        && $r['shipping_level'] === 'MAIL'
        && $r['shipping_address']['phone_number'] === '+1 305 555 0100'
        && $r['line_items'][0]['printable_normalization']['pod_package_id'] === '0600X0900.BW.STD.PB.060UW444.MXX'
        && str_contains($r['line_items'][0]['printable_normalization']['interior']['source_url'], '/print-files/'));

    // Retried: never a second job at Lulu.
    app(PrintJobs::class)->submit($job->fresh());
    expect(Http::recorded(fn ($r) => $r->url() === LULU.'/print-jobs/')->count())->toBe(1);

    // Polled: shipped, tracking stored, the buyer told once.
    $this->artisan('lulu:poll')->assertSuccessful();
    $this->artisan('lulu:poll')->assertSuccessful();

    expect($job->fresh())
        ->status->toBe('SHIPPED')
        ->tracking->toBe(['id' => 'TRK-1', 'urls' => ['https://tracking.example/TRK-1'], 'carrier' => 'USPS'])
        ->buyer_notified_at->not->toBeNull();

    Mail::assertQueued(PaperbackShippedMail::class, 1);
    Mail::assertQueued(PaperbackShippedMail::class, fn ($mail) => $mail->trackingUrl === 'https://tracking.example/TRK-1' && $mail->buyerEmail === 'reader@example.com');
});

it('records a refusal from Lulu on the job, retries it later, and never charges the buyer twice', function () {
    luluFake([LULU.'/print-jobs/' => Http::sequence()
        ->push(['shipping_address' => ['postcode' => ['Invalid postcode']]], 400)
        ->push(['id' => 5151, 'status' => ['name' => 'CREATED']], 201)]);
    $book = luluBook();
    $this->actingAs($book->user)->postJson("/books/{$book->id}/publish-flow/lulu");
    $this->postJson("/books/{$book->id}/publish-flow/lulu/refresh");
    $this->postJson("/books/{$book->id}/publish-flow/lulu/offer", ['on' => true])->assertOk();
    auth()->logout();

    $product = $book->fresh()->product;
    $this->post("/store/{$product->slug}/checkout", ['email' => 'reader@example.com', 'edition' => 'paperback']);
    $order = Order::latest('id')->firstOrFail();
    $this->post("/store/checkout/test/{$order->stripe_session_id}", [
        'email' => 'reader@example.com', 'name' => 'Jonas', 'line1' => '1 Main St', 'city' => 'Boston',
        'postal_code' => 'XXXXX', 'country' => 'US', 'phone' => '+1 617 555 0100',
    ]);

    $job = PrintJob::sole();
    expect($job->status)->toBe('failed')
        ->and($job->error)->toContain('Invalid postcode')
        ->and($job->attempts)->toBe(1)
        ->and($order->fresh()->status)->toBe('paid');

    // The next poll sends it again; Lulu takes it.
    Http::fake([LULU.'/print-jobs/5151/status/' => Http::response(['name' => 'PRODUCTION_READY', 'line_item_statuses' => []])]);
    $this->artisan('lulu:poll')->assertSuccessful();

    expect($job->fresh())->status->toBe('PRODUCTION_READY')->external_id->toBe('5151')->error->toBeNull();

    // A rejection later is recorded, and the buyer is not told it shipped.
    app(PrintJobs::class)->apply($job->fresh(), ['name' => 'REJECTED', 'message' => 'Cover too small']);
    expect($job->fresh())->status->toBe('REJECTED')->error->toBe('Cover too small');
    Mail::assertNotQueued(PaperbackShippedMail::class);
});

it('does not sell a paperback without Lulu keys or a shipping charge, and opens no print job for an ebook', function () {
    luluFake();
    $book = luluBook();
    $this->actingAs($book->user)->postJson("/books/{$book->id}/publish-flow/lulu");
    $this->postJson("/books/{$book->id}/publish-flow/lulu/refresh");

    config(['commerce.paperback.shipping_cents' => null]);
    $this->postJson("/books/{$book->id}/publish-flow/lulu/offer", ['on' => true])
        ->assertStatus(422)->assertJsonPath('errors.lulu.0', fn ($m) => str_contains($m, 'shipping charge'));

    config(['commerce.paperback.shipping_cents' => 599]);
    $this->postJson("/books/{$book->id}/publish-flow/lulu/offer", ['on' => true])->assertOk();

    config(['services.lulu.client_key' => null]);
    expect(app(LuluPrinting::class)->sellable($book->fresh()->product))->toBeFalse();
    auth()->logout();

    $product = $book->fresh()->product;
    $this->post("/store/{$product->slug}/checkout", ['email' => 'reader@example.com', 'edition' => 'paperback'])->assertSessionHasErrors('checkout');

    // An ebook order is untouched by any of this.
    $this->post("/store/{$product->slug}/checkout", ['email' => 'reader@example.com']);
    $order = Order::latest('id')->firstOrFail();
    $this->post("/store/checkout/test/{$order->stripe_session_id}", ['email' => 'reader@example.com'])->assertRedirect();

    expect($order->fresh()->status)->toBe('paid')
        ->and($order->fresh()->items()->sole()->license)->not->toBeNull()
        ->and(PrintJob::count())->toBe(0);
});

it('refuses Lulu to anyone but the book\'s owner', function () {
    luluFake();
    $book = luluBook();
    $editor = User::factory()->create();
    $book->members()->create(['user_id' => $editor->id, 'role' => 'editor', 'status' => 'active', 'email' => $editor->email]);

    $this->actingAs($editor)->postJson("/books/{$book->id}/publish-flow/lulu")->assertForbidden();
    $this->actingAs($editor)->postJson("/books/{$book->id}/publish-flow/lulu/offer", ['on' => true])->assertForbidden();
    Http::assertNothingSent();
});
