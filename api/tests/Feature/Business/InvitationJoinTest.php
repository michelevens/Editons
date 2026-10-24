<?php

use App\Billing\CreditLedger;
use App\Growth\Attribution;
use App\Mail\Account\WelcomeMail;
use App\Mail\Growth\AuthorInvitationMail;
use App\Models\AdminAuditLog;
use App\Models\AuthorInvitation;
use App\Models\CreditTransaction;
use App\Models\InboxItem;
use App\Models\User;
use App\Platform\PlatformSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * /join/{token}: the invitation's link. Registers with the invited address
 * even while sign-ups are closed, applies the perks in the same transaction
 * as the account, marks where the account came from, and is single-use.
 */

beforeEach(function () {
    Mail::fake();
});

/** An invitation sent by the owner, and its raw token. */
function sentInvitation(array $data = [], ?User $owner = null): array
{
    $owner ??= platformAdmin();
    test()->actingAs($owner)->post(route('admin.invitations.store'), $data + ['email' => 'nadege@example.com'])->assertSessionHasNoErrors();
    auth()->logout();

    $invitation = AuthorInvitation::latest('id')->firstOrFail();
    $token = null;
    Mail::assertQueued(AuthorInvitationMail::class, function (AuthorInvitationMail $m) use ($invitation, &$token) {
        if ($m->invitation->id === $invitation->id) {
            $token = $m->token;
        }

        return true;
    });

    return [$invitation, $token, $owner];
}

function joinWith(string $token, array $data = [])
{
    return test()->post(route('join.register', $token), $data + [
        'name' => 'Nadège Pierre',
        'password' => 'a-long-passphrase-1',
        'password_confirmation' => 'a-long-passphrase-1',
    ]);
}

it('shows the registration with the address fixed, and nothing for a wrong token', function () {
    [$invitation, $token] = sentInvitation(['name' => 'Nadège Pierre', 'credits' => 300]);

    $this->get(route('join.show', $token))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Join')
        ->where('state', 'pending')
        ->where('invitation.email', 'nadege@example.com')
        ->where('invitation.name', 'Nadège Pierre')
        ->where('invitation.perks', ['300 AI credits'])
        ->where('signed_in_as', null)
        ->where('account_exists', false));

    $this->get(route('join.show', str_repeat('x', 48)))->assertOk()->assertInertia(fn (Assert $page) => $page->where('state', 'unknown')->where('invitation', null));
    $this->get('/join/short')->assertOk()->assertInertia(fn (Assert $page) => $page->where('state', 'unknown'));
});

it('registers while sign-ups are closed, applies the perks atomically and audited, and sets the source', function () {
    app(PlatformSettings::class)->update(['new_signups_open' => false], platformAdmin());
    app('auth')->forgetGuards();
    [$invitation, $token, $owner] = sentInvitation(['plan' => 'author', 'plan_months' => 6, 'credits' => 500, 'campaign' => 'beta-fall']);

    // The ordinary sign-up is closed…
    $this->post('/register', ['name' => 'X', 'email' => 'x@example.com', 'password' => 'a-long-passphrase-1', 'password_confirmation' => 'a-long-passphrase-1'])
        ->assertSessionHasErrors('email');

    // …the invitation is not. The email field is ignored: the address is the invitation's.
    joinWith($token, ['email' => 'someone-else@example.com'])->assertSessionHasNoErrors()->assertRedirect('/dashboard');

    $user = User::where('email', 'nadege@example.com')->sole();
    $this->assertAuthenticatedAs($user);
    expect(User::where('email', 'someone-else@example.com')->exists())->toBeFalse();

    $invitation->refresh();
    expect($invitation->status)->toBe('accepted')
        ->and($invitation->accepted_user_id)->toBe($user->id)
        ->and($invitation->accepted_at)->not->toBeNull()
        ->and($invitation->perks_applied['plan'])->toBe('author')
        ->and($invitation->perks_applied['credits'])->toBe(500);

    // The complimentary plan, through the console's own mechanism, with its end date.
    expect($user->plan)->toBe('author')
        ->and($user->complimentary_since)->not->toBeNull()
        ->and($user->complimentary_until->isSameDay(now()->addMonths(6)))->toBeTrue();

    // Credits as an admin grant on the ledger, labelled with the invitation.
    $grant = CreditTransaction::where('user_id', $user->id)->where('kind', 'admin_grant')->sole();
    expect($grant->amount)->toBe(500)
        ->and($grant->label)->toBe("Invitation #{$invitation->id}")
        ->and($grant->external_reference)->toBe("invitation:{$invitation->id}");

    // Audited: the plan and the credits as the inviter's, the acceptance as the invitee's.
    expect(AdminAuditLog::where('action', 'plan.complimentary_set')->sole())
        ->actor_id->toBe($owner->id)
        ->reason->toBe("Invitation #{$invitation->id}")
        ->and(AdminAuditLog::where('action', 'credits.granted')->sole()->actor_id)->toBe($owner->id)
        ->and(AdminAuditLog::where('action', 'invitation.accepted')->sole()->actor_id)->toBe($user->id);

    // Growth sees where the account came from.
    expect($user->signup_source)->toBe('invite')
        ->and($user->signup_medium)->toBe('email')
        ->and($user->signup_campaign)->toBe('beta-fall')
        ->and($user->signup_landing)->toBe('/join')
        ->and($user->email_verified_at)->not->toBeNull();

    // A welcome in the inbox, and the welcome email.
    expect(InboxItem::where('user_id', $user->id)->where('kind', 'welcome')->sole()->body)
        ->toContain('Author plan is on the house until')
        ->toContain('500 AI credits');
    Mail::assertQueued(WelcomeMail::class, fn ($m) => $m->hasTo('nadege@example.com'));
});

it('is single-use', function () {
    [$invitation, $token] = sentInvitation(['credits' => 100]);

    joinWith($token)->assertRedirect('/dashboard');
    auth()->logout();

    joinWith($token)->assertSessionHasErrors('email');
    $this->get(route('join.show', $token))->assertInertia(fn (Assert $page) => $page->where('state', 'accepted')->where('token', null));

    expect(User::where('email', 'nadege@example.com')->count())->toBe(1)
        ->and(app(CreditLedger::class)->balanceFor(User::where('email', 'nadege@example.com')->sole()))->toBe(100);
});

it('leaves no account behind when the perks cannot be applied', function () {
    [$invitation, $token] = sentInvitation(['credits' => 100]);

    // Make the ledger refuse this grant, as a failing write would.
    DB::statement("ALTER TABLE credit_transactions ADD CONSTRAINT test_no_grants CHECK (label <> 'Invitation #{$invitation->id}')");

    joinWith($token)->assertServerError();

    expect(User::where('email', 'nadege@example.com')->exists())->toBeFalse()
        ->and($invitation->fresh()->status)->toBe('pending')
        ->and(AdminAuditLog::where('action', 'invitation.accepted')->exists())->toBeFalse();
    $this->assertGuest();
});

it('skips the plan for an account that already pays through Stripe, and says so', function () {
    [$invitation, $token] = sentInvitation(['email' => 'payer@example.com', 'plan' => 'studio', 'plan_months' => 2, 'credits' => 20]);
    $user = User::factory()->create(['email' => 'payer@example.com', 'plan' => 'author']);
    bizSubscription($user);

    $this->actingAs($user)->post(route('join.accept', $token))->assertRedirect('/dashboard');

    expect($user->fresh()->plan)->toBe('author')
        ->and($user->fresh()->complimentary_since)->toBeNull()
        ->and($invitation->fresh()->perks_applied['plan_skipped'])->toBe('The account already pays through Stripe.')
        ->and($invitation->fresh()->perks_applied['credits'])->toBe(20);
});

it('refuses an expired or revoked link with a friendly page', function () {
    [$expired, $token] = sentInvitation(['email' => 'late@example.com', 'expires_days' => 1]);
    $this->travel(2)->days();

    $this->get(route('join.show', $token))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('state', 'expired')
        ->where('contact', 'support@ecrivain.ai')
        ->where('invitation', null));
    joinWith($token)->assertSessionHasErrors(['email' => 'This invitation is no longer valid.']);

    expect(User::where('email', 'late@example.com')->exists())->toBeFalse();
});

it('accepts directly for the account signed in with the same address, without touching its first touch', function () {
    [$invitation, $token, $owner] = sentInvitation(['email' => 'already@example.com', 'credits' => 50]);
    // The account was made after the invitation, the ordinary way.
    $user = User::factory()->create(['email' => 'already@example.com', 'signup_source' => 'newsletter']);

    $this->actingAs($user)->get(route('join.show', $token))->assertInertia(fn (Assert $page) => $page
        ->where('signed_in_as', 'already@example.com')
        ->where('email_matches', true));

    $this->actingAs($user)->post(route('join.accept', $token))->assertRedirect('/dashboard');

    expect($invitation->fresh()->status)->toBe('accepted')
        ->and($user->fresh()->signup_source)->toBe('newsletter')
        ->and(app(CreditLedger::class)->balanceFor($user))->toBeGreaterThanOrEqual(50);
});

it('refuses a different signed-in account, with a clear message', function () {
    [$invitation, $token] = sentInvitation();
    $other = User::factory()->create(['email' => 'other@example.com']);

    $this->actingAs($other)->get(route('join.show', $token))->assertInertia(fn (Assert $page) => $page
        ->where('signed_in_as', 'other@example.com')
        ->where('email_matches', false));

    $this->actingAs($other)->post(route('join.accept', $token))
        ->assertSessionHasErrors(['email' => 'This invitation was sent to a different email address.']);
    $this->actingAs($other)->post(route('join.register', $token))->assertRedirect();

    expect($invitation->fresh()->status)->toBe('pending');
});

it('sends a guest whose address now has an account to sign in, then back', function () {
    [$invitation, $token] = sentInvitation(['email' => 'has@example.com']);
    User::factory()->create(['email' => 'has@example.com']);

    $this->get(route('join.show', $token))->assertInertia(fn (Assert $page) => $page->where('account_exists', true));
    expect(session('url.intended'))->toBe(route('join.show', $token));

    joinWith($token)->assertSessionHasErrors('email');
});

it('lets the invitee decline, and refuses to invite them again', function () {
    [$invitation, $token, $owner] = sentInvitation();

    $this->get(route('join.show', ['token' => $token, 'decline' => 1]))->assertInertia(fn (Assert $page) => $page->where('decline', true));
    $this->post(route('join.decline', $token))->assertRedirect(route('join.show', $token));

    expect($invitation->fresh()->status)->toBe('declined')
        ->and(DB::table('author_invitation_opt_outs')->count())->toBe(1)
        // An HMAC, never the address.
        ->and(DB::table('author_invitation_opt_outs')->value('email_hash'))->not->toContain('nadege');

    $this->get(route('join.show', $token))->assertInertia(fn (Assert $page) => $page->where('state', 'declined'));

    $this->actingAs($owner)->post(route('admin.invitations.store'), ['email' => 'nadege@example.com'])
        ->assertSessionHasErrors(['email' => 'Asked not to be invited again.']);
});

it('refuses joining while viewing as an author', function () {
    [$invitation, $token, $owner] = sentInvitation(['email' => 'viewed@example.com', 'credits' => 50]);
    $author = User::factory()->create(['email' => 'viewed@example.com']);

    $this->actingAs($owner)->post("/admin/accounts/{$author->id}/impersonate", ['reason' => 'Ticket 12: checking'])->assertRedirect();
    $this->assertAuthenticatedAs($author);

    $this->post(route('join.accept', $token))->assertForbidden();
    expect($invitation->fresh()->status)->toBe('pending');
});

it('keeps the token out of the first-touch landing page', function () {
    [$invitation, $token] = sentInvitation();

    $request = Request::create('/join/'.$token);
    expect(Attribution::fromRequest($request)['landing'])->toBe('/join');
});
