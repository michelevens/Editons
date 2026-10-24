<?php

use App\Commerce\Fulfilment;
use App\Commerce\Partners\Commissions;
use App\Commerce\PlatformSales;
use App\Commerce\Revenue;
use App\Commerce\SalesReport;
use App\Models\DiscountCode;
use App\Models\OrderItem;
use App\Models\PartnerCommission;

/*
 * The commission arithmetic: rate × the item's net (after discount and its
 * share of the payment fee), rounded half up in integer cents, out of the
 * seller's royalty, never the platform's share — and the parts add up.
 */

beforeEach(fn () => partnerProgram());

it('rounds half up in integer cents', function () {
    expect(Commissions::amount(1135, 2500))->toBe(284)   // 283.75
        ->and(Commissions::amount(1000, 2500))->toBe(250)
        ->and(Commissions::amount(2, 2500))->toBe(1)       // 0.5 → 1
        ->and(Commissions::amount(1, 2500))->toBe(0)       // 0.25 → 0
        ->and(Commissions::amount(0, 2500))->toBe(0)
        ->and(Commissions::amount(-50, 2500))->toBe(0)
        ->and(Commissions::amount(999, 1000))->toBe(100);  // 99.9
});

it('takes the commission out of the royalty, so price = fee + platform + royalty + commission', function (int $price, int $bps, float $platform) {
    config(['commerce.platform_rate' => $platform]);

    $fee = Revenue::fee($price);
    $split = Commissions::split($price, $fee, $bps);

    expect($split['fee'] + $split['platform'] + $split['royalty'] + $split['commission'])->toBe($price)
        // The platform's share is what it would be with no partner.
        ->and($split['platform'])->toBe(($price - $fee) - Revenue::royalty($price, $fee))
        ->and($split['royalty'] + $split['commission'])->toBe(Revenue::royalty($price, $fee));
})->with([
    [1200, 2500, 0.0],
    [999, 1000, 0.0],
    [50, 5000, 0.0],
    [100000, 3333, 0.15],
    [1299, 2500, 0.3],
]);

it('never lets the commission exceed what the seller would have earned', function () {
    config(['commerce.platform_rate' => 0.7]);

    $fee = Revenue::fee(2000);
    $split = Commissions::split(2000, $fee, 5000);

    expect($split['commission'])->toBe(Revenue::royalty(2000, $fee))
        ->and($split['royalty'])->toBe(0);
});

it('works out a 12.00 sale through a 25 % partner, and stores it on the item and the row', function () {
    $partner = partnerFor();
    $product = partnerProduct(priceCents: 1200, rate: 25);

    $order = partnerBuy($product, cookie: $partner);
    $item = $order->items->first();
    $row = PartnerCommission::sole();

    expect($order->status)->toBe('paid')
        ->and($item->fee_cents)->toBe(65)
        ->and($item->partner_rate_bps)->toBe(2500)
        ->and($item->partner_commission_cents)->toBe(284)
        ->and($item->royalty_cents)->toBe(1135 - 284)
        ->and($row->only(['partner_id', 'order_item_id', 'kind', 'rate_bps', 'base_cents', 'amount_cents', 'status', 'source', 'currency']))->toBe([
            'partner_id' => $partner->id,
            'order_item_id' => $item->id,
            'kind' => 'sale',
            'rate_bps' => 2500,
            'base_cents' => 1135,
            'amount_cents' => 284,
            'status' => 'pending',
            'source' => 'cookie',
            'currency' => 'usd',
        ])
        ->and($row->hold_until->toDateString())->toBe(now()->addDays(30)->toDateString());
});

it('works the commission on what the buyer paid after a discount', function () {
    $partner = partnerFor();
    $product = partnerProduct(priceCents: 2000, rate: 20);
    $code = DiscountCode::create(['code' => 'HALF', 'kind' => 'percent', 'percent_off' => 50, 'applies_to_storefront' => true, 'active' => true]);

    $order = partnerBuy($product, cookie: $partner, code: $code->code);
    $item = $order->items->first();

    expect($item->price_cents)->toBe(1000)
        ->and($item->fee_cents)->toBe(Revenue::fee(1000))
        ->and($item->partner_commission_cents)->toBe(Commissions::amount(1000 - Revenue::fee(1000), 2000));
});

it('never recomputes history from the product’s current rate', function () {
    $partner = partnerFor();
    $product = partnerProduct(rate: 25);
    partnerBuy($product, cookie: $partner);

    $product->forceFill(['partner_rate_bps' => 5000])->save();
    partnerBuy($product, cookie: $partner);

    expect(PartnerCommission::orderBy('id')->pluck('rate_bps')->all())->toBe([2500, 5000])
        ->and(PartnerCommission::orderBy('id')->pluck('amount_cents')->all())->toBe([284, 568]);
});

it('fixes the rate when checkout starts, not when it is paid', function () {
    $partner = partnerFor();
    $product = partnerProduct(rate: 25);

    $order = partnerBuy($product, cookie: $partner, pay: false);
    $product->forceFill(['partner_rate_bps' => 1000])->save();

    app(Fulfilment::class)->checkoutCompleted([
        'id' => $order->stripe_session_id, 'payment_status' => 'paid', 'amount_total' => $order->total_cents,
        'currency' => 'usd', 'payment_intent' => 'pi_rate', 'customer_details' => ['email' => 'reader@example.com'],
    ]);

    expect(PartnerCommission::sole()->rate_bps)->toBe(2500);
});

it('splits an order’s fee across items with Revenue::allocate, so the parts add up', function () {
    $fees = Revenue::allocate(Revenue::fee(3000), [1000, 2000]);

    expect(array_sum($fees))->toBe(Revenue::fee(3000));

    $total = 0;

    foreach ([1000, 2000] as $i => $price) {
        $split = Commissions::split($price, $fees[$i], 2500);
        $total += $split['fee'] + $split['royalty'] + $split['commission'] + $split['platform'];
    }

    expect($total)->toBe(3000);
});

it('shrinks a commission in proportion to a refund, like the royalty', function () {
    $item = (new OrderItem)->forceFill(['price_cents' => 1200, 'fee_cents' => 65, 'royalty_cents' => 851, 'partner_commission_cents' => 284, 'refunded_cents' => 600]);

    // 1135 net; 535 left after a 6.00 refund.
    expect(Commissions::earned($item))->toBe((int) round(284 * 535 / 1135))
        ->and(Revenue::earned($item))->toBe((int) round(851 * 535 / 1135));

    $item->refunded_cents = 1200;
    expect(Commissions::earned($item))->toBe(0);
});

it('keeps the platform’s share whole in the console, and shows commissions on their own', function () {
    $partner = partnerFor();
    $product = partnerProduct(rate: 25);
    partnerBuy($product, cookie: $partner);

    $usd = collect(app(PlatformSales::class)->totals())->firstWhere('currency', 'usd');

    expect($usd['partner_cents'])->toBe(284)
        ->and($usd['royalty_cents'])->toBe(851)
        ->and($usd['platform_cents'])->toBe(0)
        ->and($usd['royalty_cents'] + $usd['partner_cents'] + $usd['fee_cents'])->toBe(1200);
});

it('shows the seller partner commissions as their own line, in the report and the statement', function () {
    $partner = partnerFor();
    $product = partnerProduct(rate: 25);
    partnerBuy($product, cookie: $partner);
    partnerBuy($product);

    $report = SalesReport::for($product->user);
    $totals = collect($report['totals'])->firstWhere('currency', 'usd');

    expect($totals['partner_cents'])->toBe(284)
        ->and($totals['partner_units'])->toBe(1)
        ->and($totals['royalty_cents'])->toBe(851 + 1135);

    $month = SalesReport::month($product->user, now())['usd'];
    $line = collect($month['lines'])->firstWhere('title', 'Partner commissions');

    expect($line['royalty_cents'])->toBe(-284)
        ->and($line['units'])->toBe(0)
        ->and($month['units'])->toBe(2)
        // The book line is before the commission, so the lines add up.
        ->and($month['royalty_cents'])->toBe(851 + 1135);
});
