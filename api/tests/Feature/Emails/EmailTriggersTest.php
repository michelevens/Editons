<?php

use App\Billing\CreditLedger;
use App\Mail\Account\AccountDeletedMail;
use App\Mail\Account\EmailChangedMail;
use App\Mail\Account\PasswordChangedMail;
use App\Mail\Account\PasswordResetMail;
use App\Mail\Account\WelcomeMail;
use App\Mail\Billing\CreditsLowMail;
use App\Mail\Collaboration\BookInvitationMail;
use App\Mail\Collaboration\CommentMentionMail;
use App\Mail\Collaboration\CommentReplyMail;
use App\Mail\Collaboration\InvitationAcceptedMail;
use App\Mail\Collaboration\ReviewerAssignedMail;
use App\Mail\Writing\WeeklyDigestMail;
use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

/*
 * Every trigger wired in this branch, with the mailer faked: nothing is
 * sent anywhere, and each test says which email went to whom.
 */

beforeEach(fn () => Mail::fake());

/** An owner, a reviewer on their book, and one chapter. */
function emailBook(): array
{
    $owner = User::factory()->create(['name' => 'Ada Lovelace', 'plan' => 'studio']);
    $reviewer = User::factory()->create(['name' => 'Jonas Pierre']);
    $book = Book::factory()->for($owner)->create(['title' => 'The Salt Road']);
    $book->sections()->delete();

    $section = $book->sections()->create([
        'type' => 'chapter',
        'title' => 'The Market',
        'position' => ManuscriptSection::POSITION_STEP,
        'content' => ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'The market moved inland in 1749.']],
        ]]],
    ]);

    $book->members()->create([
        'email' => $reviewer->email,
        'user_id' => $reviewer->id,
        'role' => 'reviewer',
        'status' => 'active',
    ]);

    return [$owner, $reviewer, $book, $section];
}

// ── Account ─────────────────────────────────────────────────────────────

it('welcomes somebody who registers', function () {
    $this->post('/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    Mail::assertQueued(WelcomeMail::class, fn (WelcomeMail $mail) => $mail->hasTo('ada@example.com'));
});

it('sends the password reset link immediately, in the Écrivain template, with the broker expiry', function () {
    // Mail::fake() does not record a Mailable returned from a notification's
    // toMail(), so the notification is faked and its mail inspected.
    Notification::fake();
    config(['auth.passwords.users.expire' => 45]);
    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use ($user) {
        $mail = $notification->toMail($user);

        return $mail instanceof PasswordResetMail
            && ! $mail instanceof \Illuminate\Contracts\Queue\ShouldQueue
            && ! $notification instanceof \Illuminate\Contracts\Queue\ShouldQueue
            && $mail->hasTo($user->email)
            && $mail->expiresInMinutes === 45
            && str_contains($mail->url, '/reset-password/'.$notification->token)
            && str_contains($mail->render(), 'in 45 minutes');
    });
});

it('uses the custom notification for password resets', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPasswordNotification::class,
        fn ($notification) => $notification->toMail($user) instanceof PasswordResetMail);
});

it('confirms a password changed through a reset link', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertSessionHasNoErrors();

    Mail::assertQueued(PasswordChangedMail::class, fn ($mail) => $mail->hasTo($user->email));
});

it('confirms a password changed from the profile page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->from('/profile')->put('/password', [
        'current_password' => 'password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertSessionHasNoErrors();

    Mail::assertQueued(PasswordChangedMail::class, fn ($mail) => $mail->hasTo($user->email));
});

it('tells the old address when the email changes, and nobody when it does not', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);

    $this->actingAs($user)->patch('/profile', ['name' => $user->name, 'email' => $user->email]);
    Mail::assertNotQueued(EmailChangedMail::class);

    $this->actingAs($user)->patch('/profile', ['name' => $user->name, 'email' => 'new@example.com'])
        ->assertSessionHasNoErrors();

    Mail::assertQueued(EmailChangedMail::class, fn (EmailChangedMail $mail) => $mail->hasTo('old@example.com')
        && ! $mail->hasTo('new@example.com')
        && $mail->newEmail === 'new@example.com');
});

it('confirms account deletion with plain values, after the row is gone', function () {
    $user = User::factory()->create(['email' => 'leaving@example.com', 'name' => 'Leaving Soon']);
    Book::factory()->for($user)->count(2)->create();

    $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertRedirect('/');

    Mail::assertQueued(AccountDeletedMail::class, fn (AccountDeletedMail $mail) => $mail->hasTo('leaving@example.com')
        && $mail->booksDeleted === 2
        && $mail->name === 'Leaving Soon');
    expect(User::find($user->id))->toBeNull();
});

// ── Collaboration ───────────────────────────────────────────────────────

it('emails an invitation, and again on resend', function () {
    $owner = User::factory()->create(['plan' => 'author', 'name' => 'Ada Lovelace']);
    $book = Book::factory()->for($owner)->create(['title' => 'The Salt Road']);

    $this->actingAs($owner)->postJson("/books/{$book->id}/members", [
        'email' => 'reviewer@example.com',
        'role' => 'reviewer',
    ])->assertCreated();

    $member = $book->members()->first();

    Mail::assertQueued(BookInvitationMail::class, fn (BookInvitationMail $mail) => $mail->hasTo('reviewer@example.com')
        && str_contains($mail->acceptUrl(), $member->invite_token));

    $this->actingAs($owner)->postJson("/members/{$member->id}/resend")->assertOk();

    Mail::assertQueued(BookInvitationMail::class, 2);
});

it('tells the owner when an invitation is accepted', function () {
    $owner = User::factory()->create(['plan' => 'author', 'email' => 'owner@example.com']);
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
    $book = Book::factory()->for($owner)->create();

    $member = $book->members()->create(['email' => 'invitee@example.com', 'role' => 'editor']);
    $token = $member->issueInvite();

    $this->actingAs($invitee)->post("/invitations/{$token}")->assertRedirect();

    Mail::assertQueued(InvitationAcceptedMail::class, fn ($mail) => $mail->hasTo('owner@example.com'));
});

it('emails thread participants about a reply, but never the person replying', function () {
    [$owner, $reviewer, , $section] = emailBook();

    $this->actingAs($reviewer)->postJson("/sections/{$section->id}/comments", [
        'body' => 'Is this date right?',
        'excerpt' => 'moved inland in 1749',
    ])->assertCreated();

    Mail::assertNothingQueued();

    $thread = $section->comments()->first();

    $this->actingAs($owner)->postJson("/sections/{$section->id}/comments", [
        'body' => 'Yes — checked against the archive.',
        'parent_id' => $thread->id,
    ])->assertCreated();

    Mail::assertQueued(CommentReplyMail::class, 1);
    Mail::assertQueued(CommentReplyMail::class, fn ($mail) => $mail->hasTo($reviewer->email));
    Mail::assertNotQueued(CommentReplyMail::class, fn ($mail) => $mail->hasTo($owner->email));
});

it('emails people @mentioned in a comment, and only people on the book', function () {
    [$owner, $reviewer, , $section] = emailBook();
    $stranger = User::factory()->create(['name' => 'Grace Hopper']);

    $this->actingAs($reviewer)->postJson("/sections/{$section->id}/comments", [
        'body' => '@Ada Lovelace can you check this? And @Grace Hopper too.',
    ])->assertCreated();

    Mail::assertQueued(CommentMentionMail::class, 1);
    Mail::assertQueued(CommentMentionMail::class, fn ($mail) => $mail->hasTo($owner->email));
    Mail::assertNotQueued(CommentMentionMail::class, fn ($mail) => $mail->hasTo($stranger->email));
});

it('sends one email, not two, to somebody both mentioned and in the thread', function () {
    [$owner, $reviewer, , $section] = emailBook();

    $this->actingAs($reviewer)->postJson("/sections/{$section->id}/comments", ['body' => 'First.'])->assertCreated();
    $thread = $section->comments()->first();

    $this->actingAs($owner)->postJson("/sections/{$section->id}/comments", [
        'body' => '@Jonas good catch.',
        'parent_id' => $thread->id,
    ])->assertCreated();

    Mail::assertQueued(CommentMentionMail::class, 1);
    Mail::assertNotQueued(CommentReplyMail::class);
});

it('emails a reviewer when a chapter is assigned to them', function () {
    [$owner, $reviewer, , $section] = emailBook();

    $this->actingAs($owner)->patchJson("/sections/{$section->id}/reviewer", ['reviewer_id' => $reviewer->id])->assertOk();
    Mail::assertQueued(ReviewerAssignedMail::class, fn ($mail) => $mail->hasTo($reviewer->email));

    // Saving the same assignment again is not a new assignment.
    $this->actingAs($owner)->patchJson("/sections/{$section->id}/reviewer", ['reviewer_id' => $reviewer->id])->assertOk();
    Mail::assertQueued(ReviewerAssignedMail::class, 1);
});

it('does not email somebody assigned to a book they cannot open', function () {
    [$owner, , , $section] = emailBook();
    $stranger = User::factory()->create();

    $this->actingAs($owner)->patchJson("/sections/{$section->id}/reviewer", ['reviewer_id' => $stranger->id])->assertOk();

    Mail::assertNotQueued(ReviewerAssignedMail::class);
});

// ── Billing ─────────────────────────────────────────────────────────────

it('warns once a month when a spend crosses the low-credit line', function () {
    $user = User::factory()->create(['plan' => 'author']); // 1,000 a month: the line is 150
    $ledger = app(CreditLedger::class);
    $ledger->grant($user, 200, 'test grant');

    $ledger->spend($user, 'agent_run', 40);   // 160: above the line
    Mail::assertNotQueued(CreditsLowMail::class);

    $ledger->spend($user, 'agent_run', 20);   // 140: crosses it
    Mail::assertQueued(CreditsLowMail::class, fn (CreditsLowMail $mail) => $mail->hasTo($user->email) && $mail->balance === 140);

    $ledger->grant($user, 100, 'top up');      // 240
    $ledger->spend($user, 'agent_run', 100);  // 140: crosses again, same month
    Mail::assertQueued(CreditsLowMail::class, 1);
});

// ── Weekly digest ───────────────────────────────────────────────────────

it('sends the weekly digest to people who wrote, honouring preferences', function () {
    [$owner, , , $section] = emailBook();
    $optedOut = User::factory()->create(['email_preferences' => ['digest' => false]]);
    $quiet = User::factory()->create();
    Book::factory()->for($quiet)->create();

    $optedOutBook = Book::factory()->for($optedOut)->create();
    $optedOutSection = $optedOutBook->sections()->first()
        ?? $optedOutBook->sections()->create(['type' => 'chapter', 'title' => 'One', 'position' => 10]);

    $lastWeek = now()->subWeek()->startOfWeek()->addDays(2);

    foreach ([[$section, 1200], [$optedOutSection, 300]] as [$s, $words]) {
        DB::table('manuscript_versions')->insert([
            'manuscript_section_id' => $s->id,
            'version_number' => 999,
            'title' => $s->title,
            'word_count' => $words,
            'reason' => 'manual',
            'created_at' => $lastWeek,
        ]);
    }

    $this->artisan('emails:weekly-digest')->assertSuccessful();

    Mail::assertQueued(WeeklyDigestMail::class, 1);
    Mail::assertQueued(WeeklyDigestMail::class, fn (WeeklyDigestMail $mail) => $mail->hasTo($owner->email)
        && $mail->summary['words'] === 1200
        && $mail->summary['active_days'] === 1);
});

it('is not scheduled by default', function () {
    $this->artisan('schedule:list')->doesntExpectOutputToContain('emails:weekly-digest');
});
