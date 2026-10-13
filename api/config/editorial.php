<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Editorial driver
    |--------------------------------------------------------------------------
    |
    | Which carrier answers editorial reviews. Carriers change — this project
    | already uses Anthropic, Google Vertex and OpenAI in different places —
    | so the provider is a deployment decision set by EDITORIAL_DRIVER, not an
    | architectural one. Nothing above App\Editorial\Contracts\EditorialDriver
    | knows which is in use.
    |
    | 'fake' runs real heuristics locally with no API and no spend. It is the
    | default so a fresh clone and the whole test suite work without
    | credentials and without billing anyone.
    |
    */

    'driver' => env('EDITORIAL_DRIVER', 'fake'),

    /*
    |--------------------------------------------------------------------------
    | Carriers
    |--------------------------------------------------------------------------
    |
    | Each entry names its own class and carries its own options, which are
    | passed to the constructor by name. 'requires' lists the keys that must
    | be present; a driver selected without them fails at boot rather than
    | falling back to heuristics while claiming to be an AI.
    |
    | Adding a carrier is a class implementing EditorialDriver plus a block
    | here. No shared code changes — see App\Editorial\DriverFactory.
    |
    */

    'drivers' => [

        'fake' => [
            'class' => App\Editorial\Drivers\FakeDriver::class,
        ],

        'anthropic' => [
            'class' => App\Editorial\Drivers\AnthropicDriver::class,
            'requires' => ['apiKey'],

            'apiKey' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
            'maxTokens' => (int) env('ANTHROPIC_MAX_TOKENS', 4096),
            'timeout' => (int) env('ANTHROPIC_TIMEOUT', 60),

            // Caps what one review sends. A 12,000-word chapter would
            // otherwise set the bill by accident; the driver tells the model
            // when it has truncated, so a cut-off chapter is not flagged as
            // badly structured.
            'maxCharacters' => (int) env('ANTHROPIC_MAX_CHARACTERS', 24000),
        ],

        /*
         | Not implemented yet. Listed so the shape of adding a carrier is
         | visible, and so selecting one fails with a clear message rather
         | than looking like a typo.
         |
         | 'vertex' => [
         |     'class' => App\Editorial\Drivers\VertexDriver::class,
         |     'requires' => ['project', 'credentials'],
         |     'project' => env('GOOGLE_CLOUD_PROJECT'),
         |     'location' => env('GOOGLE_CLOUD_LOCATION', 'us-central1'),
         |     'credentials' => env('GOOGLE_APPLICATION_CREDENTIALS'),
         |     'model' => env('VERTEX_MODEL', 'gemini-2.5-pro'),
         | ],
         |
         | 'openai' => [
         |     'class' => App\Editorial\Drivers\OpenAiDriver::class,
         |     'requires' => ['apiKey'],
         |     'apiKey' => env('OPENAI_API_KEY'),
         |     'model' => env('OPENAI_MODEL', 'gpt-5'),
         | ],
         */

    ],

];
