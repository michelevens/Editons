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
        'anthropic' => App\Editorial\Drivers\AnthropicDriver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Anthropic
    |--------------------------------------------------------------------------
    |
    | Used when EDITORIAL_DRIVER=anthropic. The key is read from the
    | environment and never reaches the client: reviews are requested by the
    | server, which is also what keeps the manuscript out of the browser's
    | network log.
    |
    | max_characters caps what one review sends. A 12,000-word chapter would
    | otherwise set the bill by accident; the driver tells the model when it
    | has truncated so a cut-off chapter is not flagged as badly structured.
    |
    */

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 4096),
        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 60),
        'max_characters' => (int) env('ANTHROPIC_MAX_CHARACTERS', 24000),
    ],

];
