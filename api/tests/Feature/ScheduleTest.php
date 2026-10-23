<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

/*
 * What runs on its own. The weekly digest is written and tested but not
 * wanted yet, so nothing may schedule it by accident.
 */

it('does not schedule the weekly digest', function () {
    // Through the console kernel, so routes/console.php has been loaded.
    Artisan::call('schedule:list');

    expect(Artisan::output())->not->toContain('emails:weekly-digest')
        ->and(collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->command)->implode('
'))
        ->not->toContain('emails:weekly-digest');
});

it('serves the health check at /up without signing in', function () {
    $this->get('/up')->assertOk();
});
