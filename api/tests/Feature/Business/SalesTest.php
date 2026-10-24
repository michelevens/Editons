<?php

use App\Commerce\PlatformSales;
use App\Commerce\Revenue;
use App\Models\AdminAuditLog;
use App\Models\Payout;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Book sales across the platform: totals per currency, royalties owed from
 * the same arithmetic the authors' statements use, payouts recorded by hand,
 * masked buyers.
 */

beforeEach(function () {
    config(['commerce.stripe_fee' => ['percent' => 2.9, 'fixed_cents' => 30], 'commerce.platform_rate' => 0.0]);
});

it('totals per currency, never across, with fees and royalties as the statements work them out', function () {
    $ada = User::factory()->create();
    bizPaidOrder($ada, 1000);
    bizPaidOrder($ada, 2000);
    bizPaidOrder($ada, 1500, 'eur');

    $totals = collect(app(PlatformSales::class)->totals())->keyBy('currency');
    $usdFee = Revenue::fee(1000) + Revenue::fee(2000);

    expect($totals['usd'])->toMatchArray([
        'gross_cents' => 3000,
        'fee_cents' => $usdFee,
        'net_cents' => 3000 - $usdFee,
        'royalty_cents' => 3000 - $usdFee,
        'platform_cents' => 0,
        'units' => 2,
    ])->and($totals['eur']['gross_cents'])->toBe(1500);
});

it('owes an author what they earned less what was paid, and shrinks it after a refund', function () {
    $ada = User::factory()->create();
    $bob = User::factory()->create();
    $refunded = bizPaidOrder($ada, 1000);
    bizPaidOrder($ada, 1000);
    bizPaidOrder($bob, 500);

    // Half of one sale given back.
    $item = $refunded->items->first();
    $item->forceFill(['refunded_cents' => 500])->save();
    $refunded->forceFill(['refunded_cents' => 500, 'status' => 'partially_refunded'])->save();

    $earned = Revenue::earned($item->fresh()) + Revenue::royalty(1000, Revenue::fee(1000));
    $sales = app(PlatformSales::class);

    expect($sales->owed($ada, 'usd'))->toBe($earned);

    $this->actingAs(platformAdmin())->post(route('admin.sales.payout', $ada), [
        'currency' => 'usd', 'amount' => '10.00', 'method' => 'moncash', 'reference' => 'MC-881', 'paid_on' => now()->toDateString(), 'reason' => 'October royalties',
    ])->assertSessionHas('platform_notice');

    $row = collect($sales->royalties())->firstWhere('account.id', $ada->id);
    expect($row)->toMatchArray(['earned_cents' => $earned, 'paid_cents' => 1000, 'owed_cents' => $earned - 1000])
        ->and(AdminAuditLog::where('action', 'payout.recorded')->sole()->after)->toMatchArray(['amount_cents' => 1000, 'method' => 'moncash', 'owed_cents' => $earned - 1000]);
});

it('refuses to record more than is owed', function () {
    $ada = User::factory()->create();
    bizPaidOrder($ada, 1000);
    $owed = app(PlatformSales::class)->owed($ada, 'usd');

    $this->actingAs(platformAdmin())->post(route('admin.sales.payout', $ada), [
        'currency' => 'usd', 'amount' => ($owed + 1) / 100, 'method' => 'bank_transfer', 'paid_on' => now()->toDateString(), 'reason' => 'Overpay',
    ])->assertSessionHasErrors('amount');

    $this->actingAs(platformAdmin())->post(route('admin.sales.payout', $ada), [
        'currency' => 'eur', 'amount' => 1, 'method' => 'bank_transfer', 'paid_on' => now()->toDateString(), 'reason' => 'Wrong currency',
    ])->assertSessionHasErrors('amount');

    expect(Payout::count())->toBe(0);
});

it('lists orders with masked buyers, best sellers and the 30-day chart', function () {
    $ada = User::factory()->create(['name' => 'Ada Laurent']);
    bizPaidOrder($ada, 1200, 'usd', ['buyer_email' => 'jonas.pierre@example.com']);
    bizPaidOrder($ada, 800, 'usd', ['paid_at' => now()->subDays(40)]);

    $this->actingAs(platformAdmin('support'))->get('/admin/sales')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Business/Sales')
            ->has('orders.data', 2)
            ->where('orders.data.0.buyer', 'j•••@example.com')
            ->where('top.books.0.units', 1)
            ->where('top.authors.0.author.name', 'Ada Laurent')
            ->has('daily.days', 30)
            ->where('daily.days.29.gross_cents', 1200)
            ->where('can_edit', false));
});

it('keeps payouts from support', function () {
    $ada = User::factory()->create();
    bizPaidOrder($ada, 1000);

    $this->actingAs(platformAdmin('support'))->post(route('admin.sales.payout', $ada), [
        'currency' => 'usd', 'amount' => 1, 'method' => 'other', 'paid_on' => now()->toDateString(), 'reason' => 'Try',
    ])->assertForbidden();
});
