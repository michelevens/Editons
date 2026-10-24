<?php

/*
|--------------------------------------------------------------------------
| Écrivain Partners (the affiliate program)
|--------------------------------------------------------------------------
|
| Anybody with an account can apply to sell books on commission from a
| branded page of their own (/p/{handle}). See App\Commerce\Partners and
| docs/PARTNERS.md.
|
| The money settings below are DEFAULTS: the owner can change them from the
| console (Business → Partners → Settings), which stores them in
| platform_settings. A commission row copies the rate it was earned at, so
| changing a setting never rewrites history.
|
| The module is the `partners` feature flag (App\Platform\Features), which
| also needs direct sales (App\Commerce\Commerce). It is off by default in
| production — `PARTNERS_DEFAULT_ON=true` or the console turns it on once
| commerce is live — and on elsewhere, so it works end to end locally
| against the fake gateway.
|
*/

return [

    'default_on' => filter_var(env('PARTNERS_DEFAULT_ON', env('APP_ENV') !== 'production'), FILTER_VALIDATE_BOOL),

    // Percent of the item's net (after discount and the payment fee). Out of
    // the seller's royalty; the platform's share is unchanged.
    'default_rate' => (int) env('PARTNERS_DEFAULT_RATE', 25),
    'min_rate' => (int) env('PARTNERS_MIN_RATE', 10),
    'max_rate' => (int) env('PARTNERS_MAX_RATE', 50),

    // Days a commission waits before it can be paid (refund window).
    'hold_days' => (int) env('PARTNERS_HOLD_DAYS', 30),

    // How long a partner's link keeps the credit. Last click wins.
    'cookie' => 'ec_partner',
    'cookie_days' => (int) env('PARTNERS_COOKIE_DAYS', 30),

    // The smallest balance a payout is recorded for, in each currency.
    'payout_minimum_cents' => (int) env('PARTNERS_PAYOUT_MINIMUM_CENTS', 2500),

    // The Partner Agreement every partner accepts, by version. DRAFT: needs
    // legal review before launch (docs/PARTNERS.md).
    'agreement_version' => '1-draft',

    // A partner may change their handle this often; the old one redirects.
    'handle_change_days' => 30,

    // Books on one partner's page.
    'shelf_max' => 24,

    // An author's invitation to partner for their books.
    'invitation_days' => 30,

    // Handles nobody may take: routes, brand, roles, and words that would
    // let a page pass for Écrivain's own.
    'reserved_handles' => [
        'admin', 'administrator', 'api', 'app', 'apply', 'assets', 'billing', 'blog', 'build',
        'checkout', 'dashboard', 'dev', 'docs', 'ecrivain', 'ecrivain-ai', 'edit', 'editons',
        'email', 'help', 'home', 'info', 'login', 'logout', 'mail', 'moderator', 'new', 'null',
        'official', 'owner', 'p', 'partner', 'partners', 'payouts', 'press', 'qr', 'register',
        'root', 'sales', 'security', 'settings', 'shop', 'staff', 'static', 'status', 'store',
        'support', 'system', 'team', 'test', 'undefined', 'www',
    ],

];
