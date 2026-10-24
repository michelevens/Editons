<?php

use App\Billing\PlanCatalog;
use App\Commerce\Fulfilment;
use App\Commerce\Partners\Handles;
use App\Commerce\Partners\Program;
use App\Commerce\Revenue;
use App\Editorial\Contracts\EditorialDriver;
use App\Editorial\Drivers\FakeDriver;
use App\Editorial\EditorialAgent;
use App\Editorial\ReviewResult;
use App\Models\Book;
use App\Models\Order;
use App\Models\Partner;
use App\Models\Plan;
use App\Models\Product;
use App\Models\User;
use App\Platform\Features;
use App\Platform\TwoFactor;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Bind an editorial driver that is not sample output.
 *
 * The suite runs on FakeDriver, whose reviews are never charged. Tests about
 * charging for a review need a driver that stands in for a real provider:
 * this one gives FakeDriver's findings under another name, without the
 * SimulatesReview marker.
 */
function useChargingDriver(): void
{
    app()->bind(EditorialDriver::class, fn () => new class implements EditorialDriver
    {
        public function review(EditorialAgent $agent, string $text): ReviewResult
        {
            return (new FakeDriver)->review($agent, $text);
        }

        public function name(): string
        {
            return 'test-provider';
        }
    });
}

/**
 * A book whose author has confirmed the rights declaration and answered the
 * AI-content questions, as Prepare for stores requires: for tests about
 * something else that need a book that can be submitted.
 */
function declared(Book $book): Book
{
    $book->forceFill([
        'rights_declaration' => ['holds_rights' => true, 'materials' => [], 'confirmed_by' => $book->user_id, 'confirmed_name' => 'Test', 'confirmed_at' => now()->toIso8601String()],
        'ai_disclosure' => ['answers' => ['text' => 'none', 'images' => 'none', 'translations' => 'none'], 'confirmed_by' => $book->user_id, 'confirmed_name' => 'Test', 'confirmed_at' => now()->toIso8601String()],
    ])->save();

    return $book->fresh();
}

/**
 * A platform staff account for the admin console's tests, with two-factor
 * confirmed unless asked otherwise. Never created by a seeder (docs/ADMIN.md);
 * only here and by `php artisan platform:admin`.
 */
function platformAdmin(string $role = 'owner', bool $twoFactor = true): User
{
    $user = User::factory()->create();
    $user->forceFill(['platform_role' => $role])->save();

    if ($twoFactor) {
        app(TwoFactor::class)->begin($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    }

    return $user->fresh();
}

/** A one-paragraph ProseMirror document. */
function platformDoc(string $text): array
{
    return ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]],
    ]];
}

/**
 * Change a plan in the catalogue (App\Billing\PlanCatalog), the way the
 * owner's console does: the limits read the plans table, not the config.
 */
function setPlan(string $key, array $changes): void
{
    Plan::where('key', $key)->firstOrFail()->forceFill($changes)->save();
    app(PlanCatalog::class)->forget();
}

/**
 * The console's business tests: a Cashier subscription row, as Stripe's
 * webhooks would have left it.
 */
function bizSubscription(User $user, string $status = 'active', ?string $price = 'price_author', array $attributes = []): int
{
    return DB::table('subscriptions')->insertGetId($attributes + [
        'user_id' => $user->id,
        'type' => 'default',
        'stripe_id' => 'sub_'.Str::random(14),
        'stripe_status' => $status,
        'stripe_price' => $price,
        'quantity' => 1,
        'created_at' => now()->subMonths(2),
        'updated_at' => now(),
    ]);
}

/** A paid storefront order of one item, with its fee and royalty worked out as Fulfilment does. */
function bizPaidOrder(User $seller, int $priceCents, string $currency = 'usd', array $order = [], ?Book $book = null): Order
{
    $book ??= Book::factory()->for($seller)->create();
    $fee = Revenue::fee($priceCents);

    $model = new Order;
    $model->forceFill($order + [
        'number' => Order::newNumber(),
        'status' => 'paid',
        'buyer_email' => 'reader'.Str::random(5).'@example.com',
        'currency' => $currency,
        'total_cents' => $priceCents,
        'fee_cents' => $fee,
        'stripe_payment_intent' => 'pi_test_'.Str::random(12),
        'paid_at' => now(),
    ])->save();

    $model->items()->make()->forceFill([
        'book_id' => $book->id,
        'seller_id' => $seller->id,
        'title' => $book->title,
        'formats' => ['epub'],
        'license_type' => 'personal',
        'price_cents' => $priceCents,
        'fee_cents' => $fee,
        'royalty_cents' => Revenue::royalty($priceCents, $fee),
    ])->save();

    return $model->fresh('items');
}

/** A saved version of a new chapter, by $user, as the editor's autosave writes one. */
function bizWrote(User $user, ?Book $book = null, $at = null): Book
{
    $book ??= Book::factory()->for($user)->create();
    $section = $book->sections()->create(['type' => 'chapter', 'title' => 'One', 'position' => 10]);

    DB::table('manuscript_versions')->insert([
        'manuscript_section_id' => $section->id,
        'user_id' => $user->id,
        'version_number' => 1,
        'title' => 'One',
        'content' => json_encode(platformDoc('Hello')),
        'word_count' => 1,
        'created_at' => $at ?? now(),
    ]);

    return $book;
}

/*
 | Écrivain Partners (tests/Feature/Partners). Commerce on the fake gateway,
 | Stripe's standard fee, no platform share: a 12.00 sale has a 0.65 fee,
 | a 11.35 net, and a 25 % partner earns 2.84 of it.
 */
function partnerProgram(): void
{
    config([
        'commerce.enabled' => true,
        'commerce.gateway' => 'fake',
        'commerce.stripe_fee' => ['percent' => 2.9, 'fixed_cents' => 30],
        'commerce.platform_rate' => 0.0,
        'partners.default_on' => true,
    ]);
    app(Features::class)->flush();
    app(Program::class)->flush();
}

/** An approved partner, with an account of their own. */
function partnerFor(?User $user = null, array $attributes = []): Partner
{
    $user ??= User::factory()->create();
    $partner = new Partner;
    $partner->forceFill($attributes + [
        'user_id' => $user->id,
        'code' => Partner::newCode(),
        'handle' => Handles::suggest($user->name),
        'status' => 'approved',
        'display_name' => $user->name,
        'promotion_type' => 'school',
        'agreement_version' => config('partners.agreement_version'),
        'agreement_accepted_at' => now(),
        'approved_at' => now(),
        'payout_method' => 'moncash',
        'payout_details' => '+509 3700 0000',
    ])->save();

    return $partner->fresh('user');
}

/** A book on sale, opted in to partners at $rate %. */
function partnerProduct(?User $seller = null, int $priceCents = 1200, ?int $rate = 25, array $attributes = []): Product
{
    $seller ??= User::factory()->create();
    $book = Book::factory()->for($seller)->create();
    $product = new Product;
    $product->forceFill($attributes + [
        'user_id' => $seller->id,
        'book_id' => $book->id,
        'slug' => Product::newSlug($book->title),
        'price_cents' => $priceCents,
        'currency' => 'usd',
        'formats' => ['epub'],
        'license' => 'personal',
        'published_at' => now()->subDay(),
        'partner_enabled' => $rate !== null,
        'partner_rate_bps' => $rate !== null ? $rate * 100 : null,
    ])->save();

    return $product->fresh(['book', 'user']);
}

/** The partner cookie a visit through $partner's link leaves. */
function partnerCookie(Partner $partner, ?string $at = null): string
{
    return json_encode(['p' => $partner->id, 'at' => $at ?? now()->toIso8601String(), 'via' => 'link']);
}

/**
 * Start a checkout as the store would (with an optional partner cookie and
 * discount code), then complete it the way Stripe's webhook does.
 */
function partnerBuy(Product $product, ?Partner $cookie = null, ?string $code = null, string $email = 'reader@example.com', ?User $as = null, bool $pay = true): Order
{
    $test = test();
    $test->flushSession();

    if ($as) {
        $test->actingAs($as);
    }

    // Passed to this one request only (withCookie would stick to the test).
    $cookies = $cookie ? [config('partners.cookie') => partnerEncryptedCookie(config('partners.cookie'), partnerCookie($cookie))] : [];
    $test->call('POST', "/store/{$product->slug}/checkout", array_filter(['email' => $email, 'code' => $code]), $cookies)->assertRedirect();

    $order = Order::latest('id')->firstOrFail();

    if ($pay) {
        app(Fulfilment::class)->checkoutCompleted([
            'id' => $order->stripe_session_id,
            'payment_status' => 'paid',
            'amount_total' => $order->total_cents,
            'currency' => $order->currency,
            'payment_intent' => 'pi_'.$order->id.Str::random(6),
            'customer_details' => ['email' => $email, 'name' => 'Jonas Pierre', 'address' => ['country' => 'CA']],
            'metadata' => ['order_id' => (string) $order->id],
        ]);
    }

    if ($as) {
        auth()->logout();
    }

    return $order->fresh('items');
}

/** A cookie as the browser would send it back: encrypted like EncryptCookies does. */
function partnerEncryptedCookie(string $name, string $value): string
{
    return encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$value, false);
}

/** A Stripe charge.refunded for $order, cumulative as Stripe sends it. */
function partnerRefund(Order $order, int $cumulativeCents): void
{
    app(Fulfilment::class)->chargeRefunded([
        'payment_intent' => $order->stripe_payment_intent,
        'amount_refunded' => $cumulativeCents,
        'metadata' => ['order_id' => (string) $order->id],
    ]);
}
