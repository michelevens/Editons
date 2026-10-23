<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 | Exports a dead worker left "generating" are failed every few minutes, so
 | the Publish screen stops waiting soon after the job timeout. Files that
 | vanished from storage are checked once a day: that asks the bucket about
 | every ready export, which is too slow to do on each poll.
 |
 | Runs wherever `php artisan schedule:work` (or a cron calling
 | `schedule:run` every minute) runs. The Publish screen also settles its own
 | book's stale rows when it loads, so nothing depends on the scheduler
 | alone.
 */
Schedule::command('exports:expire')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('exports:expire --missing')->dailyAt('03:30')->withoutOverlapping();
