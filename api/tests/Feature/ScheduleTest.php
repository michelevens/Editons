<?php

use Illuminate\Console\Scheduling\Schedule;

/*
 * What runs on its own. The weekly digest ("Weekly progress" in email
 * preferences) is sent on Mondays at 8:00 New York time — the owner switched
 * it on on 2026-10-28; before that it was built but deliberately unscheduled.
 */

it('schedules the weekly digest on Mondays at 8:00 New York time', function () {
    // Through the console kernel, so routes/console.php has been loaded.
    $digest = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'emails:weekly-digest'));

    expect($digest)->not->toBeNull()
        ->and($digest->expression)->toBe('0 8 * * 1')
        ->and($digest->timezone)->toBe('America/New_York');
});

it('serves the health check at /up without signing in', function () {
    $this->get('/up')->assertOk();
});
