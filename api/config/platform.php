<?php

/*
 | The platform owner's console (docs/ADMIN.md).
 |
 | Limits here are deliberately config rather than platform settings: they
 | bound what a compromised admin account can do, so changing them is a
 | deploy, not a form an admin can submit.
 */
return [

    // What the console calls the people who run it, in the target's sign-in
    // history and in the banner: "Viewed by Ecrivain support".
    'staff_name' => env('PLATFORM_STAFF_NAME', 'Ecrivain support'),

    'impersonation' => [
        // Hard limit on a "View as" session, in minutes. Clamped to 30–60:
        // shorter is unusable for a real support call, longer is a session
        // nobody remembers they left open. Never extended.
        'minutes' => max(30, min(60, (int) env('PLATFORM_IMPERSONATION_MINUTES', 30))),
    ],

    'credits' => [
        // The largest single adjustment each role may make. Support may only
        // add (a goodwill top-up after a failed review); taking credits away
        // is the owner's call.
        'support_max_grant' => (int) env('PLATFORM_SUPPORT_MAX_GRANT', 500),
        'owner_max_adjustment' => (int) env('PLATFORM_OWNER_MAX_ADJUSTMENT', 100000),
    ],

    // The longest a complimentary plan may be given for, in days. Open-ended
    // is allowed (no end date), but a date beyond this is a typo.
    'complimentary_max_days' => 730,

    /*
     | AI usage and cost (docs/ADMIN.md, "AI usage").
     |
     | Prices are what the provider charges per million tokens (or per
     | image), used only to estimate what a call cost. A model that is not
     | listed records a null cost rather than a guess; the console counts
     | those calls separately so an unpriced model is visible, not free.
     |
     | Keys are model-id prefixes, most specific first: 'claude-opus-5'
     | would otherwise match claude-opus-5-5 and record Opus 5's rate.
     */
    'ai' => [
        'pricing' => [
            // Source: Anthropic's published API prices (anthropic.com/pricing,
            // first-party rates), as carried by the claude-api reference
            // dated 2026-10-06; checked 2026-10-24. USD per million tokens.
            'anthropic' => [
                'claude-fable-5-1' => ['in' => 10.0, 'out' => 50.0],
                'claude-fable-5' => ['in' => 10.0, 'out' => 50.0],
                'claude-opus-5-5' => ['in' => 4.0, 'out' => 20.0],
                'claude-opus-5' => ['in' => 5.0, 'out' => 25.0],
                'claude-opus-4-8' => ['in' => 5.0, 'out' => 25.0],
                'claude-sonnet-5-5' => ['in' => 2.0, 'out' => 10.0],
                'claude-sonnet-5' => ['in' => 2.0, 'out' => 10.0],
                'claude-sonnet-4-6' => ['in' => 3.0, 'out' => 15.0],
                // Up to 100K input tokens; Écrivain never sends more.
                'claude-haiku-5-5' => ['in' => 0.10, 'out' => 0.50],
                'claude-haiku-4-5' => ['in' => 1.0, 'out' => 5.0],
            ],

            // OpenAI image generation is priced per image by size and
            // quality. No verified current price was available when this was
            // written, so nothing is listed and artwork records a null cost.
            // Add 'model' => ['image' => <USD per image>] with its source
            // and date once checked against openai.com/api/pricing.
            'openai' => [],
        ],

        // A monthly cap applied to accounts without one of their own, in
        // USD. Null: none. The per-account cap is set in the console.
        'default_monthly_cap_usd' => env('PLATFORM_AI_DEFAULT_MONTHLY_CAP_USD') !== null
            ? (float) env('PLATFORM_AI_DEFAULT_MONTHLY_CAP_USD')
            : null,

        // Who drafts support replies when staff ask for one. Follows the
        // editorial driver, like cover concepts: 'fake' needs no key.
        'support_draft_driver' => env('SUPPORT_DRAFT_DRIVER', env('EDITORIAL_DRIVER', 'fake') === 'anthropic' ? 'anthropic' : 'fake'),
        'support_draft_model' => env('SUPPORT_DRAFT_MODEL', 'claude-sonnet-5-5'),
    ],

    'ops' => [
        // How long after its due time a scheduled command may stay silent
        // before it counts as late.
        'late_after_minutes' => (int) env('PLATFORM_JOB_LATE_MINUTES', 10),
        // A worker that has not looped for this long is not alive.
        'worker_silent_minutes' => (int) env('PLATFORM_WORKER_SILENT_MINUTES', 3),
    ],

    'status' => [
        // The API service's base URL, for the status page to ask its /up.
        // Unset: the API shows "unknown" (it is a separate service).
        'api_url' => env('PLATFORM_STATUS_API_URL'),
    ],

    'errors' => [
        // Browser error reports: per person (or IP) per minute, and the
        // largest body accepted.
        'client_per_minute' => 10,
        'client_max_bytes' => 16384,
    ],
];
