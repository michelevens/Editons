<?php

use App\Commerce\Fulfilment;
use App\Commerce\Partners\Attribution;
use App\Models\DiscountCode;
use App\Models\Order;
use App\Models\PartnerClick;
use App\Models\PartnerCommission;
use App\Models\User;
use App\Platform\Features;
use Illuminate\Support\Facades\DB;

/*
 * Who gets the credit: a partner's code beats their link, the last link
 * wins, and every case where no commission is due is refused — and said
 * why on the order.
 */

beforeEach(fn () => partnerProgram());

it('sets the 30-day cookie and counts a click when a book link is followed', function () {
    $partner = partnerFor();
    $product = partnerProduct();

    $response = $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone) Safari/604.1')
        ->get("/p/{$partner->handle}/{$product->slug}");

    $response->assertRedirect(route('store.product', $product->slug))
        ->assertCookie(config('partners.cookie'));

    $value = json_decode($response->getCookie(config('partners.cookie'))->getValue(), true);
    expect($value['p'])->toBe($partner->id)
        ->and($response->getCookie(config('partners.cookie'))->getExpiresTime())->toBeGreaterThan(now()->addDays(29)->getTimestamp());

    $click = PartnerClick::sole();
    expect($click->product_id)->toBe($product->id)
        ->and($click->source)->toBe('link')
        // Never the raw address or agent.
        ->and($click->ip_hash)->not->toContain('127.0.0.1')
        ->and(strlen($click->ip_hash))->toBe(64)
        ->and($click->visitor_hash)->toBe(Attribution::hash('127.0.0.1|Mozilla/5.0 (iPhone) Safari/604.1'));
});

it('counts a visitor once a day, and robots and link previews never', function () {
    $partner = partnerFor();

    foreach (range(1, 3) as $i) {
        $this->withHeader('User-Agent', 'Mozilla/5.0 Firefox/130')->get("/p/{$partner->handle}")->assertOk();
    }

    $this->withHeader('User-Agent', 'WhatsApp/2.23.20.0')->get("/p/{$partner->handle}")->assertOk();
    $this->withHeader('User-Agent', 'Googlebot/2.1 (+http://www.google.com/bot.html)')->get("/p/{$partner->handle}")->assertOk();

    expect(PartnerClick::count())->toBe(1);

    $this->travel(1)->day();
    $this->withHeader('User-Agent', 'Mozilla/5.0 Firefox/130')->get("/p/{$partner->handle}");

    expect(PartnerClick::count())->toBe(2);
});

it('accepts ?partner=CODE on an ordinary book page, and shows the recommendation with the disclosure', function () {
    $partner = partnerFor(attributes: ['display_name' => 'Lekòl Sen Jozèf']);
    $product = partnerProduct();

    $this->withHeader('User-Agent', 'Mozilla/5.0')->get("/store/{$product->slug}?partner={$partner->code}")
        ->assertOk()
        ->assertCookie(config('partners.cookie'))
        ->assertInertia(fn ($page) => $page->where('partner.name', 'Lekòl Sen Jozèf')
            ->where('partner.disclosure', 'Lekòl Sen Jozèf earns a commission on purchases made through this page.'));

    expect(PartnerClick::sole()->source)->toBe('param');
});

it('lets the last partner link win', function () {
    $first = partnerFor();
    $second = partnerFor();
    $product = partnerProduct();

    // The browser keeps one cookie: the second visit overwrote the first.
    partnerBuy($product, cookie: $first);
    $order = partnerBuy($product, cookie: $second);

    expect($order->partner_id)->toBe($second->id)
        ->and(PartnerCommission::where('partner_id', $second->id)->count())->toBe(1);
});

it('lets a partner’s discount code beat a cookie, and attribute with no cookie at all', function () {
    $cookieOwner = partnerFor();
    $codeOwner = partnerFor();
    $product = partnerProduct();
    $code = DiscountCode::create(['code' => 'FLYER10', 'kind' => 'percent', 'percent_off' => 10, 'applies_to_storefront' => true, 'active' => true, 'partner_id' => $codeOwner->id]);

    $withCookie = partnerBuy($product, cookie: $cookieOwner, code: 'flyer10');
    $withoutCookie = partnerBuy($product, code: 'FLYER10', email: 'other@example.com');

    expect($withCookie->partner_id)->toBe($codeOwner->id)
        ->and($withCookie->partner_source)->toBe('code')
        ->and($withoutCookie->partner_id)->toBe($codeOwner->id)
        ->and(PartnerCommission::where('partner_id', $codeOwner->id)->count())->toBe(2)
        ->and(PartnerCommission::where('partner_id', $cookieOwner->id)->count())->toBe(0);
});

it('ignores a cookie older than the cookie window', function () {
    $partner = partnerFor();
    $product = partnerProduct();
    $cookie = partnerEncryptedCookie(config('partners.cookie'), partnerCookie($partner, now()->subDays(31)->toIso8601String()));

    $this->call('POST', "/store/{$product->slug}/checkout", ['email' => 'r@example.com'], [config('partners.cookie') => $cookie])->assertRedirect();

    expect(Order::latest('id')->first()->partner_id)->toBeNull();
});

it('pays no commission when the buyer is the partner’s own account', function () {
    $partner = partnerFor();
    $order = partnerBuy(partnerProduct(), cookie: $partner, as: $partner->user, email: 'someone@example.com');

    expect($order->partner_refusal)->toBe('self_purchase')
        ->and(PartnerCommission::count())->toBe(0)
        ->and($order->items->first()->royalty_cents)->toBe(1135);
});

it('pays no commission when the buyer uses the partner’s email, even at Stripe', function () {
    $partner = partnerFor();

    $atCheckout = partnerBuy(partnerProduct(), cookie: $partner, email: strtoupper($partner->user->email));
    expect($atCheckout->partner_refusal)->toBe('self_purchase');

    // A different address typed on the book page, the partner's own at Stripe.
    $product = partnerProduct();
    $order = partnerBuy($product, cookie: $partner, email: 'stranger@example.com', pay: false);
    app(Fulfilment::class)->checkoutCompleted([
        'id' => $order->stripe_session_id, 'payment_status' => 'paid', 'amount_total' => $order->total_cents, 'currency' => 'usd',
        'payment_intent' => 'pi_self', 'customer_details' => ['email' => $partner->user->email],
    ]);

    expect($order->fresh()->partner_refusal)->toBe('self_purchase')
        ->and(PartnerCommission::count())->toBe(0);
});

it('pays no commission to the book’s own seller', function () {
    $seller = User::factory()->create();
    $partner = partnerFor($seller);
    $order = partnerBuy(partnerProduct($seller), cookie: $partner);

    expect($order->partner_refusal)->toBe('seller')->and(PartnerCommission::count())->toBe(0);
});

it('pays no commission to a suspended partner, nor one suspended before payment', function () {
    $suspended = partnerFor(attributes: ['status' => 'suspended', 'suspended_at' => now()]);
    $order = partnerBuy(partnerProduct(), cookie: $suspended);
    expect($order->partner_refusal)->toBe('suspended');

    $partner = partnerFor();
    $later = partnerBuy(partnerProduct(), cookie: $partner, pay: false);
    $partner->forceFill(['status' => 'suspended'])->save();
    app(Fulfilment::class)->checkoutCompleted([
        'id' => $later->stripe_session_id, 'payment_status' => 'paid', 'amount_total' => $later->total_cents, 'currency' => 'usd',
        'payment_intent' => 'pi_susp', 'customer_details' => ['email' => 'reader@example.com'],
    ]);

    expect($later->fresh()->partner_refusal)->toBe('suspended')->and(PartnerCommission::count())->toBe(0);
});

it('pays no commission on a book the seller has not opened to partners', function () {
    $order = partnerBuy(partnerProduct(rate: null), cookie: partnerFor());

    expect($order->partner_refusal)->toBe('not_opted_in')
        ->and($order->items->first()->partner_rate_bps)->toBeNull()
        ->and(PartnerCommission::count())->toBe(0);
});

it('pays no commission to a partner the seller blocked', function () {
    $partner = partnerFor();
    $product = partnerProduct();

    $this->actingAs($product->user)->post(route('sales.partners.block', $partner))->assertRedirect();
    auth()->logout();

    expect(DB::table('partner_blocks')->count())->toBe(1);

    $order = partnerBuy($product, cookie: $partner);
    expect($order->partner_refusal)->toBe('blocked')->and(PartnerCommission::count())->toBe(0);
});

it('pays an invited partner only on the inviting seller’s books', function () {
    $seller = User::factory()->create();
    $partner = partnerFor(attributes: ['restricted' => true]);
    $partner->sellers()->attach($seller->id);

    $theirs = partnerBuy(partnerProduct($seller), cookie: $partner);
    $other = partnerBuy(partnerProduct(), cookie: $partner);

    expect($theirs->partner_refusal)->toBeNull()
        ->and($other->partner_refusal)->toBe('not_permitted')
        ->and(PartnerCommission::count())->toBe(1);
});

it('attributes nothing while the program is switched off', function () {
    app(Features::class)->setGlobal('partners', false, 'Not launched yet', platformAdmin());

    $order = partnerBuy(partnerProduct(), cookie: partnerFor());

    expect($order->partner_id)->toBeNull()->and(PartnerCommission::count())->toBe(0);
});
