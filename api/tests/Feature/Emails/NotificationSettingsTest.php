<?php

use App\Inbox\Inbox;
use App\Mail\Account\ActivityDigestMail;
use App\Mail\Collaboration\ReviewerAssignedMail;
use App\Mail\EmailPreferences;
use App\Mail\HeldEmails;
use App\Mail\NotificationSettings;
use App\Mail\Notify;
use App\Mail\Writing\WeeklyDigestMail;
use App\Models\Book;
use App\Models\EmailDigest;
use App\Models\HeldEmail;
use App\Models\InboxItem;
use App\Models\ManuscriptSection;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/*
 * Notification frequency, channels, per-book mute, quiet hours and the
 * opt-in weekly progress summary (ADR — Notification frequency). The mailer
 * is faked; "sent" means handed to the mailer.
 */

beforeEach(fn () => Mail::fake());

/** An owner, a reviewer on their book, and a chapter. */
function notifyBook(array $reviewerAttributes = []): array
{
    $owner = User::factory()->create(['name' => 'Ada Lovelace']);
    $reviewer = User::factory()->create($reviewerAttributes + ['name' => 'Jonas Pierre', 'timezone' => 'America/New_York']);
    $book = Book::factory()->for($owner)->create(['title' => 'The Salt Road']);
    $section = $book->sections()->create(['type' => 'chapter', 'title' => 'The Market', 'position' => ManuscriptSection::POSITION_STEP]);
    $book->members()->create(['email' => $reviewer->email, 'user_id' => $reviewer->id, 'role' => 'reviewer', 'status' => 'active']);

    return [$owner, $reviewer->fresh(), $book, $section];
}

/** A collaboration email to the reviewer, about the book. */
function assignReviewer(ManuscriptSection $section, User $reviewer, User $owner): bool
{
    return Notify::reviewerAssigned($section, $reviewer->fresh(), $owner);
}

// ── Defaults ────────────────────────────────────────────────────────────

it('keeps today\'s behaviour for somebody who changes nothing', function () {
    [$owner, $reviewer, $book, $section] = notifyBook();

    expect(assignReviewer($section, $reviewer, $owner))->toBeTrue();
    Inbox::reviewerAssigned($section, $reviewer, $owner);

    Mail::assertQueued(ReviewerAssignedMail::class, 1);
    expect(HeldEmail::count())->toBe(0)
        ->and(InboxItem::where('user_id', $reviewer->id)->whereNull('read_at')->count())->toBe(1);

    $described = NotificationSettings::describe($reviewer);
    expect($described['frequency'])->toBe(['collaboration' => 'instant', 'writing' => 'instant', 'publishing' => 'instant', 'sales' => 'instant', 'partners' => 'instant'])
        ->and(array_unique(array_values($described['inbox'])))->toBe([true])
        ->and($described['quiet_hours'])->toBeNull()
        ->and($described['muted_books'])->toBe([])
        ->and([$described['digest_day'], $described['digest_hour']])->toBe([1, 8])
        ->and($reviewer->notification_settings)->toBeNull();
});

it('makes the weekly progress summary opt-in, for new and existing accounts', function () {
    expect(EmailPreferences::allows(User::factory()->create(), 'digest'))->toBeFalse()
        // Set aside by the migration: an untouched default-on switch saved by the old form.
        ->and(EmailPreferences::allows(User::factory()->create(['email_preferences' => ['digest_legacy' => true]]), 'digest'))->toBeFalse()
        ->and(EmailPreferences::allows(User::factory()->create(['email_preferences' => ['digest' => true]]), 'digest'))->toBeTrue();
});

it('sets a stored digest:true aside in the migration, and puts it back on rollback', function () {
    $migration = require database_path('migrations/2026_11_09_110000_create_notification_delivery.php');
    $on = User::factory()->create(['email_preferences' => ['digest' => true, 'sales' => false]]);
    $off = User::factory()->create(['email_preferences' => ['digest' => false]]);

    $migration->down();
    $migration->up();

    expect($on->fresh()->email_preferences)->toBe(['sales' => false, 'digest_legacy' => true])
        ->and($off->fresh()->email_preferences)->toBe(['digest' => false]);
});

// ── Preferences ─────────────────────────────────────────────────────────

it('saves frequency, inbox, quiet hours and the digest schedule from the profile', function () {
    [, $reviewer] = notifyBook();

    $this->actingAs($reviewer)->patch(route('profile.email-preferences'), [
        'frequency' => ['collaboration' => 'daily', 'sales' => 'off', 'publishing' => 'instant', 'account' => 'off', 'bogus' => 'weekly'],
        'inbox' => ['publishing' => false, 'bogus' => false],
        'quiet_hours' => ['start' => 22, 'end' => 7],
        'digest_day' => 5,
        'digest_hour' => 18,
        'preferences' => ['digest' => true],
    ])->assertRedirect(route('profile.edit'));

    $reviewer->refresh();

    expect(NotificationSettings::frequency($reviewer, 'collaboration'))->toBe('daily')
        ->and(NotificationSettings::frequency($reviewer, 'sales'))->toBe('off')
        ->and(EmailPreferences::allows($reviewer, 'sales'))->toBeFalse()
        // Mandatory and unknown categories are not stored.
        ->and(EmailPreferences::allows($reviewer, 'account'))->toBeTrue()
        ->and($reviewer->notification_settings['frequency'])->toBe(['collaboration' => 'daily'])
        ->and($reviewer->notification_settings['inbox'])->toBe(['publishing' => false])
        ->and(NotificationSettings::quietHours($reviewer))->toBe(['start' => 22, 'end' => 7])
        ->and([NotificationSettings::digestDay($reviewer), NotificationSettings::digestHour($reviewer)])->toBe([5, 18])
        ->and(EmailPreferences::allows($reviewer, 'digest'))->toBeTrue();

    // Back to instant switches the category on again and stores nothing for it.
    $this->actingAs($reviewer)->patch(route('profile.email-preferences'), [
        'frequency' => ['sales' => 'instant', 'collaboration' => 'instant'],
        'quiet_hours' => null,
    ]);

    expect(EmailPreferences::allows($reviewer->fresh(), 'sales'))->toBeTrue()
        ->and($reviewer->fresh()->notification_settings)->toBe(['inbox' => ['publishing' => false], 'digest_day' => 5, 'digest_hour' => 18]);
});

it('refuses settings that make no sense', function (array $payload, string $error) {
    [, $reviewer] = notifyBook();

    $this->actingAs($reviewer)->patch(route('profile.email-preferences'), $payload)->assertSessionHasErrors($error);
})->with([
    'unknown frequency' => [['frequency' => ['collaboration' => 'hourly']], 'frequency.collaboration'],
    'inbox not a boolean' => [['inbox' => ['writing' => 'maybe']], 'inbox.writing'],
    'quiet hours that end when they start' => [['quiet_hours' => ['start' => 7, 'end' => 7]], 'quiet_hours.end'],
    'an hour past midnight' => [['digest_hour' => 24], 'digest_hour'],
    'a day of the week that is not one' => [['digest_day' => 0], 'digest_day'],
    'nothing at all' => [[], 'preferences'],
]);

it('maps the unsubscribe link onto the same switch as the settings', function () {
    $user = User::factory()->create(['email_preferences' => ['digest' => true]]);

    $this->post(URL::signedRoute('email.unsubscribe.one-click', ['user' => $user->id, 'category' => 'digest']))->assertNoContent();

    expect(EmailPreferences::allows($user->fresh(), 'digest'))->toBeFalse()
        ->and(collect(EmailPreferences::describe($user->fresh()))->firstWhere('key', 'digest')['enabled'])->toBeFalse();

    // An activity category switched off elsewhere reads as "off" here.
    $other = User::factory()->create(['email_preferences' => ['sales' => false]]);
    expect(NotificationSettings::frequency($other, 'sales'))->toBe('off');
});

// ── Channels ────────────────────────────────────────────────────────────

it('sends no email when the frequency is off, and still records the inbox item', function () {
    [$owner, $reviewer, , $section] = notifyBook(['email_preferences' => ['collaboration' => false]]);

    expect(assignReviewer($section, $reviewer, $owner))->toBeFalse();
    Inbox::reviewerAssigned($section, $reviewer, $owner);

    Mail::assertNothingQueued();
    expect(InboxItem::where('user_id', $reviewer->id)->count())->toBe(1)
        ->and(HeldEmail::count())->toBe(0);
});

it('keeps the email but skips the inbox when the inbox channel is off', function () {
    [$owner, $reviewer, , $section] = notifyBook(['notification_settings' => ['inbox' => ['collaboration' => false]]]);

    assignReviewer($section, $reviewer, $owner);
    Inbox::reviewerAssigned($section, $reviewer, $owner);

    Mail::assertQueued(ReviewerAssignedMail::class, 1);
    expect(InboxItem::where('user_id', $reviewer->id)->count())->toBe(0);

    // Kinds outside the switchable categories always arrive.
    expect(NotificationSettings::inboxAllows($reviewer, 'moderation'))->toBeTrue();
});

// ── Mute ────────────────────────────────────────────────────────────────

it('mutes one book from its card: no email, inbox items arrive read, other books unaffected', function () {
    [$owner, $reviewer, $book, $section] = notifyBook();
    $other = Book::factory()->for($owner)->create();
    $otherSection = $other->sections()->create(['type' => 'chapter', 'title' => 'Elsewhere', 'position' => ManuscriptSection::POSITION_STEP]);
    $other->members()->create(['email' => $reviewer->email, 'user_id' => $reviewer->id, 'role' => 'reviewer', 'status' => 'active']);

    $this->actingAs($reviewer)->put(route('books.mute', $book), ['muted' => true])->assertRedirect();

    expect(NotificationSettings::mutedBooks($reviewer->fresh()))->toBe([$book->id]);

    expect(assignReviewer($section, $reviewer, $owner))->toBeFalse();
    Inbox::reviewerAssigned($section, $reviewer->fresh(), $owner);

    Mail::assertNothingQueued();
    expect(InboxItem::where('user_id', $reviewer->id)->where('book_id', $book->id)->whereNotNull('read_at')->count())->toBe(1);

    expect(assignReviewer($otherSection, $reviewer, $owner))->toBeTrue();
    Mail::assertQueued(ReviewerAssignedMail::class, 1);

    // Unmuting from the settings list.
    $this->actingAs($reviewer)->put(route('books.mute', $book), ['muted' => false]);
    expect(NotificationSettings::mutedBooks($reviewer->fresh()))->toBe([]);
});

it('does not let somebody mute a book they cannot open', function () {
    [, , $book] = notifyBook();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->put(route('books.mute', $book), ['muted' => true])->assertForbidden();
    expect($stranger->fresh()->notification_settings)->toBeNull();
});

it('never mutes security or billing mail', function () {
    [, $reviewer, $book] = notifyBook();
    NotificationSettings::mute($reviewer, $book);

    expect(Notify::passwordChanged($reviewer->fresh()))->toBeTrue();
});

// ── Quiet hours ─────────────────────────────────────────────────────────

it('holds instant emails during quiet hours and sends them when they end', function () {
    [$owner, $reviewer, , $section] = notifyBook(['notification_settings' => ['quiet_hours' => ['start' => 22, 'end' => 7]]]);

    // 23:30 in New York (03:30 UTC next day).
    $this->travelTo(Carbon::parse('2026-11-10 23:30', 'America/New_York'));

    expect(assignReviewer($section, $reviewer, $owner))->toBeFalse();
    Mail::assertNothingQueued();

    $held = HeldEmail::sole();
    expect($held->mode)->toBe('quiet')
        ->and($held->release_at->equalTo(Carbon::parse('2026-11-11 07:00', 'America/New_York')))->toBeTrue();

    // Security mail is never held.
    expect(Notify::passwordChanged($reviewer))->toBeTrue();

    // 06:00: still quiet.
    $this->travelTo(Carbon::parse('2026-11-11 06:00', 'America/New_York'));
    HeldEmails::deliver();
    Mail::assertNotQueued(ReviewerAssignedMail::class);

    // 07:00: released, once.
    $this->travelTo(Carbon::parse('2026-11-11 07:00', 'America/New_York'));
    expect(HeldEmails::deliver()['released'])->toBe(1)
        ->and(HeldEmails::deliver()['released'])->toBe(0);
    Mail::assertQueued(ReviewerAssignedMail::class, 1);

    // Outside quiet hours an email goes at once.
    $this->travelTo(Carbon::parse('2026-11-11 12:00', 'America/New_York'));
    expect(assignReviewer($section, $reviewer, $owner))->toBeTrue();
});

it('drops a held email whose book was muted before quiet hours ended', function () {
    [$owner, $reviewer, $book, $section] = notifyBook(['notification_settings' => ['quiet_hours' => ['start' => 0, 'end' => 23]]]);
    $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/New_York'));

    assignReviewer($section, $reviewer, $owner);
    NotificationSettings::mute($reviewer->fresh(), $book);

    $this->travelTo(Carbon::parse('2026-11-10 23:00', 'America/New_York'));
    expect(HeldEmails::deliver()['released'])->toBe(0);
    Mail::assertNothingQueued();
    expect(HeldEmail::sole()->sent_at)->not->toBeNull();
});

// ── Digests ─────────────────────────────────────────────────────────────

it('gathers a daily digest and sends it once at the person\'s local hour', function () {
    [$owner, $reviewer, , $section] = notifyBook([
        'locale' => 'fr',
        'notification_settings' => ['frequency' => ['collaboration' => 'daily'], 'digest_hour' => 9],
    ]);

    $this->travelTo(Carbon::parse('2026-11-10 14:00', 'America/New_York'));
    assignReviewer($section, $reviewer, $owner);
    assignReviewer($section, $reviewer, $owner);
    Mail::assertNothingQueued();
    expect(HeldEmail::where('mode', 'daily')->count())->toBe(2);

    // 08:00 local: not yet.
    $this->travelTo(Carbon::parse('2026-11-11 08:59', 'America/New_York'));
    expect(HeldEmails::deliver()['digests'])->toBe(0);

    // 09:00 local (14:00 UTC): one digest, both items, in French.
    $this->travelTo(Carbon::parse('2026-11-11 09:00', 'America/New_York'));
    expect(HeldEmails::deliver()['digests'])->toBe(1)
        ->and(HeldEmails::deliver()['digests'])->toBe(0);

    Mail::assertQueued(ActivityDigestMail::class, 1);
    Mail::assertQueued(ActivityDigestMail::class, fn (ActivityDigestMail $mail) => $mail->hasTo($reviewer->email)
        && count($mail->items) === 2
        && $mail->frequency === 'daily'
        && $mail->locale === 'fr');
    Mail::assertNotQueued(ReviewerAssignedMail::class);

    expect(EmailDigest::sole()->only(['kind', 'period', 'items']))->toBe(['kind' => 'daily', 'period' => '2026-11-11', 'items' => 2])
        ->and(HeldEmail::whereNull('sent_at')->count())->toBe(0);

    // Something after the moment waits for tomorrow's.
    $this->travelTo(Carbon::parse('2026-11-11 10:00', 'America/New_York'));
    assignReviewer($section, $reviewer, $owner);
    HeldEmails::deliver();
    Mail::assertQueued(ActivityDigestMail::class, 1);

    $this->travelTo(Carbon::parse('2026-11-12 09:05', 'America/New_York'));
    HeldEmails::deliver();
    Mail::assertQueued(ActivityDigestMail::class, 2);
});

it('sends a weekly digest on the chosen weekday in the person\'s timezone', function () {
    // Friday 18:00 in Paris.
    [$owner, $reviewer, , $section] = notifyBook([
        'timezone' => 'Europe/Paris',
        'notification_settings' => ['frequency' => ['collaboration' => 'weekly'], 'digest_day' => 5, 'digest_hour' => 18],
    ]);

    $this->travelTo(Carbon::parse('2026-11-09 10:00', 'Europe/Paris'));   // Monday
    assignReviewer($section, $reviewer, $owner);

    // Thursday 18:00 and Friday 17:59 Paris: nothing.
    foreach (['2026-11-12 18:00', '2026-11-13 17:59'] as $at) {
        $this->travelTo(Carbon::parse($at, 'Europe/Paris'));
        HeldEmails::deliver();
    }
    Mail::assertNothingQueued();

    // Friday 18:00 Paris is 12:00 in New York: the server's clock does not matter.
    $this->travelTo(Carbon::parse('2026-11-13 18:00', 'Europe/Paris'));
    HeldEmails::deliver();
    Mail::assertQueued(ActivityDigestMail::class, fn (ActivityDigestMail $mail) => $mail->frequency === 'weekly');
    expect(EmailDigest::sole()->period)->toBe('2026-11-13');
});

it('catches up on a missed run without sending twice', function () {
    [$owner, $reviewer, , $section] = notifyBook(['notification_settings' => ['frequency' => ['collaboration' => 'daily']]]);

    $this->travelTo(Carbon::parse('2026-11-10 12:00', 'America/New_York'));
    assignReviewer($section, $reviewer, $owner);

    // The 08:00 run never happened; the 13:00 one sends it.
    $this->travelTo(Carbon::parse('2026-11-11 13:00', 'America/New_York'));
    HeldEmails::deliver();
    HeldEmails::deliver();

    Mail::assertQueued(ActivityDigestMail::class, 1);
});

it('sends a digest that was claimed but not sent on the next run, once', function () {
    [$owner, $reviewer, , $section] = notifyBook(['notification_settings' => ['frequency' => ['collaboration' => 'daily']]]);

    $this->travelTo(Carbon::parse('2026-11-10 12:00', 'America/New_York'));
    assignReviewer($section, $reviewer, $owner);

    // A run that claimed the items and died before sending.
    $this->travelTo(Carbon::parse('2026-11-11 08:00', 'America/New_York'));
    $digest = EmailDigest::create(['user_id' => $reviewer->id, 'kind' => 'daily', 'period' => '2026-11-11']);
    HeldEmail::query()->update(['email_digest_id' => $digest->id]);

    $this->travelTo(Carbon::parse('2026-11-11 09:00', 'America/New_York'));
    HeldEmails::deliver();
    HeldEmails::deliver();

    Mail::assertQueued(ActivityDigestMail::class, fn (ActivityDigestMail $mail) => count($mail->items) === 1);
    Mail::assertQueued(ActivityDigestMail::class, 1);
    expect($digest->fresh()->sent_at)->not->toBeNull();
});

it('works out digest moments across day, week and timezone boundaries', function () {
    $user = User::factory()->make(['timezone' => 'Pacific/Auckland', 'notification_settings' => ['digest_day' => 7, 'digest_hour' => 23]]);

    // Sunday 23:30 in Auckland is Sunday 10:30 UTC.
    $now = Carbon::parse('2026-11-15 23:30', 'Pacific/Auckland');
    expect(NotificationSettings::lastMoment($user, 'weekly', $now)->equalTo(Carbon::parse('2026-11-15 23:00', 'Pacific/Auckland')))->toBeTrue()
        ->and(NotificationSettings::lastMoment($user, 'daily', $now)->equalTo(Carbon::parse('2026-11-15 23:00', 'Pacific/Auckland')))->toBeTrue();

    // Monday 00:30: still last night's.
    $now = Carbon::parse('2026-11-16 00:30', 'Pacific/Auckland');
    expect(NotificationSettings::lastMoment($user, 'weekly', $now)->equalTo(Carbon::parse('2026-11-15 23:00', 'Pacific/Auckland')))->toBeTrue();

    // Sunday 22:59: the Sunday before.
    $now = Carbon::parse('2026-11-15 22:59', 'Pacific/Auckland');
    expect(NotificationSettings::lastMoment($user, 'weekly', $now)->equalTo(Carbon::parse('2026-11-08 23:00', 'Pacific/Auckland')))->toBeTrue()
        ->and(NotificationSettings::lastMoment($user, 'daily', $now)->equalTo(Carbon::parse('2026-11-14 23:00', 'Pacific/Auckland')))->toBeTrue();
});

// ── Weekly progress ─────────────────────────────────────────────────────

function writeLastWeek(ManuscriptSection $section, int $words, Carbon $at): void
{
    DB::table('manuscript_versions')->insert([
        'manuscript_section_id' => $section->id,
        'version_number' => 999,
        'title' => $section->title,
        'word_count' => $words,
        'reason' => 'manual',
        'created_at' => $at,
    ]);
}

it('sends the weekly progress summary only to people who opted in, on their day and hour, once', function () {
    $optedIn = User::factory()->create(['timezone' => 'America/New_York', 'email_preferences' => ['digest' => true], 'notification_settings' => ['digest_day' => 3, 'digest_hour' => 7]]);
    $default = User::factory()->create(['timezone' => 'America/New_York']);
    $legacy = User::factory()->create(['timezone' => 'America/New_York', 'email_preferences' => ['digest_legacy' => true]]);

    foreach ([$optedIn, $default, $legacy] as $user) {
        $book = Book::factory()->for($user)->create();
        $section = $book->sections()->create(['type' => 'chapter', 'title' => 'One', 'position' => ManuscriptSection::POSITION_STEP]);
        writeLastWeek($section, 900, Carbon::parse('2026-11-12 10:00', 'America/New_York'));
    }

    // Monday 8:00 New York, the old fixed time: nobody.
    $this->travelTo(Carbon::parse('2026-11-16 08:00', 'America/New_York'));
    $this->artisan('notifications:deliver')->assertSuccessful();
    Mail::assertNothingQueued();

    // Wednesday 07:00: the opted-in author, the seven days before.
    $this->travelTo(Carbon::parse('2026-11-18 07:00', 'America/New_York'));
    $this->artisan('notifications:deliver')->assertSuccessful();
    $this->artisan('notifications:deliver')->assertSuccessful();

    Mail::assertQueued(WeeklyDigestMail::class, 1);
    Mail::assertQueued(WeeklyDigestMail::class, fn (WeeklyDigestMail $mail) => $mail->hasTo($optedIn->email)
        && $mail->summary['words'] === 900
        && $mail->periodStart->toDateString() === '2026-11-11'
        && $mail->periodEnd->toDateString() === '2026-11-17');
});

it('does not send a progress summary late when somebody opts in after their day', function () {
    $user = User::factory()->create(['timezone' => 'America/New_York', 'email_preferences' => ['digest' => true]]);
    $book = Book::factory()->for($user)->create();
    $section = $book->sections()->create(['type' => 'chapter', 'title' => 'One', 'position' => ManuscriptSection::POSITION_STEP]);
    writeLastWeek($section, 400, Carbon::parse('2026-11-12 10:00', 'America/New_York'));

    // Thursday: Monday's moment is three days old.
    $this->travelTo(Carbon::parse('2026-11-19 10:00', 'America/New_York'));
    HeldEmails::deliver();
    Mail::assertNothingQueued();
});
