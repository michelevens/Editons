<?php

use App\Billing\CreditLedger;
use App\Models\AdminAuditLog;
use App\Models\AdminNote;
use App\Models\CreditTransaction;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
 * What staff can do to an account, and that each change is written to the
 * audit log with it — or not made at all.
 */

function activeSubscription(User $user): void
{
    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test_'.$user->id,
        'stripe_status' => 'active',
        'stripe_price' => 'price_author',
        'quantity' => 1,
    ]);
}

it('suspends an account: no sign-in, a clear message, and its sessions end', function () {
    $owner = platformAdmin();
    $author = User::factory()->create();

    // The author is signed in elsewhere.
    $this->post('/login', ['email' => $author->email, 'password' => 'password']);
    $this->get('/books')->assertOk();

    $this->actingAs($owner)->post("/admin/accounts/{$author->id}/suspend", ['reason' => 'Chargeback fraud'])
        ->assertSessionHas('platform_notice');

    expect($author->fresh()->isSuspended())->toBeTrue();
    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'account.suspended', 'subject_id' => $author->id, 'actor_id' => $owner->id, 'reason' => 'Chargeback fraud']);

    // That old session is ended on its next request.
    auth()->guard('web')->setUser($author->fresh());
    $this->get('/books')->assertRedirect(route('login'))->assertSessionHas('status', fn ($m) => str_contains($m, 'suspended'));
    $this->assertGuest();

    // And a new sign-in is refused, after the password, with a message.
    $this->post('/login', ['email' => $author->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => 'This account is suspended. If you think this is a mistake, write to support.']);
    $this->assertGuest();

    // A wrong password still says only that the details are wrong.
    $this->post('/login', ['email' => $author->email, 'password' => 'nope'])
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);
});

it('reactivates a suspended account', function () {
    $owner = platformAdmin();
    $author = User::factory()->create();
    $author->forceFill(['suspended_at' => now(), 'suspended_reason' => 'x'])->save();

    $this->actingAs($owner)->post("/admin/accounts/{$author->id}/reactivate", ['reason' => 'Resolved with the bank']);

    expect($author->fresh()->isSuspended())->toBeFalse();
    app('auth')->forgetGuards();
    $this->post('/login', ['email' => $author->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($author);
});

it('refuses to suspend yourself or an owner', function () {
    $owner = platformAdmin();
    $other = platformAdmin();

    $this->actingAs($owner)->post("/admin/accounts/{$owner->id}/suspend", ['reason' => 'Oops mine'])->assertSessionHasErrors('reason');
    $this->actingAs($owner)->post("/admin/accounts/{$other->id}/suspend", ['reason' => 'Coup attempt'])->assertSessionHasErrors('reason');

    expect($owner->fresh()->isSuspended())->toBeFalse()->and($other->fresh()->isSuspended())->toBeFalse();
});

it('signs an account out everywhere, including a remembered device', function () {
    $support = platformAdmin('support');
    $author = User::factory()->create();
    $token = $author->remember_token;

    $this->post('/login', ['email' => $author->email, 'password' => 'password']);
    $this->travel(2)->seconds();

    $this->actingAs($support)->post("/admin/accounts/{$author->id}/sign-out", ['reason' => 'Lost laptop']);

    expect($author->fresh()->remember_token)->not->toBe($token);

    auth()->guard('web')->setUser($author->fresh());
    $this->get('/books')->assertRedirect(route('login'));
    $this->assertGuest();

    // Signing in again afterwards works.
    $this->travel(2)->seconds();
    $this->post('/login', ['email' => $author->email, 'password' => 'password']);
    $this->get('/books')->assertOk();
});

it('requires a reason for every change', function () {
    $owner = platformAdmin();
    $author = User::factory()->create();

    $this->actingAs($owner)->post("/admin/accounts/{$author->id}/suspend", [])->assertSessionHasErrors('reason');
    $this->actingAs($owner)->post("/admin/accounts/{$author->id}/credits", ['amount' => 10])->assertSessionHasErrors('reason');
    expect(AdminAuditLog::count())->toBe(0);
});

it('resets two-factor for somebody who lost their phone', function () {
    $owner = platformAdmin();
    $author = platformAdmin('support');

    $this->actingAs($owner)->post("/admin/accounts/{$author->id}/two-factor-reset", ['reason' => 'Lost phone, ID checked']);

    expect($author->fresh()->two_factor_secret)->toBeNull();
    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'account.two_factor_reset', 'subject_id' => $author->id]);
});

it('sends the ordinary password reset link', function () {
    Notification::fake();
    $support = platformAdmin('support');
    $author = User::factory()->create();

    $this->actingAs($support)->post("/admin/accounts/{$author->id}/password-reset")->assertSessionHas('platform_notice');

    Notification::assertSentTo($author, ResetPasswordNotification::class);
    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'account.password_reset_sent', 'subject_id' => $author->id]);
});

it('gives a complimentary plan with an end date, and it ends', function () {
    $owner = platformAdmin();
    $author = User::factory()->create();
    $until = now()->addDays(10)->toDateString();

    $this->actingAs($owner)->post("/admin/accounts/{$author->id}/plan", ['action' => 'set', 'plan' => 'studio', 'until' => $until, 'reason' => 'Beta tester'])
        ->assertSessionHasNoErrors();

    $author->refresh();
    expect($author->plan)->toBe('studio')
        ->and($author->planLimits()['name'])->toBe('Studio')
        ->and($author->complimentary_until->toDateString())->toBe($until);

    $log = AdminAuditLog::where('action', 'plan.complimentary_set')->sole();
    expect($log->before['plan'])->toBe('free')->and($log->after['plan'])->toBe('studio');

    // Past the date, the limits are Free at once, before any command runs.
    $this->travel(11)->days();
    expect($author->fresh()->planLimits()['name'])->toBe('Free');

    $this->artisan('platform:expire-complimentary')->assertSuccessful();
    expect($author->fresh()->plan)->toBe('free')->and($author->fresh()->complimentary_since)->toBeNull();
    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'plan.complimentary_expired', 'subject_id' => $author->id, 'actor_id' => null]);
});

it('refuses a complimentary plan to an account Stripe is billing', function () {
    $owner = platformAdmin();
    $author = User::factory()->create();
    activeSubscription($author);

    $this->actingAs($owner)->post("/admin/accounts/{$author->id}/plan", ['action' => 'set', 'plan' => 'studio', 'reason' => 'Gift'])
        ->assertSessionHasErrors(['plan' => 'This account pays through Stripe. Change the plan in Stripe (or cancel it there) instead; a complimentary plan would be overwritten by the next invoice.']);

    expect($author->fresh()->plan)->toBe('free');
    expect(AdminAuditLog::count())->toBe(0);
});

it('ends a complimentary plan on request', function () {
    $owner = platformAdmin();
    $author = User::factory()->create();
    $this->actingAs($owner)->post("/admin/accounts/{$author->id}/plan", ['action' => 'set', 'plan' => 'author', 'reason' => 'Trial']);

    $this->actingAs($owner)->post("/admin/accounts/{$author->id}/plan", ['action' => 'clear', 'reason' => 'Trial over']);

    expect($author->fresh()->plan)->toBe('free');
});

it('grants and deducts credits as signed ledger rows, audited', function () {
    $owner = platformAdmin();
    $author = User::factory()->create();
    $ledger = app(CreditLedger::class);

    $this->actingAs($owner)->post("/admin/accounts/{$author->id}/credits", ['amount' => 250, 'reason' => 'Failed review refund']);
    $this->actingAs($owner)->post("/admin/accounts/{$author->id}/credits", ['amount' => -50, 'reason' => 'Overgranted']);

    expect($ledger->balanceFor($author))->toBe(200);
    expect(CreditTransaction::where('user_id', $author->id)->orderBy('id')->get(['kind', 'amount'])->toArray())
        ->toBe([['kind' => 'admin_grant', 'amount' => 250], ['kind' => 'admin_deduct', 'amount' => -50]]);

    $granted = AdminAuditLog::where('action', 'credits.granted')->sole();
    expect($granted->before)->toBe(['balance' => 0])
        ->and($granted->after['balance'])->toBe(250)
        ->and($granted->reason)->toBe('Failed review refund');
    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'credits.deducted', 'subject_id' => $author->id]);
});

it('never deducts below zero', function () {
    $owner = platformAdmin();
    $author = User::factory()->create();

    $this->actingAs($owner)->post("/admin/accounts/{$author->id}/credits", ['amount' => -1, 'reason' => 'Take one'])
        ->assertSessionHasErrors('amount');

    expect(CreditTransaction::count())->toBe(0);
});

it('holds support to a small top-up and no deductions', function () {
    $support = platformAdmin('support');
    $author = User::factory()->create();

    $this->actingAs($support)->post("/admin/accounts/{$author->id}/credits", ['amount' => 500, 'reason' => 'Goodwill'])->assertSessionHasNoErrors();
    $this->actingAs($support)->post("/admin/accounts/{$author->id}/credits", ['amount' => 501, 'reason' => 'Too generous'])->assertSessionHasErrors('amount');
    $this->actingAs($support)->post("/admin/accounts/{$author->id}/credits", ['amount' => -10, 'reason' => 'Take back'])->assertSessionHasErrors('amount');

    expect(app(CreditLedger::class)->balanceFor($author))->toBe(500);
});

it('enforces the ledger sign in the database for staff kinds too', function () {
    $author = User::factory()->create();

    expect(fn () => DB::table('credit_transactions')->insert([
        'user_id' => $author->id, 'amount' => -5, 'kind' => 'admin_grant', 'label' => 'x', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('makes no change when the audit row cannot be written', function () {
    $owner = platformAdmin();
    $author = User::factory()->create();

    AdminAuditLog::creating(fn () => throw new RuntimeException('audit store unavailable'));

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($owner)->post("/admin/accounts/{$author->id}/credits", ['amount' => 100, 'reason' => 'Should not land']))
        ->toThrow(RuntimeException::class);
    expect(fn () => $this->actingAs($owner)->post("/admin/accounts/{$author->id}/suspend", ['reason' => 'Should not land']))
        ->toThrow(RuntimeException::class);

    expect(CreditTransaction::count())->toBe(0)
        ->and($author->fresh()->isSuspended())->toBeFalse();
});

it('makes and unmakes support staff, owner only', function () {
    $owner = platformAdmin();
    $author = User::factory()->create();

    $this->actingAs($owner)->post("/admin/accounts/{$author->id}/role", ['role' => 'support', 'reason' => 'New hire']);
    expect($author->fresh()->platform_role)->toBe('support');

    $this->actingAs($owner)->post("/admin/accounts/{$author->id}/role", ['role' => null, 'reason' => 'Left the company']);
    expect($author->fresh()->platform_role)->toBeNull();

    // Owners are only ever made from the shell.
    $this->actingAs($owner)->post("/admin/accounts/{$author->id}/role", ['role' => 'owner', 'reason' => 'Nope'])->assertSessionHasErrors('role');
});

it('pins, unpins and deletes notes, keeping the text in the audit log', function () {
    $support = platformAdmin('support');
    $author = User::factory()->create();

    $this->actingAs($support)->post("/admin/accounts/{$author->id}/notes", ['body' => 'Prefers email', 'pinned' => true]);
    $note = AdminNote::sole();
    expect($note->pinned)->toBeTrue();

    $this->actingAs($support)->patch("/admin/accounts/{$author->id}/notes/{$note->id}", ['pinned' => false]);
    expect($note->fresh()->pinned)->toBeFalse();

    // Another support member cannot delete it.
    $this->actingAs(platformAdmin('support'))->delete("/admin/accounts/{$author->id}/notes/{$note->id}")->assertForbidden();

    $this->actingAs($support)->delete("/admin/accounts/{$author->id}/notes/{$note->id}");
    expect(AdminNote::count())->toBe(0);
    expect(AdminAuditLog::where('action', 'note.deleted')->sole()->before['body'])->toBe('Prefers email');
});

it('exports the audit log as CSV, and records that it did', function () {
    $owner = platformAdmin();
    $author = User::factory()->create();
    $this->actingAs($owner)->post("/admin/accounts/{$author->id}/credits", ['amount' => 5, 'reason' => '=HYPERLINK("x")']);

    $response = $this->actingAs($owner)->get('/admin/audit/export?action=credits.granted');
    $csv = $response->streamedContent();

    expect($csv)->toContain('credits.granted')
        ->and($csv)->toContain("'=HYPERLINK")
        ->and($csv)->not->toContain('note.added');
    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'audit.exported', 'actor_id' => $owner->id]);
});

it('refuses to edit or delete an audit row', function () {
    $owner = platformAdmin();
    $this->actingAs($owner)->post('/admin/accounts/'.User::factory()->create()->id.'/sign-out', ['reason' => 'Testing']);
    $log = AdminAuditLog::sole();

    expect(fn () => $log->update(['reason' => 'rewritten']))->toThrow(LogicException::class)
        ->and(fn () => $log->delete())->toThrow(LogicException::class);
});
