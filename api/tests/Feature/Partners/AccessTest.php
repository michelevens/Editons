<?php

use App\Commerce\Partners\Program;
use App\Mail\Partners\PartnerApplicationReceivedMail;
use App\Mail\Partners\PartnerApprovedMail;
use App\Mail\Partners\PartnerDeclinedMail;
use App\Mail\Partners\PartnerInvitationMail;
use App\Mail\Partners\PartnerSuspendedMail;
use App\Models\AdminAuditLog;
use App\Models\DiscountCode;
use App\Models\Partner;
use App\Models\User;
use App\Platform\Features;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Who can do what: guests, authors, partners, support and the owner; the
 * application and the console's decisions; the seller's switch; the
 * author's invitation; the feature flag.
 */

beforeEach(function () {
    partnerProgram();
    Mail::fake();
});

function partnerApplication(): array
{
    return [
        'display_name' => 'Lekòl Sen Jozèf',
        'country' => 'ht',
        'promotion_type' => 'school',
        'promotion_note' => 'Philo class reading lists',
        'links' => ['https://lekol.example', '', ''],
        'agreement_version' => config('partners.agreement_version'),
        'accept' => true,
    ];
}

it('sends guests to sign in, and an author without a partner record to the application', function () {
    $this->get(route('partners.dashboard'))->assertRedirect(route('login'));
    $this->get(route('partners.apply'))->assertRedirect(route('login'));

    $author = User::factory()->create();
    $this->actingAs($author)->get(route('partners.dashboard'))->assertRedirect(route('partners.apply'));
    $this->actingAs($author)->get(route('partners.apply'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('Partners/Apply'));
});

it('takes an application with the agreement version and time, and tells the applicant', function () {
    $author = User::factory()->create();

    $this->actingAs($author)->post(route('partners.apply.store'), partnerApplication())->assertRedirect(route('partners.dashboard'));

    $partner = Partner::sole();
    expect($partner->status)->toBe('applied')
        ->and($partner->handle)->toBe('lekol-sen-jozef')
        ->and($partner->country)->toBe('HT')
        ->and($partner->links)->toBe(['https://lekol.example'])
        ->and($partner->agreement_version)->toBe(config('partners.agreement_version'))
        ->and($partner->agreement_accepted_at)->not->toBeNull();

    Mail::assertQueued(PartnerApplicationReceivedMail::class);

    // Not twice.
    $this->actingAs($author)->post(route('partners.apply.store'), partnerApplication())->assertSessionHasErrors('display_name');
});

it('refuses an application without the current agreement, or with non-https links', function () {
    $author = User::factory()->create();

    $this->actingAs($author)->post(route('partners.apply.store'), ['accept' => false] + partnerApplication())->assertSessionHasErrors('accept');
    $this->actingAs($author)->post(route('partners.apply.store'), ['agreement_version' => '0'] + partnerApplication())->assertSessionHasErrors('agreement');
    $this->actingAs($author)->post(route('partners.apply.store'), ['links' => ['http://x.example']] + partnerApplication())->assertSessionHasErrors('links.0');

    expect(Partner::count())->toBe(0);
});

it('lets support approve and decline, audited, and the applicant is told', function () {
    $support = platformAdmin('support');
    $one = partnerFor(attributes: ['status' => 'applied']);
    $two = partnerFor(attributes: ['status' => 'applied']);

    $this->actingAs($support)->get(route('admin.partners.index'))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Admin/Business/Partners')->has('applications', 2)->where('can_edit', false));

    $this->actingAs($support)->post(route('admin.partners.approve', $one))->assertSessionHas('platform_notice');
    $this->actingAs($support)->post(route('admin.partners.decline', $two), ['reason' => 'No links to check'])->assertSessionHas('platform_notice');

    expect($one->fresh()->status)->toBe('approved')
        ->and($two->fresh()->status)->toBe('declined')
        ->and($two->fresh()->decline_reason)->toBe('No links to check')
        ->and(AdminAuditLog::whereIn('action', ['partner.approved', 'partner.declined'])->count())->toBe(2);

    Mail::assertQueued(PartnerApprovedMail::class);
    Mail::assertQueued(PartnerDeclinedMail::class, fn ($m) => $m->reason === 'No links to check');

    // A declined applicant may apply again.
    $this->actingAs($two->user)->post(route('partners.apply.store'), partnerApplication())->assertRedirect();
    expect($two->fresh()->status)->toBe('applied');
});

it('keeps money, standing and settings to the owner', function () {
    $support = platformAdmin('support');
    $partner = partnerFor();

    foreach (['suspend', 'reinstate', 'release', 'forfeit', 'payout', 'code', 'tax-form', 'unrestrict'] as $action) {
        $this->actingAs($support)->post(route("admin.partners.{$action}", $partner), ['reason' => 'Trying it'])->assertForbidden();
    }

    $this->actingAs($support)->post(route('admin.partners.settings'), ['reason' => 'Trying it'])->assertForbidden();
});

it('answers 404 to anybody who is not staff, guests included', function () {
    $partner = partnerFor();

    $this->get(route('admin.partners.index'))->assertNotFound();
    $this->actingAs($partner->user)->get(route('admin.partners.show', $partner))->assertNotFound();
    $this->actingAs($partner->user)->post(route('admin.partners.approve', $partner))->assertNotFound();
});

it('lets the owner suspend, attach a code, mark a tax form and change settings, each audited', function () {
    $owner = platformAdmin();
    $partner = partnerFor();
    $code = DiscountCode::create(['code' => 'LEKOL', 'kind' => 'percent', 'percent_off' => 5, 'applies_to_storefront' => true, 'active' => true]);

    $this->actingAs($owner)->post(route('admin.partners.code', $partner), ['discount_code_id' => $code->id, 'reason' => 'Flyers for the school'])->assertSessionHas('platform_notice');
    $this->actingAs($owner)->post(route('admin.partners.tax-form', $partner), ['received' => true, 'reason' => 'W-8BEN by email'])->assertSessionHas('platform_notice');
    $this->actingAs($owner)->post(route('admin.partners.suspend', $partner), ['reason' => 'Reported page upheld'])->assertSessionHas('platform_notice');
    $this->actingAs($owner)->post(route('admin.partners.settings'), [
        'default_rate' => 30, 'min_rate' => 10, 'max_rate' => 50, 'hold_days' => 14, 'cookie_days' => 30, 'payout_minimum' => '20.00', 'reason' => 'Shorter hold for the launch',
    ])->assertSessionHasNoErrors();

    expect($code->fresh()->partner_id)->toBe($partner->id)
        ->and($partner->fresh()->tax_form_received_at)->not->toBeNull()
        ->and($partner->fresh()->status)->toBe('suspended')
        ->and(app(Program::class)->get('hold_days'))->toBe(14)
        ->and(app(Program::class)->get('payout_minimum_cents'))->toBe(2000)
        ->and(AdminAuditLog::whereIn('action', ['partner.code_attached', 'partner.tax_form', 'partner.suspended', 'partners.settings'])->count())->toBe(4);

    Mail::assertQueued(PartnerSuspendedMail::class);

    $this->actingAs($owner)->post(route('admin.partners.settings'), [
        'default_rate' => 60, 'min_rate' => 10, 'max_rate' => 50, 'hold_days' => 14, 'cookie_days' => 30, 'payout_minimum' => '20.00', 'reason' => 'Oops',
    ])->assertSessionHasErrors('default_rate');
});

it('exports the commissions ledger as CSV, and records that it did', function () {
    $partner = partnerFor(attributes: ['display_name' => '=HYPERLINK("x")']);
    partnerBuy(partnerProduct(), cookie: $partner);

    $csv = $this->actingAs(platformAdmin('support'))->get(route('admin.partners.export'))->assertOk()->streamedContent();

    expect($csv)->toContain('rate_percent')
        ->and($csv)->toContain("'=HYPERLINK")
        ->and(AdminAuditLog::where('action', 'partners.commissions_exported')->exists())->toBeTrue();
});

it('gives the console a partner’s page, stats and fraud signals, and support only that details exist', function () {
    $partner = partnerFor();
    partnerBuy(partnerProduct(), cookie: $partner, as: $partner->user, email: 'x@example.com');

    $this->actingAs(platformAdmin('support'))->get(route('admin.partners.show', $partner))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Admin/Business/PartnerShow')
            ->where('fraud.self_purchase.count', 1)
            ->where('fraud.self_purchase.flag', true)
            ->where('partner.payout_details', 'On file'));

    $this->actingAs(platformAdmin())->get(route('admin.partners.show', $partner))
        ->assertInertia(fn (Assert $p) => $p->where('partner.payout_details', '+509 3700 0000'));
});

it('keeps payout details encrypted at rest, and out of what the model serialises', function () {
    $partner = partnerFor();

    $raw = DB::table('partners')->where('id', $partner->id)->value('payout_details');

    expect($raw)->not->toContain('3700')
        ->and($partner->toArray())->not->toHaveKey('payout_details');
});

it('refuses partner writes while staff "view as" the account', function () {
    $support = platformAdmin('support');
    $partner = partnerFor();

    $this->actingAs($support)->post(route('admin.accounts.impersonate', $partner->user), ['reason' => 'Helping with the page']);

    $this->put(route('partners.payout-details'), ['payout_method' => 'paypal', 'payout_details' => 'evil@example.com'])->assertForbidden();
    expect($partner->fresh()->payout_details)->toBe('+509 3700 0000');
});

it('lets a seller open a book to partners only within the program’s range, and previews the split', function () {
    Storage::fake('files');
    $product = partnerProduct(rate: null);
    $book = $product->book;
    $book->exports()->create(['format' => 'epub', 'status' => 'ready', 'path' => 'x.epub', 'byte_size' => 1]);
    $payload = fn (array $extra) => ['price' => '12.00', 'currency' => 'usd', 'formats' => ['epub'], 'license' => 'personal', 'published' => true] + $extra;

    $this->actingAs($product->user)->putJson("/books/{$book->id}/product", $payload(['partner_enabled' => true, 'partner_rate' => 60]))->assertStatus(422)->assertJsonValidationErrors('partner_rate');

    $this->actingAs($product->user)->putJson("/books/{$book->id}/product", $payload(['partner_enabled' => true, 'partner_rate' => 20]))->assertOk()
        ->assertJsonPath('product.partner_enabled', true)
        ->assertJsonPath('product.partner_rate', 20)
        ->assertJsonPath('partners.preview.without', 1135)
        ->assertJsonPath('partners.preview.commission', 227)
        ->assertJsonPath('partners.preview.with', 1135 - 227);
});

it('lets an author invite a partner, who is approved for that author’s books only', function () {
    $seller = User::factory()->create();
    partnerProduct($seller);
    $other = partnerProduct();
    $invitee = User::factory()->create(['email' => 'teacher@example.com']);

    $this->actingAs($seller)->post(route('sales.partners.invite'), ['email' => 'Teacher@Example.com'])->assertSessionHas('platform_notice');

    $token = null;
    Mail::assertQueued(PartnerInvitationMail::class, function ($mail) use (&$token) {
        $token = basename(parse_url($mail->url, PHP_URL_PATH));

        return true;
    });

    $this->get(route('partners.invitation', $token))->assertOk()->assertInertia(fn (Assert $p) => $p->component('Partners/Invitation')->where('open', true));

    $this->actingAs($invitee)->post(route('partners.invitation.accept', $token), ['agreement_version' => config('partners.agreement_version'), 'accept' => true])
        ->assertRedirect(route('partners.dashboard', ['tab' => 'page']));

    $partner = Partner::where('user_id', $invitee->id)->sole();
    auth()->logout();
    expect($partner->status)->toBe('approved')
        ->and($partner->restricted)->toBeTrue()
        ->and($partner->sellers()->pluck('users.id')->all())->toBe([$seller->id])
        ->and(partnerBuy($other, cookie: $partner)->partner_refusal)->toBe('not_permitted');

    // Used once.
    $this->actingAs($invitee->fresh())->post(route('partners.invitation.accept', $token), ['agreement_version' => config('partners.agreement_version'), 'accept' => true])
        ->assertSessionHasErrors('invitation');
});

it('refuses an invitation accepted from another address', function () {
    $seller = User::factory()->create();
    partnerProduct($seller);
    $this->actingAs($seller)->post(route('sales.partners.invite'), ['email' => 'teacher@example.com']);

    $token = null;
    Mail::assertQueued(PartnerInvitationMail::class, function ($mail) use (&$token) {
        $token = basename(parse_url($mail->url, PHP_URL_PATH));

        return true;
    });

    $this->actingAs(User::factory()->create())->post(route('partners.invitation.accept', $token), ['agreement_version' => config('partners.agreement_version'), 'accept' => true])
        ->assertSessionHasErrors('invitation');
    expect(Partner::count())->toBe(0);
});

it('shows the seller who sells their books, and lets them block and unblock', function () {
    $partner = partnerFor(attributes: ['display_name' => 'Librairie du Cap']);
    $product = partnerProduct();
    partnerBuy($product, cookie: $partner);

    $this->actingAs($product->user)->get(route('sales.overview'))
        ->assertInertia(fn (Assert $p) => $p->where('partners.partners.0.name', 'Librairie du Cap')
            ->where('partners.partners.0.sales', 1)
            ->where('partners.partners.0.blocked', false)
            ->missing('partners.partners.0.email'));

    $this->actingAs($product->user)->post(route('sales.partners.block', $partner));
    $this->actingAs($product->user)->get(route('sales.overview'))->assertInertia(fn (Assert $p) => $p->where('partners.partners.0.blocked', true));
    $this->actingAs($product->user)->delete(route('sales.partners.unblock', $partner));
    $this->actingAs($product->user)->get(route('sales.overview'))->assertInertia(fn (Assert $p) => $p->where('partners.partners.0.blocked', false));
});

it('never shows a partner who bought', function () {
    $partner = partnerFor();
    partnerBuy(partnerProduct(), cookie: $partner, email: 'private.reader@example.com');

    $this->actingAs($partner->user)->get(route('partners.dashboard', ['tab' => 'commissions']))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('commissions.0.reader', 'A reader in Canada')
            ->missing('commissions.0.buyer_email'))
        ->assertDontSee('private.reader@example.com')
        ->assertDontSee('Jonas Pierre');
});

it('closes the whole module while the flag is off', function () {
    $partner = partnerFor();
    app(Features::class)->setGlobal('partners', false, 'Not launched yet', platformAdmin());

    $this->get("/p/{$partner->handle}")->assertNotFound();
    $this->actingAs($partner->user)->get(route('partners.dashboard'))->assertNotFound();
    $this->actingAs($partner->user)->get(route('partners.apply'))->assertNotFound();
});

it('is off by default in production, and needs direct sales on', function () {
    config(['partners.default_on' => false]);
    app(Features::class)->flush();
    expect(Features::enabled('partners'))->toBeFalse();

    config(['partners.default_on' => true, 'commerce.enabled' => false]);
    app(Features::class)->flush();
    expect(Features::enabled('partners'))->toBeFalse();
});
