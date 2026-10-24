<?php

use App\Growth\Lifecycle;
use App\Mail\Growth\LifecycleMail;
use App\Models\AdminAuditLog;
use App\Models\Book;
use App\Models\LifecycleCampaign;
use App\Models\LifecycleSend;
use App\Models\User;
use App\Models\UserLogin;
use Illuminate\Support\Facades\Mail;

/*
 * Lifecycle emails: off until switched on, sent once per account, only
 * within a few days of the trigger, only to people who allow tips.
 */

beforeEach(function () {
    Mail::fake();
});

it('ships switched off and sends nothing', function () {
    User::factory()->create(['created_at' => now()->subDays(4)]);

    $this->artisan('growth:lifecycle')->assertSuccessful();

    Mail::assertNothingSent();
    expect(LifecycleCampaign::where('enabled', true)->count())->toBe(0);
});

it('emails accounts with no book three days in, once, within the window, honouring preferences', function () {
    LifecycleCampaign::where('key', 'no_book_3d')->update(['enabled' => true]);

    $due = User::factory()->create(['created_at' => now()->subDays(4)]);
    $tooNew = User::factory()->create(['created_at' => now()->subDays(1)]);
    $tooOld = User::factory()->create(['created_at' => now()->subDays(60)]);
    $hasBook = User::factory()->create(['created_at' => now()->subDays(4)]);
    Book::factory()->for($hasBook)->create();
    $optedOut = User::factory()->create(['created_at' => now()->subDays(4), 'email_preferences' => ['tips' => false]]);
    $staff = platformAdmin();
    $staff->forceFill(['created_at' => now()->subDays(4)])->save();

    $this->artisan('growth:lifecycle')->assertSuccessful();
    $this->artisan('growth:lifecycle')->assertSuccessful();

    Mail::assertSent(LifecycleMail::class, 1);
    Mail::assertSent(LifecycleMail::class, fn (LifecycleMail $m) => $m->hasTo($due->email) && str_contains((string) $m->unsubscribeUrl(), '/tips'));
    expect(LifecycleSend::count())->toBe(1);

    // Even a direct second attempt sends nothing.
    $campaign = LifecycleCampaign::where('key', 'no_book_3d')->first();
    expect(app(Lifecycle::class)->sendOnce($campaign, $due))->toBeNull();
    Mail::assertSent(LifecycleMail::class, 1);
});

it('emails accounts two weeks quiet, not ones still writing or long gone', function () {
    LifecycleCampaign::where('key', 'inactive_14d')->update(['enabled' => true]);

    $quiet = User::factory()->create(['created_at' => now()->subDays(60)]);
    UserLogin::create(['user_id' => $quiet->id, 'method' => 'password', 'created_at' => now()->subDays(15)]);
    $writing = User::factory()->create(['created_at' => now()->subDays(60)]);
    UserLogin::create(['user_id' => $writing->id, 'method' => 'password', 'created_at' => now()->subDays(15)]);
    bizWrote($writing, null, now()->subDays(2));
    $gone = User::factory()->create(['created_at' => now()->subDays(300)]);
    UserLogin::create(['user_id' => $gone->id, 'method' => 'password', 'created_at' => now()->subDays(200)]);

    $this->artisan('growth:lifecycle')->assertSuccessful();

    Mail::assertSent(LifecycleMail::class, 1);
    Mail::assertSent(LifecycleMail::class, fn ($m) => $m->hasTo($quiet->email));
});

it('is switched and edited by the owner only, audited', function () {
    $campaign = LifecycleCampaign::where('key', 'no_book_3d')->first();
    $form = ['name' => $campaign->name, 'delay_days' => 5, 'subject' => 'Start your book', 'body' => 'Body.', 'cta_label' => 'Start', 'enabled' => true, 'reason' => 'Ready to go'];

    $this->actingAs(platformAdmin('support'))->patch(route('admin.growth.lifecycle.update', $campaign), $form)->assertForbidden();
    $this->actingAs(platformAdmin())->patch(route('admin.growth.lifecycle.update', $campaign), $form)->assertRedirect();

    expect($campaign->fresh())->enabled->toBeTrue()->delay_days->toBe(5)
        ->and(AdminAuditLog::where('action', 'lifecycle.updated')->sole()->after)->toMatchArray(['enabled' => true, 'delay_days' => 5]);
});
