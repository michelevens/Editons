<?php

/*
 | The console's growth tools (docs/ADMIN.md, "Business").
 |
 | Config rather than platform settings, like config/platform.php: these bound
 | how fast the console can email the whole user table, which is not a form.
 */
return [

    'attribution' => [
        // The first-touch cookie: where a visitor first arrived from.
        'cookie' => 'ec_first_touch',
        'days' => (int) env('GROWTH_ATTRIBUTION_DAYS', 90),
    ],

    'broadcasts' => [
        // Messages handed to the mailer per minute. Resend's default limit
        // is far above this; the point is not to send a whole list in one
        // burst that a provider reads as spam.
        'per_minute' => max(1, (int) env('GROWTH_BROADCAST_PER_MINUTE', 120)),
    ],

    'lifecycle' => [
        // How long after its trigger a lifecycle email may still go. Without
        // a window, switching "three days with no book" on would email every
        // old account that never started one.
        'window_days' => (int) env('GROWTH_LIFECYCLE_WINDOW_DAYS', 7),
    ],

];
