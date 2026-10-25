<?php

use App\Growth\AudienceRules;
use App\Growth\Lifecycle;
use App\Mail\Notify;
use App\Models\AdminAuditLog;
use App\Models\Book;
use App\Models\LifecycleCampaign;
use App\Models\User;
use App\Platform\AccountAdmin;
use App\Platform\House\House;
use App\Platform\House\HouseAccounts;
use App\Platform\Impersonation;
use App\Platform\PlatformMetrics;
use App\Platform\Trust\Offboarding;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/*
 * House publisher accounts (ADR — house catalog): a platform-owned imprint
 * nobody signs in to, that receives no email, is never a growth target, and
 * cannot be viewed as, made staff or offboarded with its books in it.
 */

function houseAccount(string $slug = 'ecrivain-prepa', string $name = 'Écrivain Prépa'): User
{
    return app(HouseAccounts::class)->create($slug, $name, 'Test imprint');
}

/** Messages the array mailer actually handed to its transport. */
function houseSentMail(): int
{
    return count(app('mailer')->getSymfonyTransport()->messages());
}

it('creates a house account from the shell, audited, idempotently and without a person in it', function () {
    $this->artisan('house:create', ['slug' => 'ecrivain-prepa', '--name' => 'Écrivain Prépa', '--reason' => 'Platform imprint'])
        ->expectsOutputToContain('Created')
        ->assertSuccessful();

    $this->artisan('house:create', ['slug' => 'ecrivain-prepa', '--name' => 'Écrivain Prépa', '--reason' => 'Again'])
        ->expectsOutputToContain('Already exists')
        ->assertSuccessful();

    $house = User::where('house_slug', 'ecrivain-prepa')->sole();

    expect($house->isHouse())->toBeTrue()
        ->and($house->name)->toBe('Écrivain Prépa')
        ->and($house->author_name)->toBe('Écrivain Prépa')
        ->and($house->email)->toEndWith('@'.House::MAIL_DOMAIN)
        ->and($house->plan)->toBe('studio')
        ->and($house->platform_role)->toBeNull()
        ->and(AdminAuditLog::where('action', 'house.account_created')->where('subject_id', $house->id)->count())->toBe(1);
});

it('refuses every sign-in to a house account, whatever the password', function () {
    $house = houseAccount();
    $house->forceFill(['password' => 'known-password'])->save();

    $this->post('/login', ['email' => $house->email, 'password' => 'known-password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('signs out any session that is somehow a house account', function () {
    $house = houseAccount();

    $this->actingAs($house)->get('/books')->assertRedirect(route('login'));

    $this->assertGuest();
});

it('sends no email to a house account, by Notify or straight through the mailer', function () {
    $house = houseAccount();

    expect(Notify::welcome($house))->toBeFalse();

    Mail::raw('Hello', fn ($m) => $m->to($house->email)->subject('Direct'));
    Mail::raw('Hello', fn ($m) => $m->to('reader@example.com')->bcc($house->email)->subject('Copied'));
    expect(houseSentMail())->toBe(0);

    Mail::raw('Hello', fn ($m) => $m->to('reader@example.com')->subject('Fine'));
    expect(houseSentMail())->toBe(1);
});

it('sends no password reset to a house account, from the form or the console', function () {
    $house = houseAccount();

    $this->post('/forgot-password', ['email' => $house->email]);
    expect(houseSentMail())->toBe(0);

    expect(fn () => app(AccountAdmin::class)->sendPasswordReset($house, platformAdmin()))
        ->toThrow(ValidationException::class);
});

it('keeps house accounts out of broadcasts, lifecycle mail and the real-author figures', function () {
    $house = houseAccount();
    $author = User::factory()->create();

    $audience = AudienceRules::query(null)->pluck('users.id')->all();
    expect($audience)->toContain($author->id)->not->toContain($house->id);

    LifecycleCampaign::where('key', 'no_book_3d')->update(['enabled' => true]);
    $house->forceFill(['created_at' => now()->subDays(4)])->save();
    $eligible = app(Lifecycle::class)->eligible(LifecycleCampaign::where('key', 'no_book_3d')->sole())->pluck('users.id')->all();
    expect($eligible)->not->toContain($house->id);

    expect(app(PlatformMetrics::class)->live()['accounts_total'])->toBe(User::where('account_kind', '!=', 'house')->count());
});

it('cannot be viewed as, made staff, or offboarded while it holds books', function () {
    $house = houseAccount();
    $owner = platformAdmin();
    Book::factory()->for($house)->create();

    expect(Impersonation::refusal($owner, $house))->toContain('house publisher account');

    expect(fn () => app(AccountAdmin::class)->setRole($house, 'support', 'Testing', $owner))
        ->toThrow(ValidationException::class);

    $plan = app(Offboarding::class)->plan($house);
    expect(collect($plan['refusals'])->implode(' '))->toContain('house publisher account');
});

it('shows house accounts as House in the console account list', function () {
    $house = houseAccount();
    User::factory()->create();

    $this->actingAs(platformAdmin('support'))
        ->get('/admin/accounts?filter=house')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('accounts.data', 1)
            ->where('accounts.data.0.id', $house->id)
            ->where('accounts.data.0.house', true));
});
