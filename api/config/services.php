<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Lulu Print API (App\Commerce\Print\LuluClient): print-on-demand for
    | paperbacks sold in the storefront. Keys from developers.lulu.com (or
    | developers.sandbox.lulu.com for the sandbox, a separate account). With
    | no keys Lulu is "not connected" and nothing is ever sent.
    */
    // Trimmed: a key pasted into a dashboard with a stray space or newline
    // is refused by Lulu's token endpoint as an invalid client.
    'lulu' => [
        'client_key' => trim((string) env('LULU_CLIENT_KEY')),
        'client_secret' => trim((string) env('LULU_CLIENT_SECRET')),
        // sandbox | production. Sandbox jobs are never printed.
        'env' => trim((string) env('LULU_ENV', 'sandbox')),
        'urls' => [
            'sandbox' => 'https://api.sandbox.lulu.com',
            'production' => 'https://api.lulu.com',
        ],
        // Lulu's "contact_email" on each print job: whoever answers questions
        // about a job (the owner), never the buyer.
        'contact_email' => trim((string) env('LULU_CONTACT_EMAIL')) ?: null,
        // MAIL, PRIORITY_MAIL, GROUND, EXPEDITED or EXPRESS.
        'shipping_level' => env('LULU_SHIPPING_LEVEL', 'MAIL'),
        // Where the one-copy cost quote is priced to (Lulu needs an address
        // for tax and shipping): the example address of Lulu's own API
        // reference. Only the line item and fulfilment cost are kept.
        'quote_address' => [
            'street1' => '101 Independence Ave SE',
            'city' => 'Washington',
            'state_code' => 'DC',
            'postcode' => '20540',
            'country_code' => 'US',
            'phone_number' => '+1 206 555 0100',
        ],
    ],

];
