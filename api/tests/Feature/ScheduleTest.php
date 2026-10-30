<?php

use App\Mail\Writing\WeeklyDigestMail;
use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/*
 * What runs on its own. The weekly digest ("Weekly progress" in email
 * preferences) was built unscheduled, then sent to everybody on Mondays at
 * 8:00 New York time from 2026-10-28. Since notification frequency it is
 * opt-in and goes through the hourly delivery run, on each person's own
 * day and hour (ADR — Notification frequency, channels, mutes and digests).
 */

it('runs notification delivery hourly, and no longer sends the digest to everybody on Mondays', function () {
    // Through the console kernel, so routes/console.php has been loaded.
    $events = collect(app(Schedule::class)->events());
    $deliver = $events->first(fn ($event) => str_contains((string) $event->command, 'notifications:deliver'));

    expect($deliver)->not->toBeNull()
        ->and($deliver->expression)->toBe('0 * * * *')
        ->and($events->contains(fn ($event) => str_contains((string) $event->command, 'emails:weekly-digest')))->toBeFalse();
});

it('sends the weekly progress digest from the scheduled run only to people who opted in', function () {
    Mail::fake();

    $optedIn = User::factory()->create(['email_preferences' => ['digest' => true], 'timezone' => 'America/New_York']);
    $everybodyElse = User::factory()->create(['timezone' => 'America/New_York']);

    foreach ([$optedIn, $everybodyElse] as $user) {
        $section = Book::factory()->for($user)->create()->sections()
            ->create(['type' => 'chapter', 'title' => 'One', 'position' => ManuscriptSection::POSITION_STEP]);
        DB::table('manuscript_versions')->insert([
            'manuscript_section_id' => $section->id, 'version_number' => 999, 'title' => 'One',
            'word_count' => 500, 'reason' => 'manual', 'created_at' => Carbon::parse('2026-11-11 10:00', 'America/New_York'),
        ]);
    }

    // Monday 8:00 New York: the default day and hour.
    $this->travelTo(Carbon::parse('2026-11-16 08:00', 'America/New_York'));
    $this->artisan('notifications:deliver')->assertSuccessful();

    Mail::assertQueued(WeeklyDigestMail::class, 1);
    Mail::assertQueued(WeeklyDigestMail::class, fn (WeeklyDigestMail $mail) => $mail->hasTo($optedIn->email));
});

it('serves the health check at /up without signing in', function () {
    $this->get('/up')->assertOk();
});
