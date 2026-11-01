<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI writer (ADR — AI writes the book)
    |--------------------------------------------------------------------------
    |
    | Who writes a book's plan and its chapters. Follows EDITORIAL_DRIVER, so
    | a deployment running real reviews also runs real drafting, unless
    | WRITER_DRIVER says otherwise. 'fake' writes deterministic, clearly
    | labelled sample text: no API, no spend. It is the default, so a fresh
    | clone and the whole test suite never call a provider.
    |
    */

    'driver' => env('WRITER_DRIVER', env('EDITORIAL_DRIVER', 'fake') === 'anthropic' ? 'anthropic' : 'fake'),

    'drivers' => [

        'fake' => [
            'class' => App\Ai\Writers\FakeWriter::class,
        ],

        'anthropic' => [
            'class' => App\Ai\Writers\AnthropicWriter::class,
            'requires' => ['apiKey'],

            'apiKey' => env('ANTHROPIC_API_KEY'),
            // Standard quality: the editorial model. Best quality: the
            // thorough one the reviews use.
            'model' => env('WRITER_MODEL', env('ANTHROPIC_MODEL', 'claude-sonnet-5-5')),
            'bestModel' => env('WRITER_MODEL_BEST', env('ANTHROPIC_MODEL_THOROUGH') ?: 'claude-opus-5-5'),
            // One call's answer, thinking included. A chapter part is at
            // most words_per_call words (about 3,500 tokens), so this leaves
            // room for adaptive thinking without truncating the prose.
            'maxTokens' => (int) env('WRITER_MAX_TOKENS', 16000),
            // Non-streaming with a long timeout: the call runs on the worker,
            // never in a web request.
            'timeout' => (int) env('WRITER_TIMEOUT', 600),
            'effort' => env('WRITER_EFFORT', 'medium'),
            // Server-side fallback on a classifier decline (Claude API).
            'fallbacks' => (bool) env('WRITER_FALLBACKS', true),
        ],

    ],

    /*
    | What a credit buys, in provider cost. Credits are charged from the
    | tokens each call really used, priced from config/platform.php's table
    | (cache reads and writes included), divided by this and rounded up, at
    | least one credit per call. 0.01: a credit is a cent of provider cost;
    | packs sell credits at 2.5 to 3 cents.
    */
    'usd_per_credit' => (float) env('WRITER_USD_PER_CREDIT', 0.01),

    /*
    | Sample text from the fake writer is never charged in use, like the
    | fake review driver. Tests turn this on to exercise the ledger.
    */
    'charge_simulated' => false,

    // A chapter longer than this is written in sequential parts.
    'words_per_call' => (int) env('WRITER_WORDS_PER_CALL', 2500),

    // How many chapters a Free account may draft, in all: a taste.
    'free_chapters' => 1,

    // Ceiling on one plan's chapters and on its target words per chapter.
    'max_chapters' => 40,
    'max_chapter_words' => 8000,

];
