<?php

use App\Commerce\Partners\Attribution;
use App\Commerce\Payouts;
use App\Commerce\PlatformSales;
use App\Commerce\Revenue;
use App\Models\Book;
use App\Models\PartnerCommission;
use App\Models\RoyaltyStatement;
use App\Models\User;
use App\Platform\House\HouseAccounts;
use App\Platform\House\HouseTransfer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/*
 * House books' money is the platform's: their royalty share is platform
 * revenue, nobody is owed or paid for them, and no statement is sent. A
 * partner's commission on a house book still works.
 */

beforeEach(function () {
    config(['commerce.stripe_fee' => ['percent' => 2.9, 'fixed_cents' => 30], 'commerce.platform_rate' => 0.0]);
    $this->house = app(HouseAccounts::class)->create('ecrivain-prepa', 'Écrivain Prépa', 'Test imprint');
});

it('counts a house sale\'s royalty as platform revenue and owes it to nobody', function () {
    $author = User::factory()->create();
    bizPaidOrder($author, 1000);
    bizPaidOrder($this->house, 1000);

    $sales = app(PlatformSales::class);
    $usd = collect($sales->totals())->firstWhere('currency', 'usd');
    $royalty = Revenue::royalty(1000, Revenue::fee(1000));

    expect($usd['royalty_cents'])->toBe($royalty)
        ->and($usd['house_cents'])->toBe($royalty)
        ->and($usd['platform_cents'])->toBe($usd['net_cents'] - $royalty);

    $owed = collect($sales->royalties());
    expect($owed->pluck('account.id')->all())->toBe([$author->id])
        ->and($sales->owed($this->house, 'usd'))->toBe(0);

    expect(fn () => app(Payouts::class)->record($this->house, [
        'currency' => 'usd', 'amount_cents' => 100, 'method' => 'bank_transfer', 'reference' => null, 'paid_on' => now()->toDateString(), 'note' => null,
    ], 'Pay the imprint', platformAdmin()))->toThrow(ValidationException::class);
});

it('keeps sales made before a transfer as the author\'s royalty', function () {
    $author = User::factory()->create();
    $book = Book::factory()->for($author)->create();
    bizPaidOrder($author, 1000, book: $book);

    app(HouseTransfer::class)->apply([$book->id], $this->house, 'Move', platformAdmin());
    bizPaidOrder($this->house, 1000, book: $book);

    $usd = collect(app(PlatformSales::class)->totals())->firstWhere('currency', 'usd');
    $royalty = Revenue::royalty(1000, Revenue::fee(1000));

    expect($usd['royalty_cents'])->toBe($royalty)
        ->and($usd['house_cents'])->toBe($royalty)
        ->and(app(PlatformSales::class)->owed($author, 'usd'))->toBe($royalty);
});

it('sends no royalty statement for a house account', function () {
    Mail::fake();
    $author = User::factory()->create();
    bizPaidOrder($author, 1000, order: ['paid_at' => now()->subMonthNoOverflow()->startOfMonth()->addDays(3)]);
    bizPaidOrder($this->house, 1000, order: ['paid_at' => now()->subMonthNoOverflow()->startOfMonth()->addDays(3)]);

    $this->artisan('commerce:royalty-statements')->assertSuccessful();

    expect(RoyaltyStatement::pluck('user_id')->all())->toBe([$author->id]);
});

it('pays a partner commission on a house book, with the house account as the seller', function () {
    partnerProgram();
    $product = partnerProduct($this->house, 1200, 25);
    $partner = partnerFor();

    $order = partnerBuy($product, $partner);

    $commission = PartnerCommission::where('order_id', $order->id)->sole();
    expect($commission->seller_id)->toBe($this->house->id)
        ->and($commission->amount_cents)->toBeGreaterThan(0)
        ->and($order->items->first()->seller_id)->toBe($this->house->id);
});

it('pays no commission to platform staff on a house book', function () {
    partnerProgram();
    $product = partnerProduct($this->house, 1200, 25);
    $staffPartner = partnerFor(platformAdmin('support'));

    expect(Attribution::refusal($staffPartner, $product, null, 'reader@example.com'))->toBe('seller');

    $authorProduct = partnerProduct(User::factory()->create(), 1200, 25);
    expect(Attribution::refusal($staffPartner, $authorProduct, null, 'reader@example.com'))->toBeNull();
});
