<?php

use App\Commerce\Partners\Ledger;
use App\Commerce\PlatformSales;
use App\Mail\Partners\PartnerFirstSaleMail;
use App\Mail\Partners\PartnerPayoutMail;
use App\Mail\Partners\PartnerStatementMail;
use App\Models\AdminAuditLog;
use App\Models\InboxItem;
use App\Models\Partner;
use App\Models\PartnerCommission;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/*
 * A commission's life: pending on payment, approved after the hold, paid
 * by a recorded payout; refunds before and after; suspension freezing the
 * balance; one ledger with the authors' royalty payouts.
 */

beforeEach(function () {
    partnerProgram();
    Mail::fake();
});

/** Pay the partner what they are owed, as the owner does. */
function partnerPay(Partner $partner, int $cents, ?User $owner = null)
{
    return test()->actingAs($owner ?? platformAdmin())->post(route('admin.partners.payout', $partner), [
        'currency' => 'usd', 'amount' => number_format($cents / 100, 2, '.', ''), 'method' => 'moncash',
        'reference' => 'MC-1', 'paid_on' => now()->toDateString(), 'reason' => 'Monthly partner payout',
    ]);
}

it('is pending on payment, approved once the hold is over, and tells the partner once', function () {
    $partner = partnerFor();
    $product = partnerProduct();
    partnerBuy($product, cookie: $partner);
    partnerBuy($product, cookie: $partner, email: 'second@example.com');

    Mail::assertQueued(PartnerFirstSaleMail::class, 1);
    expect(InboxItem::where('user_id', $partner->user_id)->where('kind', 'partner')->count())->toBe(2);

    $this->artisan('partners:release-commissions')->assertSuccessful();
    expect(PartnerCommission::where('status', 'pending')->count())->toBe(2);

    $this->travel(31)->days();
    $this->artisan('partners:release-commissions')->expectsOutput('Approved 2 commission(s).')->assertSuccessful();
    $this->artisan('partners:release-commissions')->expectsOutput('Approved 0 commission(s).');

    expect(app(Ledger::class)->balance($partner, 'usd'))->toBe(568);
});

it('reverses a commission refunded in full during the hold', function () {
    $partner = partnerFor();
    $order = partnerBuy(partnerProduct(), cookie: $partner);

    partnerRefund($order, 1200);
    $row = PartnerCommission::sole();

    expect($row->status)->toBe('reversed')
        ->and($row->reversed_cents)->toBe(284)
        ->and($row->net())->toBe(0);

    $this->travel(31)->days();
    $this->artisan('partners:release-commissions');
    expect($row->fresh()->status)->toBe('reversed');
});

it('reduces a commission in proportion to a partial refund, and a repeat changes nothing', function () {
    $partner = partnerFor();
    $order = partnerBuy(partnerProduct(), cookie: $partner);

    partnerRefund($order, 600);
    partnerRefund($order, 600);
    $row = PartnerCommission::sole();
    $expected = (int) round(284 * (1200 - 600 - 65) / 1135);

    expect($row->status)->toBe('pending')
        ->and($row->net())->toBe($expected)
        ->and(PartnerCommission::count())->toBe(1);
});

it('carries a refund after payout into the balance as a negative adjustment', function () {
    $partner = partnerFor();
    $product = partnerProduct(priceCents: 12000);
    $order = partnerBuy($product, cookie: $partner);
    $this->travel(31)->days();
    $this->artisan('partners:release-commissions');

    $earned = PartnerCommission::sole()->amount_cents;
    partnerPay($partner, $earned)->assertSessionHas('platform_notice');

    expect(PartnerCommission::sole()->status)->toBe('paid')
        ->and(app(Ledger::class)->balance($partner, 'usd'))->toBe(0);

    partnerRefund($order, 12000);

    $adjustment = PartnerCommission::where('kind', 'adjustment')->sole();
    expect($adjustment->amount_cents)->toBe(-$earned)
        ->and($adjustment->status)->toBe('approved')
        ->and(app(Ledger::class)->balance($partner, 'usd'))->toBe(-$earned);

    // Paid back out of the next earnings.
    partnerBuy($product, cookie: $partner, email: 'next@example.com');
    $this->travel(31)->days();
    $this->artisan('partners:release-commissions');

    expect(app(Ledger::class)->balance($partner, 'usd'))->toBe(0);
});

it('refuses a payout under the minimum, over the balance, or without payout details', function () {
    $partner = partnerFor();
    partnerBuy(partnerProduct(priceCents: 1200), cookie: $partner);
    $this->travel(31)->days();
    $this->artisan('partners:release-commissions');

    partnerPay($partner, 284)->assertSessionHasErrors('amount');
    expect(Payout::count())->toBe(0);

    $big = partnerFor();
    partnerBuy(partnerProduct(priceCents: 20000), cookie: $big, email: 'b@example.com');
    $this->travel(31)->days();
    $this->artisan('partners:release-commissions');
    $balance = app(Ledger::class)->balance($big, 'usd');

    partnerPay($big, $balance + 1)->assertSessionHasErrors('amount');
    expect(app(Ledger::class)->eligibility($big, 'usd')['eligible'])->toBeTrue();

    $big->forceFill(['payout_details' => null])->save();
    expect(app(Ledger::class)->eligibility($big, 'usd'))->toMatchArray(['eligible' => false]);
});

it('records a payout in the one payouts ledger, audited and emailed, without touching royalties owed', function () {
    $partner = partnerFor();
    $product = partnerProduct(priceCents: 20000);
    partnerBuy($product, cookie: $partner);
    $this->travel(31)->days();
    $this->artisan('partners:release-commissions');

    $sellerOwed = app(PlatformSales::class)->owed($product->user, 'usd');
    $balance = app(Ledger::class)->balance($partner, 'usd');

    partnerPay($partner, $balance);

    $payout = Payout::sole();
    expect($payout->kind)->toBe('partner')
        ->and($payout->user_id)->toBe($partner->user_id)
        ->and($payout->amount_cents)->toBe($balance)
        ->and(app(PlatformSales::class)->owed($product->user, 'usd'))->toBe($sellerOwed)
        ->and(collect(app(PlatformSales::class)->payouts()))->toHaveCount(0)
        ->and(AdminAuditLog::where('action', 'partner.payout_recorded')->sole()->reason)->toBe('Monthly partner payout');

    Mail::assertQueued(PartnerPayoutMail::class);
});

it('freezes a suspended partner’s balance until staff release or forfeit it', function () {
    $owner = platformAdmin();
    $partner = partnerFor();
    partnerBuy(partnerProduct(priceCents: 20000), cookie: $partner);
    $this->travel(31)->days();
    $this->artisan('partners:release-commissions');
    $balance = app(Ledger::class)->balance($partner, 'usd');

    $this->actingAs($owner)->post(route('admin.partners.suspend', $partner), ['reason' => 'Spam on WhatsApp groups'])->assertSessionHas('platform_notice');
    expect($partner->fresh()->balanceFrozen())->toBeTrue();

    partnerPay($partner, $balance, $owner)->assertSessionHasErrors('amount');

    $this->actingAs($owner)->post(route('admin.partners.release', $partner), ['reason' => 'Reviewed: sales were genuine'])->assertSessionHas('platform_notice');
    partnerPay($partner, $balance, $owner)->assertSessionHas('platform_notice');
    expect(Payout::count())->toBe(1);
});

it('forfeits a suspended partner’s balance, pending included, with a reason', function () {
    $owner = platformAdmin();
    $partner = partnerFor();
    $product = partnerProduct(priceCents: 20000);
    partnerBuy($product, cookie: $partner);
    $this->travel(31)->days();
    $this->artisan('partners:release-commissions');
    partnerBuy($product, cookie: $partner, email: 'later@example.com');

    $this->actingAs($owner)->post(route('admin.partners.suspend', $partner), ['reason' => 'Fake orders']);
    $this->actingAs($owner)->post(route('admin.partners.forfeit', $partner), ['reason' => 'Self-referral ring confirmed'])->assertSessionHas('platform_notice');

    $balances = app(Ledger::class)->balances($partner->fresh())['usd'];
    expect($balances['balance_cents'])->toBe(0)
        ->and($balances['pending_cents'])->toBe(0)
        ->and(PartnerCommission::where('kind', 'forfeit')->sole()->note)->toBe('Self-referral ring confirmed')
        ->and(AdminAuditLog::where('action', 'partner.balance_forfeited')->exists())->toBeTrue();
});

it('sends a monthly statement only to partners with activity, once', function () {
    $active = partnerFor();
    partnerFor();
    partnerBuy(partnerProduct(), cookie: $active);

    $this->travel(1)->months();
    $this->artisan('partners:statements')->assertSuccessful();
    $this->artisan('partners:statements')->assertSuccessful();

    Mail::assertQueued(PartnerStatementMail::class, 1);
    Mail::assertQueued(PartnerStatementMail::class, fn ($mail) => $mail->partner->is($active) && $mail->lines[0]['amount_cents'] === 284);
});
