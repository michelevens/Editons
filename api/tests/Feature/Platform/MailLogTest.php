<?php

use App\Mail\Account\WelcomeMail;
use App\Mail\EcrivainMail;
use App\Mail\Notify;
use App\Mail\Support\Letter;
use App\Models\MailLog;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The email log: metadata for every mail, the body only when it carries no
 * secret, and a resend that rebuilds the same mailable.
 */

it('logs a sent mail with its metadata, body and how to rebuild it', function () {
    $user = User::factory()->create(['email' => 'ada@example.com', 'name' => 'Ada Writer']);

    Notify::welcome($user);

    $log = MailLog::sole();
    expect($log->to)->toBe('ada@example.com')
        ->and($log->user_id)->toBe($user->id)
        ->and($log->mailable)->toBe(WelcomeMail::class)
        ->and($log->category)->toBe('account')
        ->and($log->status)->toBe('sent')
        ->and($log->subject)->not->toBeEmpty()
        ->and($log->provider_message_id)->not->toBeEmpty()
        ->and($log->sensitive)->toBeFalse()
        ->and($log->body_text)->toContain('Ada')
        ->and($log->resend_args)->toEqual(['user' => ['__model' => User::class, 'id' => $user->id]])
        ->and($log->resendable())->toBeTrue();
});

it('keeps only metadata for a password reset, never the link', function () {
    $user = User::factory()->create();

    $user->sendPasswordResetNotification('secret-reset-token-123');

    $log = MailLog::sole();
    expect($log->sensitive)->toBeTrue()
        ->and($log->body_text)->toBeNull()
        ->and($log->resend_args)->toBeNull()
        ->and($log->resendable())->toBeFalse()
        ->and($log->subject)->not->toBeEmpty();

    // The token is nowhere in the row.
    expect(json_encode($log->getAttributes()))->not->toContain('secret-reset-token-123');
});

it('resends a mail to the same address, linked to the original and audited', function () {
    $user = User::factory()->create();
    Notify::welcome($user);
    $original = MailLog::sole();
    $support = platformAdmin('support');

    $this->actingAs($support)
        ->post("/admin/mail/{$original->id}/resend", ['reason' => 'Author never got it'])
        ->assertSessionHas('platform_notice');

    $again = MailLog::where('id', '!=', $original->id)->where('to', $user->email)->sole();
    expect($again->mailable)->toBe(WelcomeMail::class)
        ->and($again->resent_from_id)->toBe($original->id);
    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'mail.resent', 'subject_id' => $original->id, 'actor_id' => $support->id, 'reason' => 'Author never got it']);
});

it('refuses to resend a mail that carried a one-time link', function () {
    $user = User::factory()->create();
    $user->sendPasswordResetNotification('tok');
    $log = MailLog::sole();

    $this->actingAs(platformAdmin())
        ->post("/admin/mail/{$log->id}/resend", ['reason' => 'Try again'])
        ->assertSessionHasErrors('mail');

    expect(MailLog::count())->toBe(1);
});

it('logs a mail that failed before it left', function () {
    $user = User::factory()->create(['email' => 'broken@example.com']);

    $mail = new class extends EcrivainMail
    {
        public function letter(): Letter
        {
            throw new RuntimeException('Template exploded');
        }
    };

    expect(fn () => Notify::send($user, $mail))->toThrow(RuntimeException::class);

    $log = MailLog::sole();
    expect($log->status)->toBe('failed')
        ->and($log->to)->toBe('broken@example.com')
        ->and($log->error)->toContain('Template exploded');
});

it('lists and searches the log for staff, and says bounces are not tracked', function () {
    Notify::welcome(User::factory()->create(['email' => 'first@example.com']));
    Notify::welcome(User::factory()->create(['email' => 'second@example.com']));

    $this->actingAs(platformAdmin('support'))
        ->get('/admin/mail?q=second')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Mail')
            ->has('logs.data', 1)
            ->where('logs.data.0.to', 'second@example.com')
            ->where('delivery_tracking', false));

    $this->actingAs(User::factory()->create())->get('/admin/mail')->assertNotFound();
});
