<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stripe
    |--------------------------------------------------------------------------
    |
    | Cashier reads its own keys from STRIPE_KEY / STRIPE_SECRET. The price
    | IDs below are the ones this application creates Checkout sessions for;
    | they are environment-specific, so they are never hard-coded.
    |
    */

    'prices' => [
        'author' => env('STRIPE_PRICE_AUTHOR'),
        'studio' => env('STRIPE_PRICE_STUDIO'),
        'credits_500' => env('STRIPE_PRICE_CREDITS_500'),
        'credits_2000' => env('STRIPE_PRICE_CREDITS_2000'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Plans
    |--------------------------------------------------------------------------
    |
    | From HANDOFF.md's PLANS table. Limits are enforced on the SERVER — the
    | prototype gates them in the client, which is a usability hint, not a
    | control. `null` means unlimited.
    |
    | `formats` names what the exporter actually builds (App\Models\BookExport
    | ::FORMATS), not the handoff's aspirational list. It previously carried
    | 'kpf' and 'large_print', which nothing produces, and omitted 'md',
    | which everything does — so markdown was unavailable on every plan,
    | including Studio. A pricing table promising a format that cannot be
    | generated is worse than a shorter one.
    |
    */

    'plans' => [
        'free' => [
            'name' => 'Free',
            'price' => 0,
            'credits' => 100,
            'books' => 1,
            'seats' => 2,
            // Enough to finish and publish a book. The free plan is meant
            // to be a real trial, not a demo with the export disabled.
            'formats' => ['pdf', 'epub'],
            'tier' => 0,
        ],
        'author' => [
            'name' => 'Author',
            'price' => 19,
            'credits' => 1000,
            'books' => 10,
            'seats' => 5,
            'formats' => ['pdf', 'epub', 'docx', 'md'],
            'tier' => 1,
        ],
        'studio' => [
            'name' => 'Studio',
            'price' => 79,
            'credits' => 5000,
            'books' => null,
            'seats' => null,
            'formats' => ['pdf', 'epub', 'docx', 'md', 'html'],
            'tier' => 2,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Credit costs
    |--------------------------------------------------------------------------
    |
    | HANDOFF.md's COST table. These are placeholders until real AI and TTS
    | costs are known — CLAUDE-NEXT.md section 11 lists final pricing as an
    | owner decision, so nothing here should be treated as settled.
    |
    */

    'costs' => [
        'chapter_review' => 10,
        'part_review' => 20,
        'book_review' => 40,
        'agent_run' => 5,
        'specialist_run' => 8,
        'narrate_chapter' => 15,
        'generate_course' => 25,
        'marketing_kit' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Credit packs
    |--------------------------------------------------------------------------
    */

    'packs' => [
        'credits_500' => ['credits' => 500, 'price' => 15],
        'credits_2000' => ['credits' => 2000, 'price' => 50],
    ],

];
