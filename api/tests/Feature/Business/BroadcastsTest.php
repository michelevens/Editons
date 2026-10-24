<?php

use App\Growth\Broadcasts;
use App\Mail\Growth\BroadcastMail;
use App\Models\AdminAuditLog;
use App\Models\Audience;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\InboxItem;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;

/*
 * Broadcasts: compose, test-send to yourself, schedule, send in batches —
 * only to people whose preferences allow news, once each — and the counts.
 */

beforeEach(function () {
    Mail::fake();
});

function bizBroadcast(User $owner, array $data = []): Broadcast
{
    test()->actingAs($owner)->post(route('admin.growth.broadcasts.store'), $data + [
        'subject' => 'Écrivain speaks Kreyòl',
        'body' => "The studio is now in Haitian Creole.\n\nSwitch in your profile.",
        'cta_label' => 'Open your profile',
        'cta_url' => 'https://app.ecrivain.ai/profile',
        'inbox' => false,
    ])->assertRedirect();

    return Broadcast::latest('id')->first();
}

it('sends only to people who allow news, once each, and counts what happened', function () {
    $owner = platformAdmin();
    $ada = User::factory()->create();
    $bob = User::factory()->create(['email_preferences' => ['news' => false]]);
    $cy = User::factory()->create();
    $suspended = User::factory()->create();
    $suspended->forceFill(['suspended_at' => now(), 'suspended_reason' => 'Spam'])->save();

    $broadcast = bizBroadcast($owner);

    $this->actingAs($owner)->post(route('admin.growth.broadcasts.schedule', $broadcast), ['when' => 'now', 'reason' => 'Launch announcement'])->assertRedirect();
    expect($broadcast->fresh()->status)->toBe('scheduled');

    $this->artisan('growth:send-broadcasts')->assertSuccessful();

    Mail::assertSent(BroadcastMail::class, 2);
    Mail::assertSent(BroadcastMail::class, fn (BroadcastMail $m) => $m->hasTo($ada->email) && str_contains($m->unsubscribeUrl(), '/news'));
    Mail::assertNotSent(BroadcastMail::class, fn (BroadcastMail $m) => $m->hasTo($bob->email) || $m->hasTo($suspended->email) || $m->hasTo($owner->email));

    $broadcast->refresh();
    expect($broadcast->status)->toBe('sent')
        ->and($broadcast->recipients_total)->toBe(3)
        ->and($broadcast->sent_count)->toBe(2)
        ->and($broadcast->skipped_count)->toBe(1)
        ->and($broadcast->failed_count)->toBe(0)
        ->and(BroadcastRecipient::where('user_id', $bob->id)->value('skip_reason'))->toBe('preferences');

    // A second run starts nothing, and nobody gets it twice.
    $this->artisan('growth:send-broadcasts')->assertSuccessful();
    app(Broadcasts::class)->sendBatch($broadcast->id, BroadcastRecipient::pluck('id')->all());
    Mail::assertSent(BroadcastMail::class, 2);

    expect(AdminAuditLog::where('action', 'broadcast.scheduled')->sole()->reason)->toBe('Launch announcement');
});

it('honours an unsubscribe that arrives between resolving and sending', function () {
    $owner = platformAdmin();
    $ada = User::factory()->create();
    $broadcast = bizBroadcast($owner);
    $broadcast->forceFill(['status' => 'sending'])->save();

    // Resolved, then Ada clicks unsubscribe before her batch goes.
    Queue::fake();
    app(Broadcasts::class)->start($broadcast);
    $this->get(URL::signedRoute('email.unsubscribe', ['user' => $ada->id, 'category' => 'news']))->assertOk();

    app(Broadcasts::class)->sendBatch($broadcast->id, BroadcastRecipient::pluck('id')->all());

    Mail::assertNotSent(BroadcastMail::class);
    expect(BroadcastRecipient::sole()->status)->toBe('skipped');
});

it('targets a saved audience and can put it in the inbox too', function () {
    $owner = platformAdmin();
    $author = User::factory()->create(['plan' => 'author']);
    $optedOut = User::factory()->create(['plan' => 'author', 'email_preferences' => ['news' => false]]);
    $free = User::factory()->create(['plan' => 'free']);
    $audience = Audience::create(['name' => 'Authors', 'rules' => ['plans' => ['author']]]);

    $broadcast = bizBroadcast($owner, ['audience_id' => $audience->id, 'inbox' => true]);
    $this->actingAs($owner)->post(route('admin.growth.broadcasts.schedule', $broadcast), ['when' => 'now', 'reason' => 'Authors only'])->assertRedirect();
    $this->artisan('growth:send-broadcasts')->assertSuccessful();

    Mail::assertSent(BroadcastMail::class, 1);
    Mail::assertSent(BroadcastMail::class, fn ($m) => $m->hasTo($author->email));
    // The inbox is not email: everyone in the audience gets the item.
    expect(InboxItem::where('kind', 'announcement')->pluck('user_id')->sort()->values()->all())->toBe(collect([$author->id, $optedOut->id])->sort()->values()->all())
        ->and(InboxItem::where('user_id', $free->id)->exists())->toBeFalse()
        ->and($broadcast->fresh()->status)->toBe('sent')
        ->and($broadcast->fresh()->audience_name)->toBe('Authors');
});

it('waits for a scheduled time, and can be cancelled before it', function () {
    $owner = platformAdmin();
    User::factory()->create();
    $broadcast = bizBroadcast($owner);

    $this->actingAs($owner)->post(route('admin.growth.broadcasts.schedule', $broadcast), ['when' => 'later', 'scheduled_at' => now()->addDay()->toIso8601String(), 'reason' => 'Monday morning'])->assertRedirect();
    $this->artisan('growth:send-broadcasts')->assertSuccessful();

    Mail::assertNothingSent();
    expect($broadcast->fresh()->status)->toBe('scheduled');

    $this->travel(25)->hours();
    $this->artisan('growth:send-broadcasts')->assertSuccessful();
    Mail::assertSent(BroadcastMail::class, 1);

    // Cancelling once it has gone is refused.
    $this->actingAs($owner)->post(route('admin.growth.broadcasts.cancel', $broadcast), ['reason' => 'Too late'])->assertSessionHasErrors('broadcast');
});

it('cancels a scheduled broadcast so nothing goes', function () {
    $owner = platformAdmin();
    User::factory()->create();
    $broadcast = bizBroadcast($owner);
    $this->actingAs($owner)->post(route('admin.growth.broadcasts.schedule', $broadcast), ['when' => 'now', 'reason' => 'Go'])->assertRedirect();
    $this->actingAs($owner)->post(route('admin.growth.broadcasts.cancel', $broadcast), ['reason' => 'Typo in the subject'])->assertRedirect();

    $this->artisan('growth:send-broadcasts')->assertSuccessful();

    Mail::assertNothingSent();
    expect($broadcast->fresh()->status)->toBe('cancelled');
});

it('sends a test to the owner only, marked as a test, with no unsubscribe', function () {
    $owner = platformAdmin();
    User::factory()->count(3)->create();
    $broadcast = bizBroadcast($owner);

    $this->actingAs($owner)->post(route('admin.growth.broadcasts.test', $broadcast))->assertSessionHas('platform_notice', fn ($n) => str_contains($n, $owner->email));

    Mail::assertSent(BroadcastMail::class, 1);
    Mail::assertSent(BroadcastMail::class, fn (BroadcastMail $m) => $m->hasTo($owner->email) && $m->test && $m->unsubscribeUrl() === null && str_starts_with($m->letter()->subject, '[Test]'));
    expect($broadcast->fresh()->status)->toBe('draft');
});

it('previews the template, escaping what the owner typed', function () {
    $this->actingAs(platformAdmin())->postJson(route('admin.growth.broadcasts.preview'), ['subject' => 'Hello <b>there</b>', 'body' => "Line <script>x</script>\n\nTwo"])
        ->assertOk()
        ->assertJson(fn ($json) => $json->where('html', fn ($html) => str_contains($html, '&lt;script&gt;') && ! str_contains($html, '<script>x')));
});

it('refuses a non-https link and keeps broadcasts from support', function () {
    $owner = platformAdmin();

    $this->actingAs($owner)->post(route('admin.growth.broadcasts.store'), ['subject' => 'S', 'body' => 'B', 'cta_label' => 'Go', 'cta_url' => 'javascript:alert(1)'])->assertSessionHasErrors('cta_url');

    $support = platformAdmin('support');
    $this->actingAs($support)->get('/admin/growth/broadcasts')->assertOk();
    $this->actingAs($support)->post(route('admin.growth.broadcasts.store'), ['subject' => 'S', 'body' => 'B'])->assertForbidden();
});
