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

    // Invitations to join, sent from the console (ADR — author invitations).
    'invitations' => [
        // How long a link works unless the sender chooses otherwise, and the
        // longest they may choose.
        'default_days' => 30,
        'max_days' => 90,
        // Rows in one bulk send. Larger lists are several batches: each is
        // previewed, and a typo in a 5,000-row file should not go out at once.
        'batch_max' => (int) env('GROWTH_INVITATION_BATCH_MAX', 200),
        // How many times one invitation can be sent again, and how long after
        // the last send. A resend is a reminder, not a drip campaign.
        'max_resends' => 3,
        'resend_after_minutes' => 60,
        // Invitations one staff member may send (resends included) in 24
        // hours. Support's is lower: their invitations carry no plan.
        'daily_limit' => [
            'owner' => (int) env('GROWTH_INVITATIONS_OWNER_DAILY', 1000),
            'support' => (int) env('GROWTH_INVITATIONS_SUPPORT_DAILY', 100),
        ],
        // The longest complimentary plan an invitation can carry, in months
        // (within platform.complimentary_max_days).
        'max_plan_months' => 24,
        // The email says "the Écrivain team" invited them. A staff member's
        // own name goes out only when this is switched on for the deployment.
        'show_inviter_name' => (bool) env('GROWTH_INVITATION_SHOW_INVITER', false),
    ],

];
