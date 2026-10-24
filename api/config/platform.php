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
];
