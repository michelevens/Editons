<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Editorial driver
    |--------------------------------------------------------------------------
    |
    | Which provider answers editorial reviews. 'fake' runs real heuristics
    | locally with no API and no spend — it is the default so a fresh clone
    | works without credentials.
    |
    | Adding Anthropic is a class implementing EditorialDriver plus an entry
    | below; nothing above the driver seam changes.
    |
    */

    'driver' => env('EDITORIAL_DRIVER', 'fake'),

    'drivers' => [
        'fake' => App\Editorial\Drivers\FakeDriver::class,
    ],

];
